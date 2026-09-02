<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$companyId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($companyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid company ID']);
    exit();
}

$conn = getVenuesDBConnection();

$query = "SELECT 
    c.id,
    c.name,
    c.description,
    c.website_link,
    c.created_at,
    cs.subscription_status,
    cs.current_monthly_total_eur as monthly_fee,
    cs.start_date,
    cs.next_billing_date,
    (SELECT email FROM users WHERE company_id = c.id AND is_admin_user = 1 LIMIT 1) as admin_email,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Brand' AND deleted_at IS NULL) as brand_count,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Venue' AND deleted_at IS NULL) as venue_count,
    (SELECT COUNT(*) FROM companies WHERE parent_id = c.id AND type = 'Subcompany' AND deleted_at IS NULL) as subcompany_count,
    (SELECT COUNT(*) FROM entity_staff WHERE entity_id = c.id) as staff_count,
    (SELECT COUNT(*) FROM entity_events WHERE entity_id = c.id) as event_count,
    (SELECT COUNT(*) FROM entity_articles WHERE entity_id = c.id) as article_count
FROM companies c
LEFT JOIN company_subscriptions cs ON c.id = cs.company_id AND cs.is_active = 1
WHERE c.id = ? AND c.type = 'Company' AND c.deleted_at IS NULL";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Company not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$company = $result->fetch_assoc();
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'company' => $company
]);
