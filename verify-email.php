<?php
require_once 'config.php';

$message = '';
$success = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $conn = getDBConnection();
    
    // Find user with this verification token
    $stmt = $conn->prepare("SELECT id, email FROM ten_users WHERE verification_token = ? AND email_verified = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($user) {
        // Verify email
        $updateStmt = $conn->prepare("UPDATE ten_users SET email_verified = 1, verification_token = NULL WHERE id = ?");
        $updateStmt->bind_param("i", $user['id']);
        
        if ($updateStmt->execute()) {
            $success = true;
            $message = 'Email verified successfully! You can now login once your account is activated by an administrator.';
            
            // Log activity
            $conn->query("INSERT INTO ten_activity_log (user_id, action, description, created_at) 
                         VALUES ({$user['id']}, 'email_verified', 'Email address verified', NOW())");
        } else {
            $message = 'Failed to verify email. Please try again.';
        }
        $updateStmt->close();
    } else {
        $message = 'Invalid or expired verification link.';
    }
    
    $conn->close();
} else {
    $message = 'No verification token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .verify-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
            padding: 48px 40px;
            text-align: center;
        }
        .icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 24px;
        }
        .icon.success {
            background: #d1fae5;
            color: #065f46;
        }
        .icon.error {
            background: #fee2e2;
            color: #991b1b;
        }
        h1 {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 16px;
        }
        p {
            color: #64748b;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="icon <?php echo $success ? 'success' : 'error'; ?>">
            <i class="fas fa-<?php echo $success ? 'check-circle' : 'times-circle'; ?>"></i>
        </div>
        
        <h1><?php echo $success ? 'Email Verified!' : 'Verification Failed'; ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        
        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login
        </a>
    </div>
</body>
</html>
