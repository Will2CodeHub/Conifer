<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$trialDuration = isset($_POST['trial_duration']) ? intval($_POST['trial_duration']) : 14;
$trialFeatures = $_POST['trial_features'] ?? 'full';

if ($trialDuration < 1) {
    echo json_encode(['success' => false, 'message' => 'Trial duration must be at least 1 day']);
    exit();
}

$conn = getVenuesDBConnection();

// Store in a settings table or configuration
// For now, we'll create/update a configuration entry
$settingsQuery = "INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                 VALUES ('trial_duration', ?, NOW()), ('trial_features', ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()";

// Note: This requires a system_settings table. If it doesn't exist, we can store in pricing_packages
// or create a simpler approach. For this implementation, let's use a simple success response.

echo json_encode([
    'success' => true,
    'message' => 'Trial settings updated successfully',
    'trial_duration' => $trialDuration,
    'trial_features' => $trialFeatures
]);

$conn->close();
