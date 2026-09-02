<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$canAssign = hasPermission('pkv_assign') || hasPermission('pkv_manage_all') || isAdmin();
if (!$canAssign) {
    echo json_encode(['success' => false, 'message' => 'Access denied - you do not have permission to assign brokers']);
    exit;
}

// Get POST data
$enquiryId = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;
$brokerId = isset($_POST['broker_id']) ? intval($_POST['broker_id']) : null;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

// Validate inputs
if (!$enquiryId) {
    echo json_encode(['success' => false, 'message' => 'Invalid enquiry ID']);
    exit;
}

$currentUser = getCurrentUser();

try {
    $conn = getDBConnection();
    
    // Get current enquiry details
    $stmt = $conn->prepare("SELECT * FROM ten_pkv_enquiries WHERE id = ?");
    $stmt->bind_param("i", $enquiryId);
    $stmt->execute();
    $enquiry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$enquiry) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Enquiry not found']);
        exit;
    }
    
    $oldBrokerId = $enquiry['assigned_broker_id'];
    
    // Verify broker exists if provided
    $brokerName = 'Unassigned';
    $brokerEmail = null;
    
    if ($brokerId) {
        $stmt = $conn->prepare("SELECT company_name, email, is_active FROM ten_pkv_brokers WHERE id = ?");
        $stmt->bind_param("i", $brokerId);
        $stmt->execute();
        $broker = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$broker) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Broker not found']);
            exit;
        }
        
        if (!$broker['is_active']) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Cannot assign to inactive broker']);
            exit;
        }
        
        $brokerName = $broker['company_name'];
        $brokerEmail = $broker['email'];
    }
    
    // Update enquiry
    $stmt = $conn->prepare("UPDATE ten_pkv_enquiries 
                           SET assigned_broker_id = ?, 
                               updated_at = NOW() 
                           WHERE id = ?");
    $stmt->bind_param("ii", $brokerId, $enquiryId);
    $stmt->execute();
    $stmt->close();
    
    // Determine action description
    $action = '';
    if ($oldBrokerId && $brokerId) {
        // Get old broker name
        $stmt = $conn->prepare("SELECT company_name FROM ten_pkv_brokers WHERE id = ?");
        $stmt->bind_param("i", $oldBrokerId);
        $stmt->execute();
        $oldBroker = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $oldBrokerName = $oldBroker ? $oldBroker['company_name'] : "Broker #$oldBrokerId";
        
        $action = "Reassigned from $oldBrokerName to $brokerName";
    } elseif ($brokerId) {
        $action = "Assigned to $brokerName";
    } else {
        $action = "Broker assignment removed";
    }
    
    // Add to history
    $historyNote = $action;
    if ($note) {
        $historyNote .= " - " . $note;
    }
    
    $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                           (enquiry_id, user_id, action, note, created_at) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("iiss", $enquiryId, $currentUser['id'], $action, $historyNote);
    $stmt->execute();
    $stmt->close();
    
    // Send email notification to new broker
    if ($brokerId && $brokerEmail) {
        $clientName = $enquiry['first_name'] . ' ' . $enquiry['last_name'];
        $subject = "PKV Enquiry Assigned - $clientName";
        $body = "Hello,\n\n";
        $body .= "A PKV enquiry has been assigned to you:\n\n";
        $body .= "Enquiry ID: #$enquiryId\n";
        $body .= "Client Name: $clientName\n";
        $body .= "Email: " . ($enquiry['email'] ?: 'Not provided') . "\n";
        $body .= "Phone: " . ($enquiry['phone'] ?: 'Not provided') . "\n";
        $body .= "Current State: " . ucwords(str_replace('_', ' ', $enquiry['state'])) . "\n";
        
        if ($enquiry['additional_info']) {
            $body .= "\nAdditional Information:\n" . $enquiry['additional_info'] . "\n";
        }
        
        if ($note) {
            $body .= "\nAssignment Note:\n$note\n";
        }
        
        $body .= "\nPlease login to the TEN Management System to view full details:\n";
        $body .= SITE_URL . "/module-pkv.php\n\n";
        $body .= "Best regards,\nTEN Management System";
        
        sendEmail($brokerEmail, $subject, nl2br($body));
        
        // Log email sent
        $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                               (enquiry_id, user_id, action, note, created_at) 
                               VALUES (?, ?, 'Email sent', ?, NOW())");
        $emailNote = "Assignment notification sent to $brokerName";
        $stmt->bind_param("iis", $enquiryId, $currentUser['id'], $emailNote);
        $stmt->execute();
        $stmt->close();
    }
    
    // Notify old broker if reassigning
    if ($oldBrokerId && $oldBrokerId != $brokerId) {
        $stmt = $conn->prepare("SELECT email, company_name FROM ten_pkv_brokers WHERE id = ?");
        $stmt->bind_param("i", $oldBrokerId);
        $stmt->execute();
        $oldBrokerData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($oldBrokerData && $oldBrokerData['email']) {
            $clientName = $enquiry['first_name'] . ' ' . $enquiry['last_name'];
            $subject = "PKV Enquiry Reassigned - $clientName";
            $body = "Hello,\n\n";
            $body .= "The following PKV enquiry has been reassigned:\n\n";
            $body .= "Enquiry ID: #$enquiryId\n";
            $body .= "Client Name: $clientName\n";
            $body .= "Reassigned to: $brokerName\n\n";
            
            if ($note) {
                $body .= "Reason: $note\n\n";
            }
            
            $body .= "Thank you for your service.\n\n";
            $body .= "Best regards,\nTEN Management System";
            
            sendEmail($oldBrokerData['email'], $subject, nl2br($body));
        }
    }
    
    // Log activity
    logActivity('pkv_broker_assigned', 'pkv_enquiry', $enquiryId, 
                "$action for enquiry #$enquiryId");
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Broker assignment updated successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
