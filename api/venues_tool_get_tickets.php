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
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$query = "SELECT 
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
    u.username as user_name
FROM support_tickets st
JOIN companies c ON st.company_id = c.id
JOIN users u ON st.user_id = u.id
WHERE c.deleted_at IS NULL
ORDER BY 
    CASE st.status
        WHEN 'open' THEN 1
        WHEN 'in_progress' THEN 2
        WHEN 'resolved' THEN 3
        WHEN 'closed' THEN 4
    END,
    st.created_at DESC";

$result = $conn->query($query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database query failed: ' . $conn->error]);
    $conn->close();
    exit();
}

$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}

$conn->close();

echo json_encode([
    'success' => true,
    'tickets' => $tickets
]);
