<?php
require_once '../config.php';
require_once '../email-helpers.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$companyName = trim($_POST['company_name'] ?? '');
$adminEmail = trim($_POST['admin_email'] ?? '');
$adminName = trim($_POST['admin_name'] ?? '');
$password = $_POST['password'] ?? '';
$subscriptionType = $_POST['subscription_type'] ?? 'trial';
$sendEmail = isset($_POST['send_email']) ? intval($_POST['send_email']) : 1;

if (empty($companyName) || empty($adminEmail) || empty($adminName) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
    exit();
}

$conn = getDBConnection();

// Check if email already exists
$checkQuery = "SELECT id FROM users WHERE email = ?";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("s", $adminEmail);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email address already exists']);
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// Create URL slug
$tenUrl = strtolower(preg_replace('/[^a-z0-9]+/', '-', $companyName));
$tenUrl = trim($tenUrl, '-');

// Start transaction
$conn->begin_transaction();

try {
    // Insert company
    $insertCompanyQuery = "INSERT INTO companies (type, name, ten_url, has_dedicated_page, created_at) 
                          VALUES ('Company', ?, ?, 1, NOW())";
    $stmt = $conn->prepare($insertCompanyQuery);
    $stmt->bind_param("ss", $companyName, $tenUrl);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to create company');
    }
    
    $companyId = $conn->insert_id;
    $stmt->close();
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert admin user
    $insertUserQuery = "INSERT INTO users (company_id, username, email, password_hash, role, is_admin_user, active, created_at) 
                       VALUES (?, ?, ?, ?, 'Admin', 1, 1, NOW())";
    $stmt = $conn->prepare($insertUserQuery);
    $username = strtolower(str_replace(' ', '', $adminName));
    $stmt->bind_param("isss", $companyId, $username, $adminEmail, $passwordHash);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to create admin user');
    }
    $stmt->close();
    
    // Create subscription
    $status = ($subscriptionType === 'trial') ? 'trial' : 'active';
    $startDate = date('Y-m-d');
    $nextBillingDate = date('Y-m-d', strtotime('+1 month'));
    
    $insertSubQuery = "INSERT INTO company_subscriptions 
                      (company_id, pricing_package_id, subscription_status, start_date, next_billing_date, 
                       is_active, current_monthly_total_eur, subscribed_dedicated_brands, subscribed_dedicated_venues, 
                       subscribed_dedicated_subsidiaries, package_id, updated_at) 
                      VALUES (?, 1, ?, ?, ?, 1, 19.99, 1, 2, 0, 1, NOW())";
    $stmt = $conn->prepare($insertSubQuery);
    $stmt->bind_param("isss", $companyId, $status, $startDate, $nextBillingDate);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to create subscription');
    }
    $stmt->close();
    
    $conn->commit();
    
    // Send welcome email if requested
    if ($sendEmail && function_exists('sendWelcomeEmail')) {
        sendWelcomeEmail($adminEmail, $adminName, $companyName, $password);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Company account created successfully',
        'company_id' => $companyId
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
