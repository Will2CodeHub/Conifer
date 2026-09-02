<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getVenuesDBConnection();

// Total revenue
$revenueQuery = "SELECT SUM(current_monthly_total_eur) as total FROM company_subscriptions WHERE is_active = 1";
$totalRevenue = $conn->query($revenueQuery)->fetch_assoc()['total'] ?? 0;

// Average revenue per company
$avgQuery = "SELECT AVG(current_monthly_total_eur) as avg FROM company_subscriptions WHERE is_active = 1";
$avgRevenue = $conn->query($avgQuery)->fetch_assoc()['avg'] ?? 0;

// Total entities
$entitiesQuery = "SELECT COUNT(*) as count FROM companies WHERE deleted_at IS NULL";
$totalEntities = $conn->query($entitiesQuery)->fetch_assoc()['count'] ?? 0;

// Conversion rate (trial to active)
$trialQuery = "SELECT COUNT(*) as count FROM company_subscriptions WHERE subscription_status = 'trial'";
$trialCount = $conn->query($trialQuery)->fetch_assoc()['count'] ?? 0;

$activeQuery = "SELECT COUNT(*) as count FROM company_subscriptions WHERE subscription_status = 'active'";
$activeCount = $conn->query($activeQuery)->fetch_assoc()['count'] ?? 0;

$conversionRate = ($trialCount + $activeCount) > 0 ? ($activeCount / ($trialCount + $activeCount)) * 100 : 0;

$conn->close();

echo json_encode([
    'success' => true,
    'analytics' => [
        'total_revenue' => $totalRevenue,
        'avg_revenue' => $avgRevenue,
        'total_entities' => $totalEntities,
        'conversion_rate' => $conversionRate
    ]
]);
