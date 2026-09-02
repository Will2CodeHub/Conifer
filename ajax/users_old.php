<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!hasPermission('users.view') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';
$conn = getDBConnection();

try {
    switch ($action) {
        case 'create_user':
            if (!hasPermission('users.create') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            
            $fullName = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $status = $_POST['status'];
            $roles = $_POST['roles'] ?? [];
            
            // Check if username/email exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ? OR email = ?");
            $checkStmt->bind_param("ss", $username, $email);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
                exit();
            }
            $checkStmt->close();
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO ten_users (username, email, full_name, password, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $username, $email, $fullName, $hashedPassword, $status);
            
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                $stmt->close();
                
                // Assign roles
                if (!empty($roles)) {
                    $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
                    foreach ($roles as $roleId) {
                        $roleStmt->bind_param("iii", $userId, $roleId, $_SESSION['ten_user_id']);
                        $roleStmt->execute();
                    }
                    $roleStmt->close();
                }
                
                // Send welcome email
                $emailBody = "<h2>Welcome to TEN Management</h2>
                             <p>Your account has been created.</p>
                             <p><strong>Username:</strong> $username</p>
                             <p><strong>Password:</strong> $password</p>
                             <p>Please login and change your password immediately.</p>";
                sendEmail($email, 'Your TEN Management Account', $emailBody);
                
                logActivity('create_user', 'user', $userId, "Created user: $username");
                
                echo json_encode(['success' => true, 'message' => 'User created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create user']);
            }
            break;
            
        case 'update_user':
            if (!hasPermission('users.edit') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            
            $userId = intval($_POST['user_id']);
            $fullName = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $status = $_POST['status'];
            $roles = $_POST['roles'] ?? [];
            
            $stmt = $conn->prepare("UPDATE ten_users SET full_name = ?, email = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $fullName, $email, $status, $userId);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // Update roles
                $conn->query("DELETE FROM ten_user_roles WHERE user_id = $userId");
                
                if (!empty($roles)) {
                    $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
                    foreach ($roles as $roleId) {
                        $roleStmt->bind_param("iii", $userId, $roleId, $_SESSION['ten_user_id']);
                        $roleStmt->execute();
                    }
                    $roleStmt->close();
                }
                
                logActivity('update_user', 'user', $userId, "Updated user");
                
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update user']);
            }
            break;
            
        case 'delete_user':
            if (!hasPermission('users.delete') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            
            $userId = intval($_POST['user_id']);
            
            if ($userId == $_SESSION['ten_user_id']) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                exit();
            }
            
            $stmt = $conn->prepare("DELETE FROM ten_users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            
            if ($stmt->execute()) {
                logActivity('delete_user', 'user', $userId, "Deleted user");
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
            }
            $stmt->close();
            break;
            
        case 'get_user':
            $userId = intval($_POST['user_id']);
            
            $stmt = $conn->prepare("SELECT u.*, GROUP_CONCAT(ur.role_id) as role_ids 
                                   FROM ten_users u 
                                   LEFT JOIN ten_user_roles ur ON u.id = ur.user_id 
                                   WHERE u.id = ? 
                                   GROUP BY u.id");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($user) {
                $user['role_ids'] = $user['role_ids'] ? explode(',', $user['role_ids']) : [];
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

$conn->close();
