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

$enquiryId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$enquiryId) {
    echo json_encode(['success' => false, 'message' => 'Invalid enquiry ID']);
    exit;
}

$currentUser = getCurrentUser();
$isExternalBroker = hasPermission('pkv_external_broker') && !isAdmin();

try {
    $conn = getDBConnection();
    
    // Get enquiry details
    $query = "SELECT 
                e.*,
                b.company_name as broker_name,
                b.contact_person as broker_contact,
                b.email as broker_email
              FROM ten_pkv_enquiries e
              LEFT JOIN ten_pkv_brokers b ON e.assigned_broker_id = b.id
              WHERE e.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $enquiryId);
    $stmt->execute();
    $enquiry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$enquiry) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Enquiry not found']);
        exit;
    }
    
    // Check if external broker can access this enquiry
    if ($isExternalBroker && $enquiry['assigned_broker_id'] != $currentUser['id']) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Access denied to this enquiry']);
        exit;
    }
    
    // Get history
    $historyQuery = "SELECT 
                        h.*,
                        u.full_name as user_name
                     FROM ten_pkv_history h
                     LEFT JOIN ten_users u ON h.user_id = u.id
                     WHERE h.enquiry_id = ?
                     ORDER BY h.created_at DESC";
    
    $stmt = $conn->prepare($historyQuery);
    $stmt->bind_param("i", $enquiryId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'enquiry' => $enquiry,
        'history' => $history
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
