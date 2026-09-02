<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$hasPKVAccess = hasPermission('pkv_view') || isAdmin();
if (!$hasPKVAccess) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$currentUser = getCurrentUser();
$isExternalBroker = hasPermission('pkv_external_broker') && !isAdmin();

// Get parameters
$state = isset($_GET['state']) ? $_GET['state'] : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$offset = ($page - 1) * $perPage;

try {
    $conn = getDBConnection();
    
    // Build query
    $query = "SELECT e.*, b.company_name as broker_name 
              FROM ten_pkv_enquiries e 
              LEFT JOIN ten_pkv_brokers b ON e.assigned_broker_id = b.id 
              WHERE 1=1";
    
    $params = [];
    $types = '';
    
    // External broker filter
    if ($isExternalBroker) {
        // Find broker ID for this user
        $brokerStmt = $conn->prepare("SELECT id FROM ten_pkv_brokers WHERE user_id = ?");
        $brokerStmt->bind_param("i", $currentUser['id']);
        $brokerStmt->execute();
        $brokerResult = $brokerStmt->get_result()->fetch_assoc();
        $brokerStmt->close();
        
        if ($brokerResult) {
            $query .= " AND e.assigned_broker_id = ?";
            $params[] = $brokerResult['id'];
            $types .= 'i';
        } else {
            // No broker ID found, return empty
            echo json_encode([
                'success' => true,
                'enquiries' => [],
                'total' => 0,
                'total_pages' => 0,
                'current_page' => $page
            ]);
            exit;
        }
    }
    
    // State filter
    if ($state !== 'all') {
        $query .= " AND e.state = ?";
        $params[] = $state;
        $types .= 's';
    }
    
    // Search filter
    if ($search !== '') {
        $query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.phone LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sssss';
    }
    
    // Count total - use a subquery approach instead of regex
    $countQuery = "SELECT COUNT(*) as total FROM (" . $query . ") as filtered";
    $countStmt = $conn->prepare($countQuery);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $totalPages = ceil($total / $perPage);
    
    // Get enquiries
    $query .= " ORDER BY e.updated_at DESC, e.created_at DESC LIMIT ? OFFSET " . $offset;
    $params[] = $perPage;
    $types .= 'i';
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $enquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Log activity for broker login
    if ($isExternalBroker) {
        logActivity('pkv_broker_access', 'pkv_module', null, 
                    "Broker accessed PKV module (viewed enquiries)");
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'enquiries' => $enquiries,
        'total' => $total,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'per_page' => $perPage
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
