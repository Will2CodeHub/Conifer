<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 50;

try {
    $conn = getDBConnection();
    
    // Get activity log including PKV-related and login activities
    $query = "SELECT 
                a.id,
                a.user_id,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.created_at,
                u.full_name as user_name
              FROM ten_activity_log a
              LEFT JOIN ten_users u ON a.user_id = u.id
              WHERE (a.entity_type LIKE 'pkv%' OR a.action LIKE 'pkv%' OR a.action = 'login')
              ORDER BY a.created_at DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
