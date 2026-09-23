<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT pr.*, u.email FROM ten_password_resets pr 
                           JOIN ten_users u ON pr.user_id = u.id 
                           WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resetRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    if ($resetRequest) {
        $validToken = true;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($password)) {
                $error = 'Please enter a password';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match';
            } else {
                $conn = getDBConnection();
                
                // Update password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE ten_users SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?");
                $updateStmt->bind_param("si", $hashedPassword, $resetRequest['user_id']);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Mark token as used
                $markStmt = $conn->prepare("UPDATE ten_password_resets SET used = 1 WHERE token = ?");
                $markStmt->bind_param("s", $token);
                $markStmt->execute();
                $markStmt->close();
                
                $conn->close();
                
                $success = 'Password has been reset successfully! You can now login.';
                $validToken = false;
            }
        }
    } else {
        $error = 'Invalid or expired reset link';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - TEN Management System</title>
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
        .reset-container {
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
            margin-bottom: 20px;
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
        .password-requirements {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo">
            <i class="fas fa-lock"></i>
            <h1>Reset Password</h1>
            <p>Enter your new password</p>
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
            <div class="links">
                <a href="login.php">Go to Login</a>
            </div>
        <?php elseif ($validToken): ?>
        
        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" required autofocus 
                       placeholder="Enter new password">
                <div class="password-requirements">
                    Must be at least 8 characters long
                </div>
            </div>
            
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required 
                       placeholder="Confirm new password">
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-check"></i> Reset Password
            </button>
        </form>
        
        <div class="links">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
        
        <?php else: ?>
            <div class="links">
                <a href="forgot-password.php">Request new reset link</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
