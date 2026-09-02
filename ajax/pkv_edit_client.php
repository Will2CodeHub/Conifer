<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$canManageAll = hasPermission('pkv_manage_all') || isAdmin();
if (!$canManageAll) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get POST data
$enquiryId = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;
$firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
$lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : null;
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
$dateOfBirth = isset($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
$nationality = isset($_POST['nationality']) ? trim($_POST['nationality']) : null;
$additionalInfo = isset($_POST['additional_info']) ? trim($_POST['additional_info']) : null;

// Validate inputs
if (!$enquiryId || !$firstName || !$lastName) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$currentUser = getCurrentUser();

try {
    $conn = getDBConnection();
    
    // Get current enquiry details
    $stmt = $conn->prepare("SELECT * FROM ten_pkv_enquiries WHERE id = ?");
    $stmt->bind_param("i", $enquiryId);
    $stmt->execute();
    $oldEnquiry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$oldEnquiry) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Enquiry not found']);
        exit;
    }
    
    // Update enquiry
    $stmt = $conn->prepare("UPDATE ten_pkv_enquiries 
                           SET first_name = ?, 
                               last_name = ?, 
                               email = ?, 
                               phone = ?, 
                               date_of_birth = ?, 
                               nationality = ?, 
                               additional_info = ?,
                               updated_at = NOW() 
                           WHERE id = ?");
    $stmt->bind_param("sssssssi", $firstName, $lastName, $email, $phone, $dateOfBirth, $nationality, $additionalInfo, $enquiryId);
    $stmt->execute();
    $stmt->close();
    
    // Track changes
    $changes = [];
    if ($oldEnquiry['first_name'] !== $firstName) $changes[] = "First name: '{$oldEnquiry['first_name']}' → '{$firstName}'";
    if ($oldEnquiry['last_name'] !== $lastName) $changes[] = "Last name: '{$oldEnquiry['last_name']}' → '{$lastName}'";
    if ($oldEnquiry['email'] !== $email) $changes[] = "Email: '{$oldEnquiry['email']}' → '{$email}'";
    if ($oldEnquiry['phone'] !== $phone) $changes[] = "Phone: '{$oldEnquiry['phone']}' → '{$phone}'";
    if ($oldEnquiry['date_of_birth'] !== $dateOfBirth) $changes[] = "Date of birth changed";
    if ($oldEnquiry['nationality'] !== $nationality) $changes[] = "Nationality: '{$oldEnquiry['nationality']}' → '{$nationality}'";
    if ($oldEnquiry['additional_info'] !== $additionalInfo) $changes[] = "Additional information updated";
    
    // Add to history
    if (!empty($changes)) {
        $action = "Client details updated";
        $note = implode("; ", $changes);
        $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                               (enquiry_id, user_id, action, note, created_at) 
                               VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiss", $enquiryId, $currentUser['id'], $action, $note);
        $stmt->execute();
        $stmt->close();
    }
    
    // Log activity
    logActivity('pkv_client_edit', 'pkv_enquiry', $enquiryId, 
                "Updated client details for enquiry #$enquiryId: $firstName $lastName");
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Client details updated successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
