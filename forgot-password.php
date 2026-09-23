<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } else {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT id, username, full_name FROM ten_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            $insertStmt = $conn->prepare("INSERT INTO ten_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $insertStmt->bind_param("iss", $user['id'], $token, $expiresAt);
            $insertStmt->execute();
            $insertStmt->close();
            
            // Send reset email
            $resetLink = SITE_URL . "/reset-password.php?token=" . $token;
            $emailBody = "
                <h2>Password Reset Request</h2>
                <p>Hi {$user['full_name']},</p>
                <p>We received a request to reset your password for your TEN Management account.</p>
                <p>Click the link below to reset your password:</p>
                <p><a href='$resetLink'>Reset Password</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, please ignore this email.</p>
            ";
            sendEmail($email, 'Password Reset - TEN Management', $emailBody);
            
            $success = 'Password reset link has been sent to your email address.';
        } else {
            // Don't reveal if email exists or not for security
            $success = 'If an account exists with that email, a password reset link has been sent.';
        }
        
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - TEN Management System</title>
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
        .forgot-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            padding: 48px 40px;
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo i {
            font-size: 64px;
            color: #667eea;
            margin-bottom: 16px;
        }
        .logo h1 {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .logo p {
            color: #64748b;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .links {
            text-align: center;
            margin-top: 24px;
        }
        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="logo">
            <i class="fas fa-key"></i>
            <h1>Forgot Password?</h1>
            <p>Enter your email to reset it</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required autofocus 
                       placeholder="your.email@example.com">
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>
        
        <div class="links">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</body>
</html>
