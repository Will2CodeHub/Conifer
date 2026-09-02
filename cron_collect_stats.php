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
    $stats = $analyzer->analyzeTimePeriod($yesterdayStart, $yesterdayEnd);
    
    echo "→ Total Visits: " . $stats['total_visits'] . "\n";
    echo "→ Unique Visitors: " . $stats['unique_visitors'] . "\n";
    echo "→ Human Visits: " . $stats['human_visits'] . "\n";
    echo "→ Human Unique: " . $stats['human_unique'] . "\n";
    echo "→ Bot Visits: " . $stats['bot_visits'] . "\n";
    echo "→ Spam Visits: " . $stats['spam_visits'] . "\n";
    
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
    
    // Clean up old entries from log file if stats were saved successfully
    // DISABLED - cleanup function was deleting all entries due to format mismatch
    /*
    if ($success) {
        echo "\nCleaning up log file...\n";
        $cleanupResult = cleanupLogFile($logPath, $yesterdayEnd);
        if ($cleanupResult['success']) {
            echo "✓ Removed {$cleanupResult['removed']} old entries\n";
            echo "✓ Kept {$cleanupResult['kept']} recent entries\n";
            if ($cleanupResult['size_before'] > 0) {
                $reduction = round((1 - $cleanupResult['size_after'] / $cleanupResult['size_before']) * 100, 1);
                echo "✓ File size reduced by {$reduction}%\n";
            }
        } else {
            echo "⚠ Warning: Could not clean log file: " . $cleanupResult['error'] . "\n";
        }
    }
    */
    
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
 * Clean up old entries from the log file, keeping only entries from the current day forward
 * 
 * @param string $logPath Path to the log file
 * @param int $cutoffTimestamp Timestamp - entries before this will be removed
 * @return array Result with success status and statistics
 */
function cleanupLogFile($logPath, $cutoffTimestamp) {
    $result = [
        'success' => false,
        'removed' => 0,
        'kept' => 0,
        'size_before' => 0,
        'size_after' => 0,
        'error' => ''
    ];
    
    try {
        // Check if file exists and is writable
        if (!file_exists($logPath)) {
            $result['error'] = 'Log file does not exist';
            return $result;
        }
        
        if (!is_writable($logPath)) {
            $result['error'] = 'Log file is not writable';
            return $result;
        }
        
        $result['size_before'] = filesize($logPath);
        
        // Read the entire file
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $result['error'] = 'Could not read log file';
            return $result;
        }
        
        // Filter lines to keep only recent entries
        $keptLines = [];
        foreach ($lines as $line) {
            // Parse the line: timestamp|ip|user_agent
            $parts = explode('|', $line, 2);
            if (count($parts) >= 1) {
                $timestamp = intval($parts[0]);
                // Keep entries from today and future (in case of clock skew)
                if ($timestamp > $cutoffTimestamp) {
                    $keptLines[] = $line;
                    $result['kept']++;
                } else {
                    $result['removed']++;
                }
            }
        }
        
        // Write back only the kept lines
        $tempFile = $logPath . '.tmp';
        $written = file_put_contents($tempFile, implode("\n", $keptLines) . "\n");
        
        if ($written === false) {
            $result['error'] = 'Could not write temporary file';
            return $result;
        }
        
        // Replace original file with cleaned version
        if (!rename($tempFile, $logPath)) {
            $result['error'] = 'Could not replace log file';
            @unlink($tempFile); // Clean up temp file
            return $result;
        }
        
        $result['size_after'] = filesize($logPath);
        $result['success'] = true;
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}
