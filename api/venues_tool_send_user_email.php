<?php
require_once '../config.php';
require_once 'venues_db_helper.php';
require_once '../email-helpers.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($userId <= 0 || empty($subject) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get user details
$query = "SELECT u.email, u.username, c.name as company_name 
          FROM users u 
          JOIN companies c ON u.company_id = c.id 
          WHERE u.id = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();
$conn->close();

// Send email
$emailContent = "
    <h2>Hello {$user['username']}!</h2>
    <p>You have received a message from TEN Management regarding <strong>{$user['company_name']}</strong>.</p>
    
    <div class='info-box'>
        <p style='white-space: pre-wrap;'>{$message}</p>
    </div>
    
    <p style='color: #6c757d; font-size: 14px;'>This message was sent from the TEN Management system. If you have questions, please reply to this email.</p>
";

$emailSent = false;
if (function_exists('getEmailTemplate') && function_exists('sendEmail')) {
    $htmlContent = getEmailTemplate($emailContent, $subject);
    $emailSent = sendEmail($user['email'], $subject, $htmlContent, 'TEN Management');
}

if ($emailSent) {
    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully to ' . $user['username']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. Check server mail configuration.'
    ]);
}
