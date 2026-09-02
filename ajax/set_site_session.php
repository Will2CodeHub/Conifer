<?php
/**
 * Set Site Session Handler
 * Updates session with selected site's environment variables
 */

require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$siteKey = $_POST['site_key'] ?? '';

if (empty($siteKey)) {
    echo json_encode(['success' => false, 'error' => 'No site key provided']);
    exit;
}

// Define site configurations
$siteConfigs = [
    'ten' => [
        'TEN_BASE_PATH' => '/home/tenuser/public_html/',
        'TEN_BASE_WEBSITE_PATH' => 'theeyenewspapers.com',
        'TEN_BASE_SITE_NAME' => 'The Eye Newspapers',
        'TEN_BASE_SITE_NAME_SHORT' => 'The Eye Newspapers',
        'TEN_BASE_SITE_ABBREVIATION' => 'ten',
    ],
    'tme' => [
        'TEN_BASE_PATH' => '/home/tmeuser/public_html/',
        'TEN_BASE_WEBSITE_PATH' => 'themunicheye.com',
        'TEN_BASE_SITE_NAME' => 'The Munich Eye',
        'TEN_BASE_SITE_NAME_SHORT' => 'Munich Eye',
        'TEN_BASE_SITE_ABBREVIATION' => 'tme',
    ],
    // Add more sites here as needed
];

if (!isset($siteConfigs[$siteKey])) {
    echo json_encode(['success' => false, 'error' => 'Invalid site key']);
    exit;
}

// Store in session
$_SESSION['selected_site_config'] = $siteConfigs[$siteKey];
$_SESSION['selected_site_key'] = $siteKey;

echo json_encode([
    'success' => true,
    'site_key' => $siteKey,
    'site_name' => $siteConfigs[$siteKey]['TEN_BASE_SITE_NAME']
]);
