<?php
/**
 * TEN Management - User Handler
 * AJAX handler for user management operations
 */

require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

// Check authorization
if (!hasPermission('users.view') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$conn = getDBConnection();
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        
        case 'get_user':
            $userId = (int)$_GET['user_id'];
            
            // Get user details
            $stmt = $conn->prepare("SELECT * FROM ten_users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user) {
                // Get user's roles
                $rolesStmt = $conn->prepare("SELECT role_id FROM ten_user_roles WHERE user_id = ?");
                $rolesStmt->bind_param('i', $userId);
                $rolesStmt->execute();
                $rolesResult = $rolesStmt->get_result();
                $roles = [];
                while ($row = $rolesResult->fetch_assoc()) {
                    $roles[] = $row['role_id'];
                }
                
                echo json_encode([
                    'success' => true,
                    'user' => $user,
                    'roles' => $roles  // Array of role IDs
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
            break;
            
        case 'create_user':
            if (!hasPermission('users.create') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            
            $fullName = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $status = $_POST['status'] ?? 'pending';
            $roles = $_POST['roles'] ?? [];
            
            // Validate
            if (empty($fullName) || empty($email) || empty($username) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                break;
            }
            
            // Check for duplicate email
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ?");
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Email already exists']);
                break;
            }
            
            // Check for duplicate username
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ?");
            $checkStmt->bind_param('s', $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username already exists']);
                break;
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("INSERT INTO ten_users (username, email, password, full_name, status, email_verified, created_at) 
                                   VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param('sssss', $username, $email, $hashedPassword, $fullName, $status);
            
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                
                // Assign roles
                if (!empty($roles)) {
                    $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) 
                                               VALUES (?, ?, ?, NOW())");
                    foreach ($roles as $roleId) {
                        $roleId = (int)$roleId;
                        $assignedBy = $_SESSION['ten_user_id'];
                        $roleStmt->bind_param('iii', $userId, $roleId, $assignedBy);
                        $roleStmt->execute();
                    }
                }
                
                logActivity($_SESSION['ten_user_id'], 'create', 'user', $userId, "Created user: $fullName");
                echo json_encode(['success' => true, 'message' => 'User created successfully', 'user_id' => $userId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create user']);
            }
            break;
            
        case 'update_user':
            if (!hasPermission('users.edit') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            
            $userId = (int)$_POST['user_id'];
            $fullName = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $username = trim($_POST['username']);
            $status = $_POST['status'] ?? 'active';
            $roles = $_POST['roles'] ?? [];
            
            // Validate
            if (empty($fullName) || empty($email) || empty($username)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                break;
            }
            
            // Check for duplicate email (excluding current user)
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ? AND id != ?");
            $checkStmt->bind_param('si', $email, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Email already exists']);
                break;
            }
            
            // Check for duplicate username (excluding current user)
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ? AND id != ?");
            $checkStmt->bind_param('si', $username, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username already exists']);
                break;
            }
            
            // Update user
            $stmt = $conn->prepare("UPDATE ten_users 
                                   SET full_name = ?, email = ?, username = ?, status = ?, updated_at = NOW() 
                                   WHERE id = ?");
            $stmt->bind_param('ssssi', $fullName, $email, $username, $status, $userId);
            
            if ($stmt->execute()) {
                // Update roles - remove old ones and add new ones
                $deleteRolesStmt = $conn->prepare("DELETE FROM ten_user_roles WHERE user_id = ?");
                $deleteRolesStmt->bind_param('i', $userId);
                $deleteRolesStmt->execute();
                
                if (!empty($roles)) {
                    $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) 
                                               VALUES (?, ?, ?, NOW())");
                    foreach ($roles as $roleId) {
                        $roleId = (int)$roleId;
                        $assignedBy = $_SESSION['ten_user_id'];
                        $roleStmt->bind_param('iii', $userId, $roleId, $assignedBy);
                        $roleStmt->execute();
                    }
                }
                
                logActivity($_SESSION['ten_user_id'], 'update', 'user', $userId, "Updated user: $fullName");
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update user']);
            }
            break;
            
        case 'delete_user':
            if (!hasPermission('users.delete') && !isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            
            $userId = (int)$_POST['user_id'];
            
            // Prevent deleting self
            if ($userId == $_SESSION['ten_user_id']) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                break;
            }
            
            // Get user name for logging
            $stmt = $conn->prepare("SELECT full_name FROM ten_users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'User not found']);
                break;
            }
            
            // Delete user (cascade will handle user_roles)
            $stmt = $conn->prepare("DELETE FROM ten_users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['ten_user_id'], 'delete', 'user', $userId, "Deleted user: " . $user['full_name']);
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("User Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

$conn->close();
?>
