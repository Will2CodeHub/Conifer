<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($username) || empty($email) || empty($fullName) || empty($password)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } else {
        $conn = getDBConnection();
        
        // Check if username or email already exists
        $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ? OR email = ?");
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        
        if ($exists) {
            $error = 'Username or email already exists';
        } else {
            // Create user account
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $verificationToken = bin2hex(random_bytes(32));
            
            $stmt = $conn->prepare("INSERT INTO ten_users (username, email, full_name, password, status, verification_token, created_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
            $stmt->bind_param("sssss", $username, $email, $fullName, $hashedPassword, $verificationToken);
            
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                $stmt->close();
                
                // Assign basic role (contributor) by default
                $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id) SELECT ?, id FROM ten_roles WHERE role_key = 'contributor'");
                $roleStmt->bind_param("i", $userId);
                $roleStmt->execute();
                $roleStmt->close();
                
                // Send verification email
                $verificationLink = SITE_URL . "/verify-email.php?token=" . $verificationToken;
                $emailBody = "
                    <h2>Welcome to TEN Management System</h2>
                    <p>Hi $fullName,</p>
                    <p>Thank you for registering. Your account has been created and is pending approval.</p>
                    <p>Please verify your email by clicking the link below:</p>
                    <p><a href='$verificationLink'>Verify Email Address</a></p>
                    <p>Once verified, an administrator will review and activate your account.</p>
                ";
                sendEmail($email, 'Verify Your Email - TEN Management', $emailBody);
                
                $success = 'Account created successfully! Please check your email to verify your address.';
            } else {
                $error = 'Failed to create account. Please try again.';
            }
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
    <title>Register - TEN Management System</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
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
        .btn-register {
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
        .btn-register:hover {
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
    <div class="register-container">
        <div class="logo">
            <i class="fas fa-user-plus"></i>
            <h1>Create Account</h1>
            <p>Join TEN Management System</p>
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
        <?php else: ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" required 
                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                       placeholder="John Doe">
            </div>
            
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       placeholder="johndoe">
            </div>
            
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       placeholder="your.email@example.com">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required 
                       placeholder="Enter password">
                <div class="password-requirements">
                    Must be at least 8 characters long
                </div>
            </div>
            
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required 
                       placeholder="Confirm password">
            </div>
            
            <button type="submit" class="btn-register">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
        
        <?php endif; ?>
        
        <div class="links">
            <a href="management-login.php">Already have an account? Sign In</a>
        </div>
    </div>
</body>
</html>
