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

$data = json_decode(file_get_contents('php://input'), true);
$companyId = isset($data['company_id']) ? intval($data['company_id']) : 0;

if ($companyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid company ID']);
    exit();
}

$conn = getVenuesDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get admin user
$query = "SELECT u.id, u.email, u.username, c.name as company_name 
          FROM users u 
          JOIN companies c ON u.company_id = c.id 
          WHERE u.company_id = ? AND u.is_admin_user = 1 AND u.active = 1 
          LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Admin user not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// Generate new password
$newPassword = bin2hex(random_bytes(8));
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password
$updateQuery = "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
$stmt = $conn->prepare($updateQuery);
$stmt->bind_param("si", $passwordHash, $user['id']);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update password']);
    $stmt->close();
    $conn->close();
    exit();
}

$stmt->close();
$conn->close();

// Send password reset email with new password
$subject = 'Your Password Has Been Reset - TEN Venues';
$emailContent = "
    <h2>Hello {$user['username']}!</h2>
    <p>Your password for <strong>{$user['company_name']}</strong> has been reset by an administrator.</p>
    
    <div class='info-box'>
        <p><strong>Your New Password:</strong></p>
        <p style='font-size: 18px; font-weight: 600; color: #2563eb; letter-spacing: 2px;'>{$newPassword}</p>
    </div>
    
    <p>Please log in with this password and change it to something memorable in your account settings.</p>
    <p style='color: #6c757d; font-size: 14px;'>For security reasons, we recommend changing your password immediately after logging in.</p>
";

$emailSent = false;
if (function_exists('getEmailTemplate') && function_exists('sendEmail')) {
    $htmlContent = getEmailTemplate($emailContent, $subject);
    $emailSent = sendEmail($user['email'], $subject, $htmlContent, 'TEN Management');
}

if (!$emailSent) {
    error_log("Failed to send password reset email to: " . $user['email']);
}

echo json_encode([
    'success' => true,
    'message' => 'Password reset successfully. New password: ' . $newPassword . ($emailSent ? ' (Email sent to ' . $user['email'] . ')' : ' (Email failed - check server mail config)')
]);
