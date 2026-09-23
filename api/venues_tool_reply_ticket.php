<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$ticketId = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;
$message = isset($data['message']) ? trim($data['message']) : '';

if ($ticketId <= 0 || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID or message']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get current user ID from TEN Management session
$adminUserId = $_SESSION['user_id'] ?? null;

// Insert reply
$insertQuery = "INSERT INTO support_ticket_replies (ticket_id, user_id, is_admin, message, created_at) 
                VALUES (?, ?, 1, ?, NOW())";
$stmt = $conn->prepare($insertQuery);
$stmt->bind_param("iis", $ticketId, $adminUserId, $message);

if ($stmt->execute()) {
    // Update ticket status to in_progress if it was open
    $updateQuery = "UPDATE support_tickets 
                    SET status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END,
                        updated_at = NOW()
                    WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("i", $ticketId);
    $updateStmt->execute();
    $updateStmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Reply sent successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send reply'
    ]);
}

$stmt->close();
$conn->close();
