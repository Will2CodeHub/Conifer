<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$ticketId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get ticket details
$ticketQuery = "SELECT 
    st.id,
    st.company_id,
    st.user_id,
    st.subject,
    st.message,
    st.status,
    st.priority,
    st.created_at,
    st.updated_at,
    c.name as company_name,
    u.username as user_name,
    u.email as user_email
FROM support_tickets st
JOIN companies c ON st.company_id = c.id
JOIN users u ON st.user_id = u.id
WHERE st.id = ?";

$stmt = $conn->prepare($ticketQuery);
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$ticket = $result->fetch_assoc();
$stmt->close();

// Get replies
$repliesQuery = "SELECT 
    str.id,
    str.ticket_id,
    str.user_id,
    str.is_admin,
    str.message,
    str.created_at,
    u.username as user_name
FROM support_ticket_replies str
LEFT JOIN users u ON str.user_id = u.id
WHERE str.ticket_id = ?
ORDER BY str.created_at ASC";

$stmt = $conn->prepare($repliesQuery);
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$result = $stmt->get_result();

$replies = [];
while ($row = $result->fetch_assoc()) {
    $replies[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'ticket' => $ticket,
    'replies' => $replies
]);
