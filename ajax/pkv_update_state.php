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

// Get POST data
$enquiryId = isset($_POST['enquiry_id']) ? intval($_POST['enquiry_id']) : 0;
$newState = isset($_POST['new_state']) ? trim($_POST['new_state']) : '';
$note = isset($_POST['note']) ? trim($_POST['note']) : '';
$sendEmail = isset($_POST['send_email']) && $_POST['send_email'] == '1';
$notifyAdmins = isset($_POST['notify_admins']) && $_POST['notify_admins'] == '1';
$emailRecipients = isset($_POST['email_recipients']) ? trim($_POST['email_recipients']) : '';
$emailSubject = isset($_POST['email_subject']) ? trim($_POST['email_subject']) : '';
$emailBody = isset($_POST['email_body']) ? trim($_POST['email_body']) : '';

// Validate inputs
if (!$enquiryId || !$newState) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$validStates = ['new', 'contacted', 'awaiting_client_response', 'awaiting_insurer_response', 
                'documents_pending', 'quote_provided', 'negotiating', 'contract_preparation', 
                'contract_sent', 'contract_signed', 'on_hold', 'closed_success', 'closed_failed'];

if (!in_array($newState, $validStates)) {
    echo json_encode(['success' => false, 'message' => 'Invalid state']);
    exit;
}

$currentUser = getCurrentUser();
$isExternalBroker = hasPermission('pkv_external_broker') && !isAdmin();

try {
    $conn = getDBConnection();
    
    // Get current enquiry details
    $stmt = $conn->prepare("SELECT e.*, b.company_name as broker_name, b.email as broker_email 
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
    
    // Check if external broker can modify this enquiry
    if ($isExternalBroker) {
        // Find broker ID for this user
        $brokerStmt = $conn->prepare("SELECT id FROM ten_pkv_brokers WHERE user_id = ?");
        $brokerStmt->bind_param("i", $currentUser['id']);
        $brokerStmt->execute();
        $brokerResult = $brokerStmt->get_result()->fetch_assoc();
        $brokerStmt->close();
        
        if (!$brokerResult || $enquiry['assigned_broker_id'] != $brokerResult['id']) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Access denied to this enquiry']);
            exit;
        }
    }
    
    $oldState = $enquiry['state'];
    
    // Update enquiry state and optionally add note
    if ($note) {
        $stmt = $conn->prepare("UPDATE ten_pkv_enquiries 
                               SET state = ?, 
                                   notes = CONCAT(IFNULL(notes, ''), '\n\n[', NOW(), '] ', ?),
                                   updated_at = NOW() 
                               WHERE id = ?");
        $noteWithUser = $currentUser['full_name'] . ": " . $note;
        $stmt->bind_param("ssi", $newState, $noteWithUser, $enquiryId);
    } else {
        $stmt = $conn->prepare("UPDATE ten_pkv_enquiries 
                               SET state = ?, 
                                   updated_at = NOW() 
                               WHERE id = ?");
        $stmt->bind_param("si", $newState, $enquiryId);
    }
    $stmt->execute();
    $stmt->close();
    
    // Add to history
    $action = "State changed from '" . ucwords(str_replace('_', ' ', $oldState)) . "' to '" . ucwords(str_replace('_', ' ', $newState)) . "'";
    $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                           (enquiry_id, user_id, action, note, old_state, new_state, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iissss", $enquiryId, $currentUser['id'], $action, $note, $oldState, $newState);
    $stmt->execute();
    $stmt->close();
    
    // Handle email notifications
    $emailsSent = [];
    
    // If external broker is making the change, ALWAYS notify admins automatically
    if ($isExternalBroker || $notifyAdmins) {
        // Get admin users with PKV permissions
        $adminQuery = "SELECT DISTINCT u.email, u.full_name
                      FROM ten_users u
                      LEFT JOIN ten_user_roles ur ON u.id = ur.user_id
                      LEFT JOIN ten_role_permissions rp ON ur.role_id = rp.role_id
                      LEFT JOIN ten_permissions p ON rp.permission_id = p.id
                      WHERE (u.is_admin = 1 OR p.permission_key IN ('pkv_manage_all', 'pkv_assign'))
                      AND u.email IS NOT NULL
                      AND u.status = 'active'";
        $adminEmails = $conn->query($adminQuery)->fetch_all(MYSQLI_ASSOC);
        
        // Prepare admin notification
        $adminSubject = "PKV State Change by " . ($isExternalBroker ? "Broker" : "Admin") . " - " . $enquiry['first_name'] . " " . $enquiry['last_name'];
        $adminBody = "Hello,\n\n";
        $adminBody .= "A PKV enquiry state has been updated:\n\n";
        $adminBody .= "Client: " . $enquiry['first_name'] . " " . $enquiry['last_name'] . "\n";
        $adminBody .= "Enquiry ID: #" . $enquiry['id'] . "\n";
        $adminBody .= "Updated by: " . $currentUser['full_name'] . ($isExternalBroker ? " (Broker)" : "") . "\n";
        $adminBody .= "Previous State: " . ucwords(str_replace('_', ' ', $oldState)) . "\n";
        $adminBody .= "New State: " . ucwords(str_replace('_', ' ', $newState)) . "\n";
        if ($note) {
            $adminBody .= "\nNote: " . $note . "\n";
        }
        $adminBody .= "\nView in TEN Management System:\n";
        $adminBody .= SITE_URL . "/module-pkv.php\n\n";
        $adminBody .= "Best regards,\nTEN Management System";
        
        foreach ($adminEmails as $admin) {
            if ($admin['email']) {
                sendEmail($admin['email'], $adminSubject, nl2br($adminBody));
                $emailsSent[] = $admin['email'];
            }
        }
    }
    
    // Send optional email to broker/client if requested by admin
    if ($sendEmail && !$isExternalBroker && $emailSubject && $emailBody && $emailRecipients) {
        $recipients = array_filter(array_map('trim', explode(',', $emailRecipients)));
        
        // Send emails
        $emailBodyWithFooter = $emailBody . "\n\n---\n" . 
                              "Client: " . $enquiry['first_name'] . " " . $enquiry['last_name'] . "\n" .
                              "Enquiry ID: #" . $enquiry['id'] . "\n" .
                              "New State: " . ucwords(str_replace('_', ' ', $newState)) . "\n\n" .
                              "View in TEN Management System:\n" .
                              SITE_URL . "/module-pkv.php";
        
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                sendEmail($recipient, $emailSubject, nl2br($emailBodyWithFooter));
                $emailsSent[] = $recipient;
            }
        }
    }
    
    // Log emails sent if any
    if (!empty($emailsSent)) {
        $emailRecipientsStr = implode(', ', $emailsSent);
        $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                               (enquiry_id, user_id, action, note, created_at) 
                               VALUES (?, ?, 'Email notification sent', ?, NOW())");
        $emailNote = "Notification emails sent to: $emailRecipientsStr";
        $stmt->bind_param("iis", $enquiryId, $currentUser['id'], $emailNote);
        $stmt->execute();
        $stmt->close();
    }
    
    // Log activity
    logActivity('pkv_state_change', 'pkv_enquiry', $enquiryId, 
                "Changed state from $oldState to $newState for enquiry #$enquiryId by " . $currentUser['full_name']);
    
    $conn->close();
    
    $responseMessage = 'Enquiry state updated successfully';
    if (!empty($emailsSent)) {
        $responseMessage .= ' and ' . count($emailsSent) . ' notification(s) sent';
    }
    
    echo json_encode([
        'success' => true,
        'message' => $responseMessage
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
