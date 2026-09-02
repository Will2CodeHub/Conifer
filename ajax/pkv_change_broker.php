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
$brokerId = isset($_POST['broker_id']) ? intval($_POST['broker_id']) : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

// Validate inputs
if (!$enquiryId || !$brokerId) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$currentUser = getCurrentUser();

try {
    $conn = getDBConnection();
    
    // Get current enquiry details
    $stmt = $conn->prepare("SELECT e.*, b.company_name as old_broker_name 
                           FROM ten_pkv_enquiries e 
                           LEFT JOIN ten_pkv_brokers b ON e.assigned_broker_id = b.id 
                           WHERE e.id = ?");
    $stmt->bind_param("i", $enquiryId);
    $stmt->execute();
    $enquiry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$enquiry) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Enquiry not found']);
        exit;
    }
    
    // Get new broker details
    $stmt = $conn->prepare("SELECT company_name, email FROM ten_pkv_brokers WHERE id = ? AND is_active = 1");
    $stmt->bind_param("i", $brokerId);
    $stmt->execute();
    $newBroker = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$newBroker) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Broker not found or inactive']);
        exit;
    }
    
    $oldBrokerId = $enquiry['assigned_broker_id'];
    
    // Update enquiry
    $stmt = $conn->prepare("UPDATE ten_pkv_enquiries 
                           SET assigned_broker_id = ?, 
                               updated_at = NOW() 
                           WHERE id = ?");
    $stmt->bind_param("ii", $brokerId, $enquiryId);
    $stmt->execute();
    $stmt->close();
    
    // Add to history
    $action = "Broker assignment changed";
    $historyNote = "Changed from " . ($enquiry['old_broker_name'] ?: 'Unassigned') . " to " . $newBroker['company_name'];
    if ($note) {
        $historyNote .= "\nNote: " . $note;
    }
    
    $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                           (enquiry_id, user_id, action, note, created_at) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("iiss", $enquiryId, $currentUser['id'], $action, $historyNote);
    $stmt->execute();
    $stmt->close();
    
    // Send email notification to new broker
    $emailSubject = "New PKV Enquiry Assigned to You - " . $enquiry['first_name'] . " " . $enquiry['last_name'];
    $emailBody = "Hello,\n\n";
    $emailBody .= "A new PKV insurance enquiry has been assigned to you:\n\n";
    $emailBody .= "Client: " . $enquiry['first_name'] . " " . $enquiry['last_name'] . "\n";
    $emailBody .= "Email: " . ($enquiry['email'] ?: 'Not provided') . "\n";
    $emailBody .= "Phone: " . ($enquiry['phone'] ?: 'Not provided') . "\n";
    $emailBody .= "Current State: " . ucwords(str_replace('_', ' ', $enquiry['state'])) . "\n\n";
    $emailBody .= "Please log in to the TEN Management System to view full details and manage this enquiry:\n";
    $emailBody .= SITE_URL . "/module-pkv.php\n\n";
    $emailBody .= "Best regards,\nTEN Management Team";
    
    sendEmail($newBroker['email'], $emailSubject, nl2br($emailBody));
    
    // Log email sent
    $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                           (enquiry_id, user_id, action, note, created_at) 
                           VALUES (?, ?, 'Email sent', ?, NOW())");
    $emailLogNote = "Assignment notification sent to: " . $newBroker['email'];
    $stmt->bind_param("iis", $enquiryId, $currentUser['id'], $emailLogNote);
    $stmt->execute();
    $stmt->close();
    
    // Log activity
    logActivity('pkv_broker_change', 'pkv_enquiry', $enquiryId, 
                "Changed broker for enquiry #$enquiryId to " . $newBroker['company_name']);
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Broker assignment updated successfully and notification sent'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
