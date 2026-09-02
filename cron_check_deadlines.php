#!/usr/bin/php
<?php
/**
 * Project Management Deadline Notification Cron Job
 * 
 * This script checks for approaching task deadlines and sends notifications
 * 
 * Setup:
 * 1. Make executable: chmod +x cron_check_deadlines.php
 * 2. Add to crontab: 0 9 * * * /usr/bin/php /path/to/cron_check_deadlines.php
 * 
 * Or use curl method:
 * 0 9 * * * curl -X POST https://yoursite.com/management/ajax/project_management.php -d "action=check_deadlines"
 */

require_once __DIR__ . '/config.php';

echo "=== Project Deadline Check Started at " . date('Y-m-d H:i:s') . " ===\n";

// Make API call to check deadlines
$url = SITE_URL . '/ajax/project_management.php';
$data = ['action' => 'check_deadlines'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "ERROR: cURL error - $error\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "ERROR: HTTP $httpCode response\n";
    exit(1);
}

$result = json_decode($response, true);

if ($result && $result['success']) {
    echo "SUCCESS: Checked {$result['checked']} upcoming tasks\n";
    echo "Notifications sent successfully\n";
} else {
    $errorMsg = $result['error'] ?? 'Unknown error';
    echo "ERROR: $errorMsg\n";
    exit(1);
}

echo "=== Deadline Check Completed at " . date('Y-m-d H:i:s') . " ===\n";
exit(0);