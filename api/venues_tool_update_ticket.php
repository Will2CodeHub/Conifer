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
$status = isset($data['status']) ? trim($data['status']) : '';

$validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
if ($ticketId <= 0 || !in_array($status, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID or status']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Update ticket status
$updateQuery = "UPDATE support_tickets 
                SET status = ?,
                    resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE resolved_at END,
                    updated_at = NOW()
                WHERE id = ?";
$stmt = $conn->prepare($updateQuery);
$stmt->bind_param("ssi", $status, $status, $ticketId);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Ticket status updated successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update ticket status'
    ]);
}

$stmt->close();
$conn->close();
