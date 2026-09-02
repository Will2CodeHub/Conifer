<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getDBConnection();

$query = "SELECT 
    cs.id,
    cs.company_id,
    c.name as company_name,
    cs.subscription_status,
    cs.start_date,
    cs.end_date,
    cs.next_billing_date,
    cs.current_monthly_total_eur,
    pp.package_name
FROM company_subscriptions cs
JOIN companies c ON cs.company_id = c.id
LEFT JOIN pricing_packages pp ON cs.pricing_package_id = pp.id
WHERE c.deleted_at IS NULL
ORDER BY cs.subscription_status DESC, c.name ASC";

$result = $conn->query($query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database query failed']);
    $conn->close();
    exit();
}

$subscriptions = [];
while ($row = $result->fetch_assoc()) {
    $subscriptions[] = $row;
}

$conn->close();

echo json_encode([
    'success' => true,
    'subscriptions' => $subscriptions
]);
