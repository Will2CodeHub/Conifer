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
    c.id,
    c.name,
    c.created_at,
    cs.subscription_status,
    cs.current_monthly_total_eur as monthly_fee,
    (SELECT email FROM users WHERE company_id = c.id AND is_admin_user = 1 LIMIT 1) as admin_email,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Brand' AND deleted_at IS NULL) as brand_count,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Venue' AND deleted_at IS NULL) as venue_count,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Subcompany' AND deleted_at IS NULL) as subcompany_count,
    (SELECT COUNT(*) FROM entity_staff WHERE entity_id = c.id) as staff_count,
    (SELECT COUNT(*) FROM entity_events WHERE entity_id = c.id) as event_count,
    (SELECT COUNT(*) FROM entity_articles WHERE entity_id = c.id) as article_count
FROM companies c
LEFT JOIN company_subscriptions cs ON c.id = cs.company_id AND cs.is_active = 1
WHERE c.type = 'Company' AND c.deleted_at IS NULL
ORDER BY c.name ASC";

$result = $conn->query($query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database query failed']);
    $conn->close();
    exit();
}

$companies = [];
while ($row = $result->fetch_assoc()) {
    $companies[] = $row;
}

$conn->close();

echo json_encode([
    'success' => true,
    'companies' => $companies
]);
