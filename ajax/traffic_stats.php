<?php
/**
 * AJAX Handler for Traffic Statistics
 * Provides real-time and historical traffic data
 */

require_once '../config.php';
require_once '../includes/LogAnalyzer.php';

@set_time_limit(0); // large live logs can take a while to stream

header('Content-Type: application/json');

// Require authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
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
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
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
    
    echo json_encode([
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
    
    // Site configuration — canonical publication acronyms; MUST match statistics.php
    // and the site_key stored by cron_collect_stats.php.
    $sites = [
        'ten'   => ['name' => 'The Eye Newspapers', 'log_path' => '/home/tenuser/private/unique_visitors_count.txt'],
        'tme'   => ['name' => 'The Munich Eye',     'log_path' => '/home/tmeuser/private/unique_visitors_count.txt'],
        'tge'   => ['name' => 'The Germany Eye',    'log_path' => '/home/tgeuser/private/unique_visitors_count.txt'],
        'bae'   => ['name' => 'Buenos Aires Eye',   'log_path' => '/home/baeuser/private/unique_visitors_count.txt'],
        'tbare' => ['name' => 'The Barcelona Eye',  'log_path' => '/home/tbareuse/private/unique_visitors_count.txt'],
        'tbrae' => ['name' => 'The Brazil Eye',     'log_path' => '/home/tbeuser/private/unique_visitors_count.txt'],
        'tce'   => ['name' => 'The Canary Eye',     'log_path' => '/home/tceuser/private/unique_visitors_count.txt'],
        'tmae'  => ['name' => 'The Madrid Eye',     'log_path' => '/home/tmaeuser/private/unique_visitors_count.txt'],
        'truse' => ['name' => 'The Russia Eye',     'log_path' => '/home/treuser/private/unique_visitors_count.txt'],
        'tte'   => ['name' => 'The Tokyo Eye',      'log_path' => '/home/tteuser/private/unique_visitors_count.txt'],
        'tpe'   => ['name' => 'The Paris Eye',      'log_path' => '/home/tpeuser/private/unique_visitors_count.txt'],
        'tbere' => ['name' => 'The Berlin Eye',     'log_path' => '/home/tberuser/private/unique_visitors_count.txt'],
    ];
    
    if (!isset($sites[$siteKey])) {
        error_log("Invalid site key: $siteKey");
        echo json_encode(['success' => false, 'message' => 'Invalid site key']);
        return;
    }
    
    $logPath = $sites[$siteKey]['log_path'];
    error_log("Log path: $logPath");
    error_log("File exists: " . (file_exists($logPath) ? 'YES' : 'NO'));
    
    // Calculate time range
    $timeRange = calculateTimeRange($period);
    if (!$timeRange) {
        error_log("Invalid period: $period");
        echo json_encode(['success' => false, 'message' => 'Invalid period']);
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
    
    echo json_encode([
        'success' => true,
        'site_key' => $siteKey,
        'period' => $period,
        'stats' => $stats,
        'data_source' => $dataSource
    ]);
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

    // Get current month stats - need to count days with actual data
    $currentMonthStart = strtotime('first day of this month 00:00:00');
    $currentMonthEnd = time();
    $currentMonthName = date('F Y', $currentMonthStart);

    // Count how many days have data in current month
    $startDate = date('Y-m-d', $currentMonthStart);
    $endDate = date('Y-m-d', $currentMonthEnd);

    // Total page views for the current month so far (sum of per-URL breakdown).
    $currentMonthPageViews = getPageViewsTotal($conn, $siteKey, $startDate, $endDate);
    
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT stat_date) as days_with_data,
               SUM(total_visits) as total_visits,
               SUM(unique_visitors) as unique_visitors,
               SUM(human_visits) as human_visits,
               SUM(human_unique) as human_unique
        FROM ten_traffic_stats 
        WHERE site_key = ? 
        AND stat_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("sss", $siteKey, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentMonthData = $result->fetch_assoc();
    $stmt->close();
    
    $currentMonthStats = [
        'total_visits' => (int)($currentMonthData['total_visits'] ?? 0),
        'unique_visitors' => (int)($currentMonthData['unique_visitors'] ?? 0),
        'human_visits' => (int)($currentMonthData['human_visits'] ?? 0),
        'human_unique' => (int)($currentMonthData['human_unique'] ?? 0)
    ];
    
    // Calculate projection based on days with actual data
    $daysInMonth = date('t');
    $daysWithData = (int)($currentMonthData['days_with_data'] ?? 0);
    $projectionAvailable = $daysWithData >= 1; // Need at least 1 day with data
    
    $projectedTotal = 0;
    if ($projectionAvailable && $currentMonthStats['total_visits'] > 0 && $daysWithData > 0) {
        $dailyAverage = $currentMonthStats['total_visits'] / $daysWithData;
        $projectedTotal = $dailyAverage * $daysInMonth;
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'debug' => [
            'days_with_data' => $daysWithData,
            'current_total' => $currentMonthStats['total_visits'],
            'daily_average' => $daysWithData > 0 ? ($currentMonthStats['total_visits'] / $daysWithData) : 0,
            'days_in_month' => $daysInMonth
        ],
        'last_month' => [
            'month_name' => $lastMonthName,
            'total_visits' => $lastMonthStats['total_visits'],
            'unique_visitors' => $lastMonthStats['unique_visitors'],
            'page_views' => $lastMonthPageViews,
            'human_visits' => $lastMonthStats['human_visits'],
            'human_unique' => $lastMonthStats['human_unique']
        ],
        'current_month' => [
            'month_name' => $currentMonthName,
            'days_in_month' => $daysInMonth,
            'days_elapsed' => $daysWithData,
            'total_visits' => $currentMonthStats['total_visits'],
            'unique_visitors' => $currentMonthStats['unique_visitors'],
            'page_views' => $currentMonthPageViews,
            'human_visits' => $currentMonthStats['human_visits'],
            'projection_available' => $projectionAvailable,
            'projected_total' => $projectedTotal
        ]
    ]);
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
    
    $conn->close();
    
    return $stats;
}
