#!/usr/bin/php
<?php
/**
 * Daily Traffic Statistics Collection Cron Job
 * 
 * This script should run daily (preferably at 00:05) to collect
 * statistics for the previous day and store them in the database.
 * 
 * Uses environment variables for site configuration - each site
 * should run its own cron job.
 * 
 * Add to crontab:
 * 5 0 * * * cd /path/to/site && /usr/bin/php cron_collect_stats.php >> /path/to/logs/cron_stats.log 2>&1
 */

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/LogAnalyzer.php';

echo "=== Traffic Statistics Collection Started at " . date('Y-m-d H:i:s') . " ===\n";

// Get site info from environment variables
$siteKey = getenv('TEN_BASE_SITE_ABBREVIATION');
$siteName = getenv('TEN_BASE_SITE_NAME');
$basePath = getenv('TEN_BASE_PATH');

if (!$siteKey || !$siteName || !$basePath) {
    echo "ERROR: Site configuration not found in environment variables.\n";
    echo "Required: TEN_BASE_SITE_ABBREVIATION, TEN_BASE_SITE_NAME, TEN_BASE_PATH\n";
    exit(1);
}

$logPath = $basePath . '/../private/unique_visitors_count.txt';

echo "Site: $siteName ($siteKey)\n";
echo "Base Path: $basePath\n";
echo "Log file: $logPath\n\n";

// Check if log file exists
if (!file_exists($logPath)) {
    echo "ERROR: Log file not found at $logPath\n";
    exit(1);
}

if (!is_readable($logPath)) {
    echo "ERROR: Log file is not readable at $logPath\n";
    exit(1);
}

// Trim the visitor log FIRST — a runaway file would otherwise exhaust memory and
// fatal the analyzer (which is what broke the tool). Rotation streams the file
// and rewrites it IN PLACE (owner/permissions preserved), so it is safe to run
// as root over each site's own log. Keeps the last 3 days (yesterday included).
echo "Rotating visitor log (keep last 3 days)...\n";
$rot = rotateVisitorLog($logPath, 3);
if ($rot['success']) {
    echo "✓ Kept {$rot['kept']} entries, removed {$rot['removed']}; {$rot['size_before']} → {$rot['size_after']} bytes\n\n";
} else {
    echo "⚠ Rotation skipped (log left intact): {$rot['error']}\n\n";
}

$conn = getDBConnection();

// Calculate yesterday's date range
$yesterday = strtotime('yesterday');
$yesterdayStart = strtotime('00:00:00', $yesterday);
$yesterdayEnd = strtotime('23:59:59', $yesterday);
$yesterdayDate = date('Y-m-d', $yesterday);

echo "Collecting stats for date: $yesterdayDate\n";
echo "Time range: " . date('Y-m-d H:i:s', $yesterdayStart) . " to " . date('Y-m-d H:i:s', $yesterdayEnd) . "\n\n";

// Check if stats already exist for this date
$checkStmt = $conn->prepare("SELECT id FROM ten_traffic_stats WHERE site_key = ? AND stat_date = ?");
$checkStmt->bind_param("ss", $siteKey, $yesterdayDate);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    echo "Stats already exist for this date. Updating...\n";
}

// Analyze log file
try {
    $analyzer = new LogAnalyzer($logPath, $siteKey);
    // includeIds=true → also returns visitor_hashes/human_unique_hashes (crc32 of
    // the day's distinct visitor IDs) so month-level views can dedup by UNION.
    $stats = $analyzer->analyzeTimePeriod($yesterdayStart, $yesterdayEnd, 0, true);
    
    echo "→ Total Visits: " . $stats['total_visits'] . "\n";
    echo "→ Unique Visitors: " . $stats['unique_visitors'] . "\n";
    echo "→ Human Visits: " . $stats['human_visits'] . "\n";
    echo "→ Human Unique: " . $stats['human_unique'] . "\n";
    echo "→ Bot Visits: " . $stats['bot_visits'] . "\n";
    echo "→ Spam Visits: " . $stats['spam_visits'] . "\n";
    echo "→ Reclassified as automated (browser-UA crawlers + no-IP monitor): " .
         (int)($stats['bot_reclassified'] ?? 0) . "\n";
    
    // Prepare data for storage
    $hourlyJson = json_encode($stats['hourly_distribution']);
    $pageViewsJson = json_encode(array_slice($stats['page_views'], 0, 100, true)); // Top 100 pages
    $referrersJson = json_encode(array_slice($stats['referrers'], 0, 50, true)); // Top 50 referrers
    $userAgentsJson = json_encode($stats['user_agents']);
    
    // Insert or update stats
    if ($existing) {
        $updateStmt = $conn->prepare("UPDATE ten_traffic_stats SET 
            total_visits = ?, 
            unique_visitors = ?, 
            human_visits = ?, 
            human_unique = ?, 
            bot_visits = ?, 
            spam_visits = ?,
            hourly_distribution = ?,
            page_views = ?,
            referrers = ?,
            user_agents = ?
            WHERE site_key = ? AND stat_date = ?");
        
        $updateStmt->bind_param("iiiiiissssss",
            $stats['total_visits'],
            $stats['unique_visitors'],
            $stats['human_visits'],
            $stats['human_unique'],
            $stats['bot_visits'],
            $stats['spam_visits'],
            $hourlyJson,
            $pageViewsJson,
            $referrersJson,
            $userAgentsJson,
            $siteKey,
            $yesterdayDate
        );
        
        if ($updateStmt->execute()) {
            echo "✓ Stats updated successfully\n";
            $success = true;
        } else {
            echo "✗ Error updating stats: " . $updateStmt->error . "\n";
            $success = false;
        }
        $updateStmt->close();
    } else {
        $insertStmt = $conn->prepare("INSERT INTO ten_traffic_stats 
            (site_key, stat_date, total_visits, unique_visitors, human_visits, human_unique, 
             bot_visits, spam_visits, hourly_distribution, page_views, referrers, user_agents) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $insertStmt->bind_param("ssiiiiisssss",
            $siteKey,
            $yesterdayDate,
            $stats['total_visits'],
            $stats['unique_visitors'],
            $stats['human_visits'],
            $stats['human_unique'],
            $stats['bot_visits'],
            $stats['spam_visits'],
            $hourlyJson,
            $pageViewsJson,
            $referrersJson,
            $userAgentsJson
        );
        
        if ($insertStmt->execute()) {
            echo "✓ Stats saved successfully\n";
            $success = true;
        } else {
            echo "✗ Error saving stats: " . $insertStmt->error . "\n";
            $success = false;
        }
        $insertStmt->close();
    }
    
    // Store the per-day distinct visitor-ID fingerprints (for month-level dedup).
    // Done as a separate UPDATE, guarded on the column existing, so the collector
    // keeps working on a DB that hasn't had the migration applied yet.
    if ($success && ten_stats_has_column($conn, 'ten_traffic_stats', 'visitor_hashes')) {
        $visitorHashesJson = json_encode(array_values($stats['visitor_hashes'] ?? []));
        $humanHashesJson   = json_encode(array_values($stats['human_unique_hashes'] ?? []));
        $hashStmt = $conn->prepare("UPDATE ten_traffic_stats SET visitor_hashes = ?, human_unique_hashes = ? WHERE site_key = ? AND stat_date = ?");
        if ($hashStmt) {
            $hashStmt->bind_param("ssss", $visitorHashesJson, $humanHashesJson, $siteKey, $yesterdayDate);
            if ($hashStmt->execute()) {
                echo "✓ Stored " . count($stats['visitor_hashes'] ?? []) . " visitor fingerprints for month-level dedup\n";
            } else {
                echo "⚠ Could not store visitor fingerprints: " . $hashStmt->error . "\n";
            }
            $hashStmt->close();
        }
    }

    // Store the top human source-IPs + distinct-human-IP count for the day, so the
    // stats page can spot browser-UA crawlers (a few IPs with huge "human" hit
    // counts) that the user-agent bot filter doesn't catch. Guarded on the column
    // existing so the collector keeps working on an un-migrated DB.
    if ($success && ten_stats_has_column($conn, 'ten_traffic_stats', 'top_ips')) {
        $topIpsJson = json_encode([
            'distinct'     => (int)($stats['human_ip_distinct'] ?? 0),
            'ips'          => (object)($stats['top_ips'] ?? []),
            'reclassified' => (int)($stats['bot_reclassified'] ?? 0),
            'flagged'      => (object)($stats['reclassified_ips'] ?? []),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        $ipStmt = $conn->prepare("UPDATE ten_traffic_stats SET top_ips = ? WHERE site_key = ? AND stat_date = ?");
        if ($ipStmt) {
            $ipStmt->bind_param("sss", $topIpsJson, $siteKey, $yesterdayDate);
            if ($ipStmt->execute()) {
                echo "✓ Stored top " . count($stats['top_ips'] ?? []) . " human source-IPs (" .
                     (int)($stats['human_ip_distinct'] ?? 0) . " distinct) for crawler detection\n";
            } else {
                echo "⚠ Could not store source-IPs: " . $ipStmt->error . "\n";
            }
            $ipStmt->close();
        }
    }

} catch (Exception $e) {
    echo "✗ Error processing site: " . $e->getMessage() . "\n";
    $success = false;
}

$conn->close();

echo "\n=== Collection Complete ===\n";
echo "Site: $siteName\n";
echo "Status: " . ($success ? "SUCCESS" : "FAILED") . "\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";

exit($success ? 0 : 1);

/**
 * Rotate the visitor log, keeping only the last $keepDays of entries plus a hard
 * cap on line count. Each line is "[<date string>] visitorId ip userAgent ...",
 * so we parse the bracketed date with strtotime (the OLD cleanup wrongly read it
 * as "unixts|ip|ua", got intval 0, and deleted everything — hence it was off).
 *
 * SAFETY: if parsing would keep almost nothing from a large file (a sign the
 * format changed), it ABORTS and leaves the file untouched rather than wipe it.
 *
 * @param string $logPath
 * @param int    $keepDays  days of history to retain
 * @param int    $maxLines  final hard cap on retained lines
 * @return array
 */
/** True if $table has a column named $col (cached per request). */
function ten_stats_has_column($conn, $table, $col) {
    static $cache = [];
    $key = $table . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];
    $has = false;
    $r = @$conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . $conn->real_escape_string($col) . "'");
    if ($r) { $has = $r->num_rows > 0; $r->free(); }
    return $cache[$key] = $has;
}

function rotateVisitorLog($logPath, $keepDays = 3, $maxLines = 200000) {
    $result = ['success' => false, 'removed' => 0, 'kept' => 0, 'size_before' => 0, 'size_after' => 0, 'error' => ''];

    if (!file_exists($logPath))  { $result['error'] = 'Log file does not exist'; return $result; }
    if (!is_writable($logPath))  { $result['error'] = 'Log file is not writable'; return $result; }
    $result['size_before'] = filesize($logPath);

    $cutoff = strtotime('-' . max(1, (int)$keepDays) . ' days');

    // Pass 1: stream the original, copy only recent lines to a temp file. Bounded
    // memory regardless of how large the log has grown.
    $in = @fopen($logPath, 'r');
    if (!$in) { $result['error'] = 'Could not open log for reading'; return $result; }
    $tmp = $logPath . '.rot.' . getmypid();
    $out = @fopen($tmp, 'w');
    if (!$out) { fclose($in); $result['error'] = 'Could not open temp file'; return $result; }

    $total = 0; $kept = 0; $parsedOk = 0;
    while (($line = fgets($in)) !== false) {
        if (trim($line) === '') { continue; }
        $total++;
        $t = false;
        if (preg_match('/^\[(.*?)\]/', $line, $m)) {
            // Same normalisation LogAnalyzer uses: "30/Oct/2025:13:38:41 +0100".
            $c = str_replace('/', '-', trim($m[1]));
            $c = preg_replace('/:/', ' ', $c, 1);
            $t = strtotime($c);
        }
        if ($t !== false) { $parsedOk++; }                     // date understood
        if ($t !== false && $t >= $cutoff) { fwrite($out, rtrim($line, "\r\n") . "\n"); $kept++; }
    }
    fclose($in); fclose($out);

    // SAFETY: abort only if we could not PARSE most lines (a real format change).
    // Keeping few lines is EXPECTED when trimming a long backlog to a few days, so
    // that alone must NOT trigger an abort.
    if ($total > 50 && $parsedOk < ($total * 0.5)) {
        @unlink($tmp);
        $result['error'] = "safety abort — only $parsedOk/$total lines had a parseable date (format mismatch?)";
        return $result;
    }

    // Hard line cap (tail): if still over the cap, drop the oldest lines.
    $skip = ($kept > $maxLines) ? ($kept - $maxLines) : 0;

    // Pass 2: rewrite the ORIGINAL file in place (fopen 'w' truncates but keeps
    // the same inode → owner and permissions are preserved). This is what makes
    // it safe to run as root over a file owned by each site's user.
    $rin  = @fopen($tmp, 'r');
    $orig = @fopen($logPath, 'w');
    if (!$rin || !$orig) { if ($rin) fclose($rin); if ($orig) fclose($orig); @unlink($tmp); $result['error'] = 'Could not rewrite original log'; return $result; }
    $idx = 0; $written = 0;
    while (($line = fgets($rin)) !== false) {
        if ($idx++ < $skip) { continue; }
        fwrite($orig, $line); $written++;
    }
    fclose($rin); fclose($orig); @unlink($tmp);

    $result['kept']    = $written;
    $result['removed'] = $total - $written;
    clearstatcache();
    $result['size_after'] = filesize($logPath);
    $result['success'] = true;
    return $result;
}
