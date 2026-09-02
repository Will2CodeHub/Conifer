<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int)$_GET['user_id'];

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT role_id FROM ten_user_roles WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$roles = [];
while ($row = $result->fetch_assoc()) {
    $roles[] = (int)$row['role_id'];
}

$conn->close();

echo json_encode(['success' => true, 'roles' => $roles]);
?>
