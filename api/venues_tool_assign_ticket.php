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
$assignedTo = isset($data['assigned_to']) ? intval($data['assigned_to']) : null;

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Update ticket assignment
$updateQuery = "UPDATE support_tickets 
                SET assigned_to = ?,
                    updated_at = NOW()
                WHERE id = ?";
$stmt = $conn->prepare($updateQuery);
$stmt->bind_param("ii", $assignedTo, $ticketId);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => $assignedTo ? 'Ticket assigned successfully' : 'Ticket unassigned'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to assign ticket'
    ]);
}

$stmt->close();
$conn->close();
