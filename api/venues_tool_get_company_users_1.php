<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$companyId = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

if ($companyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid company ID']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get company name
$companyQuery = "SELECT name FROM companies WHERE id = ? AND type = 'Company' LIMIT 1";
$stmt = $conn->prepare($companyQuery);
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

// Get all active users for this company
$usersQuery = "SELECT id, username, email, position FROM users 
               WHERE company_id = ? AND active = 1 
               ORDER BY is_admin_user DESC, username ASC";
$stmt = $conn->prepare($usersQuery);
$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'company_name' => $company['name'],
    'users' => $users
]);
