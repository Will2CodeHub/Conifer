<?php
/**
 * AJAX Handler for Traffic Statistics
 * Provides real-time and historical traffic data
 */

require_once '../config.php';
require_once '../includes/LogAnalyzer.php';
require_once '../lib/stats_sites.php';

@set_time_limit(0); // large live logs can take a while to stream

header('Content-Type: application/json');

/**
 * Safe JSON output. The LIVE visitor logs contain page-view URLs / user-agents
 * with non-UTF-8 bytes (e.g. %-mangled or latin1), which make a plain
 * json_encode() return FALSE → an empty 200 body → the page shows
 * "Failed to load statistics". JSON_INVALID_UTF8_SUBSTITUTE swaps bad bytes for
 * U+FFFD instead of failing; PARTIAL_OUTPUT_ON_ERROR is a further backstop.
 */
function ts_json($data) {
    return json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// Require authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo ts_json(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? '';

// DEBUG
error_log("=== TRAFFIC STATS DEBUG ===");
error_log("Action: " . $action);
error_log("GET params: " . print_r($_GET, true));

switch ($action) {
    case 'system_info':
        getSystemInfo();
        break;
    
    case 'get_stats':
        getTrafficStats();
        break;
    
    case 'monthly_summary':
        getMonthlySummary();
        break;
    
    default:
        echo ts_json(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Get system information about bot lists and database updates
 */
function getSystemInfo() {
    $botDataDir = '/home/tenuser/private/bot_data/';
    $botListFiles = [];
    
    // Check for JSON bot list files
    if (is_dir($botDataDir)) {
        $files = glob($botDataDir . '*.json');
        foreach ($files as $file) {
            $filename = basename($file);
            $timestamp = filemtime($file);
            $botListFiles[] = [
                'filename' => $filename,
                'date' => date('Y-m-d H:i:s', $timestamp)
            ];
        }
    }
    
    // Get last database update
    $conn = getDBConnection();
    $result = $conn->query("SELECT MAX(created_at) as last_update FROM ten_traffic_stats");
    $dbLastUpdate = 'No data yet';
    
    if ($result && $row = $result->fetch_assoc()) {
        if ($row['last_update']) {
            $dbLastUpdate = date('Y-m-d H:i:s', strtotime($row['last_update']));
        }
    }
    
    $conn->close();
    
    echo ts_json([
        'success' => true,
        'bot_list_files' => $botListFiles,
        'db_last_update' => $dbLastUpdate
    ]);
}

/**
 * Get traffic statistics for a specific period
 */
function getTrafficStats() {
    $siteKey = $_GET['site'] ?? 'tme';
    $period = $_GET['period'] ?? 'last_7_days';
    
    error_log("getTrafficStats - Site: $siteKey, Period: $period");
    
    // Canonical publication list (single source of truth in lib/stats_sites.php).
    $sites = ten_stats_sites();

    if (!isset($sites[$siteKey])) {
        error_log("Invalid site key: $siteKey");
        echo ts_json(['success' => false, 'message' => 'Invalid site key']);
        return;
    }
    
    $logPath = $sites[$siteKey]['log_path'];
    error_log("Log path: $logPath");
    error_log("File exists: " . (file_exists($logPath) ? 'YES' : 'NO'));
    
    // Calculate time range
    $timeRange = calculateTimeRange($period);
    if (!$timeRange) {
        error_log("Invalid period: $period");
        echo ts_json(['success' => false, 'message' => 'Invalid period']);
        return;
    }
    
    $startTime = $timeRange['start'];
    $endTime = $timeRange['end'];
    $useDatabase = $timeRange['use_database'];
    
    error_log("Use database: " . ($useDatabase ? 'YES' : 'NO'));
    error_log("Start time: " . date('Y-m-d H:i:s', $startTime));
    error_log("End time: " . date('Y-m-d H:i:s', $endTime));
    
    $stats = null;
    $dataSource = '';
    
    if ($useDatabase) {
        // Get stats from database for historical data
        error_log("Getting stats from database");
        $stats = getStatsFromDatabase($siteKey, $startTime, $endTime);
        $dataSource = 'Database (Historical)';
    } else {
        // Live period. The web process (tenuser) can only read its OWN visitor log;
        // other sites' logs live in their user's private dir and aren't readable
        // here. If the live log is missing/unreadable/errors, fall back to the
        // database day-rows so the page still works instead of failing.
        if (file_exists($logPath) && is_readable($logPath)) {
            try {
                $analyzer = new LogAnalyzer($logPath, $siteKey);
                // Live periods are recent, so only scan the last 40 MB of the log —
                // bounds the work so a large/busy site's log can't time out.
                $stats = $analyzer->analyzeTimePeriod($startTime, $endTime, 40 * 1024 * 1024);
                $dataSource = 'Live Log File';
            } catch (Throwable $e) {
                error_log("Live log analysis failed, falling back to DB: " . $e->getMessage());
                $stats = getStatsFromDatabase($siteKey, $startTime, $endTime);
                $dataSource = 'Database (live log unavailable)';
            }
        } else {
            $stats = getStatsFromDatabase($siteKey, $startTime, $endTime);
            $dataSource = 'Database (live log not accessible for this site)';
        }
    }
    
    // Last-31-days daily series for the traffic-over-time chart. This is independent of
    // the selected period (which drives the summary cards and the other charts) — the
    // chart always shows the most recent 31 days of real visits.
    if (is_array($stats)) {
        $stats['daily_series'] = getDailySeries($siteKey, 31);
    }

    echo ts_json([
        'success' => true,
        'site_key' => $siteKey,
        'period' => $period,
        'stats' => $stats,
        'data_source' => $dataSource
    ]);
}

/**
 * Per-day real-visitor counts for the last $days days (ending today), with any missing
 * days filled as zero. Powers the "Traffic — last 31 days" chart on the statistics page.
 */
function getDailySeries($siteKey, $days = 31) {
    $conn = getDBConnection();
    $end   = strtotime('today');
    $start = strtotime('-' . ($days - 1) . ' days', $end);
    $startDate = date('Y-m-d', $start);
    $endDate   = date('Y-m-d', $end);

    $map = [];
    $stmt = $conn->prepare("
        SELECT stat_date, human_visits, total_visits
        FROM ten_traffic_stats
        WHERE site_key = ?
        AND stat_date BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            // Prefer real (human) visits; fall back to total where human isn't recorded.
            $val = $row['human_visits'];
            if ($val === null || $val === '') { $val = $row['total_visits']; }
            $map[$row['stat_date']] = (int) $val;
        }
        $stmt->close();
    }

    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $ts = strtotime("+$i days", $start);
        $d  = date('Y-m-d', $ts);
        $series[] = [
            'date'   => $d,
            'label'  => date('j M', $ts),
            'visits' => $map[$d] ?? 0,
        ];
    }
    return $series;
}

/**
 * Get monthly summary with projections
 */
function getMonthlySummary() {
    $siteKey = $_GET['site'] ?? 'tme';
    $conn = getDBConnection();
    
    // Get last month stats
    $lastMonthStart = strtotime('first day of last month 00:00:00');
    $lastMonthEnd = strtotime('last day of last month 23:59:59');
    $lastMonthName = date('F Y', $lastMonthStart);
    
    $lastMonthStats = getStatsFromDatabase($siteKey, $lastMonthStart, $lastMonthEnd);
    // Total page views for last month = sum of the per-URL page_views breakdown.
    $lastMonthPageViews = 0;
    foreach (($lastMonthStats['page_views'] ?? []) as $c) { $lastMonthPageViews += (int)$c; }

    // REAL-VISITOR figures exclude bots & spam. "Unique visitors" uses the human
    // fingerprint dedup where available, else summed daily human uniques. Page views
    // are already human-only (the collector records them only for non-bot,
    // non-spam requests).
    $lastMonthHumanUnique = getUniqueVisitorsDedup(
        $conn, $siteKey, date('Y-m-d', $lastMonthStart), date('Y-m-d', $lastMonthEnd),
        (int)$lastMonthStats['human_unique'], 'human_unique_hashes', 'human_unique'
    );
    $lastMonthBots = (int)$lastMonthStats['bot_visits'] + (int)$lastMonthStats['spam_visits'];

    // Get current month stats - need to count days with actual data
    $currentMonthStart = strtotime('first day of this month 00:00:00');
    $currentMonthEnd = time();
    $currentMonthName = date('F Y', $currentMonthStart);
    $startDate = date('Y-m-d', $currentMonthStart);
    $endDate = date('Y-m-d', $currentMonthEnd);

    // Page views for the current month so far (human-only breakdown).
    $currentMonthPageViews = getPageViewsTotal($conn, $siteKey, $startDate, $endDate);

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT stat_date) as days_with_data,
               SUM(total_visits) as total_visits,
               SUM(human_visits) as human_visits,
               SUM(human_unique) as human_unique,
               SUM(bot_visits) as bot_visits,
               SUM(spam_visits) as spam_visits
        FROM ten_traffic_stats
        WHERE site_key = ?
        AND stat_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $currentMonthData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $curHumanVisits = (int)($currentMonthData['human_visits'] ?? 0);
    $curBots        = (int)($currentMonthData['bot_visits'] ?? 0) + (int)($currentMonthData['spam_visits'] ?? 0);
    $curTotal       = (int)($currentMonthData['total_visits'] ?? 0);

    $currentHumanUnique = getUniqueVisitorsDedup(
        $conn, $siteKey, $startDate, $endDate,
        (int)($currentMonthData['human_unique'] ?? 0), 'human_unique_hashes', 'human_unique'
    );

    $daysInMonth = date('t');
    $daysWithData = (int)($currentMonthData['days_with_data'] ?? 0);
    // A projection needs a few real days to be meaningful — extrapolating a full
    // month from 1-2 days gives nonsense, so require at least 3 days of data.
    $projectionAvailable = $daysWithData >= 3;

    // Full-month projection = (metric so far / days measured) * days in month.
    $projHumanVisits = $projHumanUnique = $projPageViews = 0;
    if ($projectionAvailable && $daysWithData > 0) {
        $projHumanVisits = ($curHumanVisits        / $daysWithData) * $daysInMonth;
        $projHumanUnique = ($currentHumanUnique    / $daysWithData) * $daysInMonth;
        $projPageViews   = ($currentMonthPageViews / $daysWithData) * $daysInMonth;
    }

    $conn->close();

    echo ts_json([
        'success' => true,
        'last_month' => [
            'month_name' => $lastMonthName,
            'human_visits' => (int)$lastMonthStats['human_visits'],
            'unique_visitors' => $lastMonthHumanUnique,   // human, bot/spam excluded
            'page_views' => $lastMonthPageViews,
            'total_visits' => (int)$lastMonthStats['total_visits'],
            'bots_filtered' => $lastMonthBots,
        ],
        'current_month' => [
            'month_name' => $currentMonthName,
            'days_in_month' => $daysInMonth,
            'days_elapsed' => $daysWithData,
            'human_visits' => $curHumanVisits,
            'unique_visitors' => $currentHumanUnique,     // human, bot/spam excluded
            'page_views' => $currentMonthPageViews,
            'total_visits' => $curTotal,
            'bots_filtered' => $curBots,
            'projection_available' => $projectionAvailable,
            'projected_human_visits' => $projHumanVisits,
            'projected_unique' => $projHumanUnique,
            'projected_page_views' => $projPageViews,
        ]
    ]);
}

/**
 * Month-level UNIQUE VISITORS with true dedup.
 *
 * Daily rows store each day's distinct-visitor count, so SUMming them over a month
 * double-counts anyone who returns on more than one day. Instead we UNION the
 * per-day visitor fingerprints (crc32 of each visitor id, written by the collector)
 * and count the union — a person seen on 10 days counts once.
 *
 * Falls back to $fallbackSum (the summed daily uniques) whenever the range isn't
 * fully fingerprinted: the column doesn't exist yet, or any day that has traffic is
 * missing its fingerprints (i.e. predates fingerprint collection). This keeps a
 * transition month from silently under-reporting.
 */
function getUniqueVisitorsDedup($conn, $siteKey, $startDate, $endDate, $fallbackSum, $hashCol = 'visitor_hashes', $countCol = 'unique_visitors') {
    static $colOk = [];
    if (!isset($colOk[$hashCol])) {
        $colOk[$hashCol] = false;
        $r = @$conn->query("SHOW COLUMNS FROM ten_traffic_stats LIKE '" . $conn->real_escape_string($hashCol) . "'");
        if ($r) { $colOk[$hashCol] = $r->num_rows > 0; $r->free(); }
    }
    if (!$colOk[$hashCol]) return (int)$fallbackSum;

    // $hashCol/$countCol are internal constants (not user input) but escape anyway.
    $hc = '`' . str_replace('`', '', $hashCol) . '`';
    $cc = '`' . str_replace('`', '', $countCol) . '`';
    $stmt = $conn->prepare("
        SELECT $cc AS cnt, $hc AS hashes
        FROM ten_traffic_stats
        WHERE site_key = ?
        AND stat_date BETWEEN ? AND ?
    ");
    if (!$stmt) return (int)$fallbackSum;
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $union = [];
    $daysWithTraffic = 0;
    $daysFingerprinted = 0;
    while ($row = $result->fetch_assoc()) {
        $uv = (int)$row['cnt'];
        $arr = ($row['hashes'] !== null && $row['hashes'] !== '')
            ? json_decode($row['hashes'], true)
            : null;
        if ($uv > 0) {
            $daysWithTraffic++;
            if (is_array($arr)) $daysFingerprinted++;
        }
        if (is_array($arr)) {
            foreach ($arr as $h) { $union[$h] = true; }
        }
    }
    $stmt->close();

    // Only trust the dedup when every day that had traffic was fingerprinted.
    if ($daysWithTraffic > 0 && $daysFingerprinted >= $daysWithTraffic) {
        return count($union);
    }
    return (int)$fallbackSum;
}

/**
 * Sum the total page views (across all URLs) for a site over a date range.
 * Reads the per-day page_views JSON breakdown and adds up every count.
 */
function getPageViewsTotal($conn, $siteKey, $startDate, $endDate) {
    $stmt = $conn->prepare("
        SELECT page_views
        FROM ten_traffic_stats
        WHERE site_key = ?
        AND stat_date BETWEEN ? AND ?
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $pages = json_decode($row['page_views'], true);
        if (is_array($pages)) {
            foreach ($pages as $c) { $total += (int)$c; }
        }
    }
    $stmt->close();
    return $total;
}

/**
 * Calculate time range for different periods
 */
function calculateTimeRange($period) {
    $now = time();
    $useDatabase = false;
    
    switch ($period) {
        case 'last_hour':
            $start = $now - 3600;
            $end = $now;
            break;
        
        case 'last_2_hours':
            $start = $now - (3600 * 2);
            $end = $now;
            break;
        
        case 'last_4_hours':
            $start = $now - (3600 * 4);
            $end = $now;
            break;
        
        case 'last_12_hours':
            $start = $now - (3600 * 12);
            $end = $now;
            break;
        
        case 'last_24_hours':
            $start = $now - (3600 * 24);
            $end = $now;
            break;
        
        case 'today':
            $start = strtotime('today 00:00:00');
            $end = $now;
            break;
        
        case 'yesterday':
            $start = strtotime('yesterday 00:00:00');
            $end = strtotime('yesterday 23:59:59');
            $useDatabase = true;
            break;
        
        case 'last_7_days':
            $start = strtotime('-7 days 00:00:00');
            $end = $now;
            $useDatabase = true;
            break;
        
        case 'last_30_days':
            $start = strtotime('-30 days 00:00:00');
            $end = $now;
            $useDatabase = true;
            break;
        
        case 'this_month':
            $start = strtotime('first day of this month 00:00:00');
            $end = $now;
            $useDatabase = true;
            break;
        
        case 'last_month':
            $start = strtotime('first day of last month 00:00:00');
            $end = strtotime('last day of last month 23:59:59');
            $useDatabase = true;
            break;
        
        default:
            return null;
    }
    
    return [
        'start' => $start,
        'end' => $end,
        'use_database' => $useDatabase
    ];
}

/**
 * Get aggregated stats from database for a time range
 */
function getStatsFromDatabase($siteKey, $startTime, $endTime) {
    $conn = getDBConnection();
    
    $startDate = date('Y-m-d', $startTime);
    $endDate = date('Y-m-d', $endTime);
    
    $stmt = $conn->prepare("
        SELECT 
            SUM(total_visits) as total_visits,
            SUM(unique_visitors) as unique_visitors,
            SUM(human_visits) as human_visits,
            SUM(human_unique) as human_unique,
            SUM(bot_visits) as bot_visits,
            SUM(spam_visits) as spam_visits
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $totals = $result->fetch_assoc();
    $stmt->close();
    
    // Initialize stats with zeros if no data
    $stats = [
        'total_visits' => (int)($totals['total_visits'] ?? 0),
        'unique_visitors' => (int)($totals['unique_visitors'] ?? 0),
        'human_visits' => (int)($totals['human_visits'] ?? 0),
        'human_unique' => (int)($totals['human_unique'] ?? 0),
        'bot_visits' => (int)($totals['bot_visits'] ?? 0),
        'spam_visits' => (int)($totals['spam_visits'] ?? 0),
        'hourly_distribution' => array_fill(0, 24, 0),
        'page_views' => [],
        'referrers' => [],
        'user_agents' => []
    ];
    
    // Get aggregated hourly distribution
    $stmt = $conn->prepare("
        SELECT hourly_distribution 
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $hourly = json_decode($row['hourly_distribution'], true);
        if (is_array($hourly)) {
            for ($i = 0; $i < 24; $i++) {
                $stats['hourly_distribution'][$i] += ($hourly[$i] ?? 0);
            }
        }
    }
    $stmt->close();
    
    // Get aggregated page views
    $stmt = $conn->prepare("
        SELECT page_views 
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pageViews = [];
    while ($row = $result->fetch_assoc()) {
        $pages = json_decode($row['page_views'], true);
        if (is_array($pages)) {
            foreach ($pages as $page => $count) {
                if (!isset($pageViews[$page])) {
                    $pageViews[$page] = 0;
                }
                $pageViews[$page] += $count;
            }
        }
    }
    arsort($pageViews);
    $stats['page_views'] = $pageViews;
    $stmt->close();
    
    // Get aggregated referrers
    $stmt = $conn->prepare("
        SELECT referrers 
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $referrers = [];
    while ($row = $result->fetch_assoc()) {
        $refs = json_decode($row['referrers'], true);
        if (is_array($refs)) {
            foreach ($refs as $ref => $count) {
                if (!isset($referrers[$ref])) {
                    $referrers[$ref] = 0;
                }
                $referrers[$ref] += $count;
            }
        }
    }
    arsort($referrers);
    $stats['referrers'] = array_slice($referrers, 0, 50, true);
    $stmt->close();
    
    // Get aggregated user agents
    $stmt = $conn->prepare("
        SELECT user_agents 
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $userAgents = [];
    while ($row = $result->fetch_assoc()) {
        $agents = json_decode($row['user_agents'], true);
        if (is_array($agents)) {
            foreach ($agents as $agent => $count) {
                if (!isset($userAgents[$agent])) {
                    $userAgents[$agent] = 0;
                }
                $userAgents[$agent] += $count;
            }
        }
    }
    arsort($userAgents);
    $stats['user_agents'] = $userAgents;
    $stmt->close();

    // Aggregate top human source-IPs across the period (crawler detection). The
    // column may not exist on an un-migrated DB, so guard on it; historical rows
    // predating collection simply contribute nothing.
    $stats['top_ips'] = [];
    $stats['human_ip_distinct'] = 0;
    $stats['bot_reclassified'] = 0;
    $stats['reclassified_ips'] = [];
    if (ts_has_column($conn, 'top_ips')) {
        $stmt = $conn->prepare("
            SELECT top_ips
            FROM ten_traffic_stats
            WHERE site_key = ?
            AND stat_date BETWEEN ? AND ?
        ");
        $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $ipHits = [];
        $flaggedHits = [];
        $distinctSum = 0;
        $reclassifiedSum = 0;
        while ($row = $result->fetch_assoc()) {
            $obj = json_decode($row['top_ips'] ?? '', true);
            if (!is_array($obj)) continue;
            $distinctSum += (int)($obj['distinct'] ?? 0);
            $reclassifiedSum += (int)($obj['reclassified'] ?? 0);
            $ips = $obj['ips'] ?? [];
            if (is_array($ips)) {
                foreach ($ips as $ip => $hits) {
                    $ipHits[$ip] = ($ipHits[$ip] ?? 0) + (int)$hits;
                }
            }
            $flagged = $obj['flagged'] ?? [];
            if (is_array($flagged)) {
                foreach ($flagged as $ip => $hits) {
                    $flaggedHits[$ip] = ($flaggedHits[$ip] ?? 0) + (int)$hits;
                }
            }
        }
        $stmt->close();
        arsort($ipHits);
        arsort($flaggedHits);
        $stats['top_ips'] = array_slice($ipHits, 0, 50, true);
        $stats['human_ip_distinct'] = $distinctSum; // IP-days; not a true period distinct
        $stats['bot_reclassified'] = $reclassifiedSum;
        $stats['reclassified_ips'] = array_slice($flaggedHits, 0, 50, true);
    }

    $conn->close();

    return $stats;
}

/** True if ten_traffic_stats has column $col (cached per request). */
function ts_has_column($conn, $col) {
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    $ok = false;
    $r = @$conn->query("SHOW COLUMNS FROM ten_traffic_stats LIKE '" . $conn->real_escape_string($col) . "'");
    if ($r) { $ok = $r->num_rows > 0; $r->free(); }
    return $cache[$col] = $ok;
}
