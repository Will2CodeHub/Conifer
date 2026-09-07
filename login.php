<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $conn = getDBConnection();
        
        // Check login attempts
        $stmt = $conn->prepare("SELECT * FROM ten_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($user) {
            // Check if account is locked
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $error = 'Account is temporarily locked. Please try again later.';
            } elseif ($user['status'] !== 'active') {
                $error = 'Account is not active. Please contact administrator.';
            } elseif (password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['ten_user_id'] = $user['id'];
                $_SESSION['ten_username'] = $user['username'];
                $_SESSION['ten_email'] = $user['email'];
                $_SESSION['ten_full_name'] = $user['full_name'];
                $_SESSION['ten_is_admin'] = $user['is_admin'];
                $_SESSION['ten_language'] = $user['language_preference'];
                $_SESSION['ten_section'] = $user['section'];
                $_SESSION['ten_publication'] = $user['publication'];
                
                // Get user role/position from ten_user_roles table
                $roleStmt = $conn->prepare("SELECT r.role_name 
                                            FROM ten_user_roles ur 
                                            JOIN ten_roles r ON ur.role_id = r.id 
                                            WHERE ur.user_id = ? 
                                            ORDER BY r.role_level DESC 
                                            LIMIT 1");
                $roleStmt->bind_param("i", $user['id']);
                $roleStmt->execute();
                $roleResult = $roleStmt->get_result();
                
                if ($roleRow = $roleResult->fetch_assoc()) {
                    $_SESSION['ten_position'] = $roleRow['role_name'];
                } else {
                    $_SESSION['ten_position'] = 'User'; // Default position if no role found
                }
                $roleStmt->close();

                // Reset login attempts
                $resetStmt = $conn->prepare("UPDATE ten_users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
                $resetStmt->bind_param("i", $user['id']);
                $resetStmt->execute();
                $resetStmt->close();

                $conn->close();

                // Journalists only use the Article Management page — land them there,
                // not on the dashboard (which they cannot access).
                if (($_SESSION['ten_position'] ?? '') === 'Journalist') {
                    header('Location: module-articles.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit();
            } else {
                // Failed login
                $attempts = $user['login_attempts'] + 1;
                $lockedUntil = null;
                
                if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                    $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
                    $error = 'Too many failed attempts. Account locked for 15 minutes.';
                } else {
                    $error = 'Invalid email or password';
                }
                
                $updateStmt = $conn->prepare("UPDATE ten_users SET login_attempts = ?, locked_until = ? WHERE id = ?");
                $updateStmt->bind_param("isi", $attempts, $lockedUntil, $user['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
        } else {
            $error = 'Invalid email or password';
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
    <title>Login - TEN Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 420px;
            padding: 40px 32px;
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo i {
            font-size: 48px;
            color: #4f46e5;
            margin-bottom: 12px;
        }
        .logo h1 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 4px;
        }
        .logo p {
            color: #6b7280;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.15s;
            background-color: #f9fafb;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #4f46e5;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-login:hover {
            background-color: #4338ca;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .links {
            text-align: center;
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 12px;
            font-size: 14px;
        }
        .links a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 500;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .links span {
            color: #d1d5db;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <i class="fas fa-newspaper"></i>
            <h1>TEN Management</h1>
            <p>The Eye Newspapers Management System</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required autofocus 
                       placeholder="your.email@example.com">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required 
                       placeholder="Enter your password">
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div class="links">
            <a href="forgot-password.php">Forgot Password?</a>
            <span>|</span>
            <a href="register.php">Create Account</a>
        </div>
    </div>
</body>
</html>