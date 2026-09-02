<?php
require_once '../config.php';
require_once '../email-helpers.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$recipients = $_POST['recipients'] ?? 'all';
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($subject) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Subject and message are required']);
    exit();
}

$conn = getDBConnection();

// Build query based on recipients
$query = "SELECT DISTINCT u.email, u.username, c.name as company_name 
          FROM users u 
          JOIN companies c ON u.company_id = c.id 
          LEFT JOIN company_subscriptions cs ON c.id = cs.company_id AND cs.is_active = 1 
          WHERE u.is_admin_user = 1 AND u.active = 1 AND c.deleted_at IS NULL";

switch ($recipients) {
    case 'active':
        $query .= " AND cs.subscription_status = 'active'";
        break;
    case 'trial':
        $query .= " AND cs.subscription_status = 'trial'";
        break;
    case 'inactive':
        $query .= " AND (cs.subscription_status = 'inactive' OR cs.subscription_status = 'suspended')";
        break;
    case 'all':
    default:
        // No additional filter
        break;
}

$result = $conn->query($query);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Database query failed']);
    $conn->close();
    exit();
}

$emailsSent = 0;
$emailsFailed = 0;

while ($user = $result->fetch_assoc()) {
    $emailBody = "Dear " . $user['username'] . ",\n\n";
    $emailBody .= $message . "\n\n";
    $emailBody .= "Best regards,\n";
    $emailBody .= "The TEN Venues Team";
    
    if (function_exists('sendEmail')) {
        if (sendEmail($user['email'], $subject, $emailBody)) {
            $emailsSent++;
        } else {
            $emailsFailed++;
        }
    } else {
        // Fallback to PHP mail
        if (mail($user['email'], $subject, $emailBody, "From: noreply@theeyenewspapers.com\r\n")) {
            $emailsSent++;
        } else {
            $emailsFailed++;
        }
    }
}

$conn->close();

if ($emailsSent > 0) {
    $responseMessage = "Message sent to $emailsSent recipient(s)";
    if ($emailsFailed > 0) {
        $responseMessage .= " ($emailsFailed failed)";
    }
    echo json_encode(['success' => true, 'message' => $responseMessage]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send messages']);
}
