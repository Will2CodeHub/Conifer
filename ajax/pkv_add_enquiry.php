<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$canManage = hasPermission('pkv_manage') || hasPermission('pkv_manage_all') || isAdmin();
if (!$canManage) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get POST data
$firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
$lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : null;
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
$dateOfBirth = isset($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
$nationality = isset($_POST['nationality']) ? trim($_POST['nationality']) : null;
$currentInsurance = isset($_POST['current_insurance']) ? trim($_POST['current_insurance']) : null;
$employmentStatus = isset($_POST['employment_status']) ? trim($_POST['employment_status']) : null;
$annualIncome = isset($_POST['annual_income']) ? floatval($_POST['annual_income']) : null;
$familyMembers = isset($_POST['family_members']) ? intval($_POST['family_members']) : 0;
$existingConditions = isset($_POST['existing_conditions']) ? trim($_POST['existing_conditions']) : null;
$additionalInfo = isset($_POST['additional_info']) ? trim($_POST['additional_info']) : null;
$source = isset($_POST['source']) ? trim($_POST['source']) : null;
$assignedBrokerId = isset($_POST['assigned_broker_id']) ? intval($_POST['assigned_broker_id']) : null;

// Validate required fields
if (!$firstName || !$lastName) {
    echo json_encode(['success' => false, 'message' => 'First name and last name are required']);
    exit;
}

if (!$email && !$phone) {
    echo json_encode(['success' => false, 'message' => 'Either email or phone number is required']);
    exit;
}

// Validate email format if provided
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Validate employment status if provided
$validEmploymentStatuses = ['employed', 'self_employed', 'student', 'unemployed', 'retired', 'other'];
if ($employmentStatus && !in_array($employmentStatus, $validEmploymentStatuses)) {
    $employmentStatus = null;
}

$currentUser = getCurrentUser();

try {
    $conn = getDBConnection();
    
    // Check for duplicate enquiry (same email or phone)
    if ($email) {
        $stmt = $conn->prepare("SELECT id FROM ten_pkv_enquiries WHERE email = ? AND state NOT IN ('closed_success', 'closed_failed')");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $existing = $result->fetch_assoc();
            $stmt->close();
            $conn->close();
            echo json_encode([
                'success' => false, 
                'message' => 'An active enquiry already exists for this email address (ID: #' . $existing['id'] . ')'
            ]);
            exit;
        }
        $stmt->close();
    }
    
    // Insert new enquiry
    $query = "INSERT INTO ten_pkv_enquiries (
                first_name, last_name, email, phone, date_of_birth, nationality,
                current_insurance, employment_status, annual_income, family_members,
                existing_conditions, additional_info, source, assigned_broker_id,
                state, created_at, updated_at
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssssssdisssi", 
        $firstName, $lastName, $email, $phone, $dateOfBirth, $nationality,
        $currentInsurance, $employmentStatus, $annualIncome, $familyMembers,
        $existingConditions, $additionalInfo, $source, $assignedBrokerId
    );
    
    $stmt->execute();
    $enquiryId = $conn->insert_id;
    $stmt->close();
    
    // Add to history
    $action = "Enquiry created";
    $note = "Initial enquiry submitted" . ($source ? " from $source" : "");
    $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                           (enquiry_id, user_id, action, note, new_state, created_at) 
                           VALUES (?, ?, ?, ?, 'new', NOW())");
    $stmt->bind_param("iiss", $enquiryId, $currentUser['id'], $action, $note);
    $stmt->execute();
    $stmt->close();
    
    // Send notification emails if broker is assigned
    if ($assignedBrokerId) {
        // Get broker details
        $stmt = $conn->prepare("SELECT company_name, email, contact_person FROM ten_pkv_brokers WHERE id = ?");
        $stmt->bind_param("i", $assignedBrokerId);
        $stmt->execute();
        $broker = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($broker && $broker['email']) {
            // Send email to broker
            $subject = "New PKV Enquiry - $firstName $lastName";
            $body = "Hello,\n\n";
            $body .= "A new PKV enquiry has been assigned to you:\n\n";
            $body .= "Enquiry ID: #$enquiryId\n";
            $body .= "Client Name: $firstName $lastName\n";
            $body .= "Email: " . ($email ?: 'Not provided') . "\n";
            $body .= "Phone: " . ($phone ?: 'Not provided') . "\n";
            $body .= "Date of Birth: " . ($dateOfBirth ?: 'Not provided') . "\n";
            $body .= "Nationality: " . ($nationality ?: 'Not provided') . "\n\n";
            if ($additionalInfo) {
                $body .= "Additional Information:\n$additionalInfo\n\n";
            }
            $body .= "Please login to the TEN Management System to view full details and contact the client.\n\n";
            $body .= SITE_URL . "/module-pkv.php\n\n";
            $body .= "Best regards,\nTEN Management System";
            
            sendEmail($broker['email'], $subject, nl2br($body));
            
            // Log email sent
            $stmt = $conn->prepare("INSERT INTO ten_pkv_history 
                                   (enquiry_id, user_id, action, note, created_at) 
                                   VALUES (?, ?, 'Email sent', ?, NOW())");
            $emailNote = "Assignment notification sent to " . $broker['company_name'];
            $stmt->bind_param("iis", $enquiryId, $currentUser['id'], $emailNote);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Send notification to admins
    $adminQuery = "SELECT DISTINCT u.email 
                  FROM ten_users u
                  LEFT JOIN ten_user_roles ur ON u.id = ur.user_id
                  LEFT JOIN ten_role_permissions rp ON ur.role_id = rp.role_id
                  LEFT JOIN ten_permissions p ON rp.permission_id = p.id
                  WHERE (u.is_admin = 1 OR p.permission_key = 'pkv_manage_all')
                  AND u.email IS NOT NULL";
    $adminEmails = $conn->query($adminQuery)->fetch_all(MYSQLI_ASSOC);
    
    foreach ($adminEmails as $admin) {
        $subject = "New PKV Enquiry Received - #$enquiryId";
        $body = "A new PKV enquiry has been received:\n\n";
        $body .= "Enquiry ID: #$enquiryId\n";
        $body .= "Client Name: $firstName $lastName\n";
        $body .= "Email: " . ($email ?: 'Not provided') . "\n";
        $body .= "Phone: " . ($phone ?: 'Not provided') . "\n";
        $body .= "Source: " . ($source ?: 'Direct') . "\n";
        if ($assignedBrokerId && isset($broker)) {
            $body .= "Assigned to: " . $broker['company_name'] . "\n";
        } else {
            $body .= "Status: Unassigned\n";
        }
        $body .= "\nView in TEN Management System:\n";
        $body .= SITE_URL . "/module-pkv.php\n";
        
        sendEmail($admin['email'], $subject, nl2br($body));
    }
    
    // Log activity
    logActivity('pkv_enquiry_created', 'pkv_enquiry', $enquiryId, 
                "Created new PKV enquiry #$enquiryId for $firstName $lastName");
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Enquiry created successfully',
        'enquiry_id' => $enquiryId
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
