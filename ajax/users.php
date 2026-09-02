<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => ''];

// Check permissions
$action = $_POST['action'] ?? '';
$requiresManage = in_array($action, ['delete_user']);
$requiresEdit = in_array($action, ['update_user']);
$requiresCreate = in_array($action, ['create_user']);

if (!isAdmin()) {
    if ($requiresManage && !hasPermission('users.manage') && !hasPermission('users.delete')) {
        $response['message'] = 'Unauthorized access - requires delete permission';
        echo json_encode($response);
        exit();
    }
    if ($requiresEdit && !hasPermission('users.edit') && !hasPermission('users.manage')) {
        $response['message'] = 'Unauthorized access - requires edit permission';
        echo json_encode($response);
        exit();
    }
    if ($requiresCreate && !hasPermission('users.create') && !hasPermission('users.manage')) {
        $response['message'] = 'Unauthorized access - requires create permission';
        echo json_encode($response);
        exit();
    }
}

$conn = getDBConnection();

try {
    switch ($action) {
        case 'create_user':
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $status = sanitize($_POST['status'] ?? 'pending');
            $roles = $_POST['roles'] ?? [];
            
            // Validate required fields
            if (empty($fullName) || empty($email) || empty($username) || empty($password)) {
                throw new Exception('All required fields must be filled');
            }
            
            // Check if email already exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            $checkStmt->close();
            
            // Check if username already exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Username already exists');
            }
            $checkStmt->close();
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("INSERT INTO ten_users (username, email, password, full_name, status, email_verified, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("sssss", $username, $email, $hashedPassword, $fullName, $status);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create user: ' . $stmt->error);
            }
            
            $userId = $stmt->insert_id;
            $stmt->close();
            
            // Assign roles
            if (!empty($roles) && is_array($roles)) {
                $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                $assignedBy = $_SESSION['ten_user_id'];
                
                foreach ($roles as $roleId) {
                    $roleId = intval($roleId);
                    $roleStmt->bind_param("iii", $userId, $roleId, $assignedBy);
                    $roleStmt->execute();
                }
                $roleStmt->close();
            }
            
            // Log activity
            logActivity('user_created', 'user', $userId, "Created user: $username");
            
            $response['success'] = true;
            $response['message'] = 'User created successfully';
            $response['user_id'] = $userId;
            break;
            
        case 'update_user':
            $userId = intval($_POST['user_id'] ?? 0);
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $status = sanitize($_POST['status'] ?? 'active');
            $roles = $_POST['roles'] ?? [];
            
            if ($userId <= 0) {
                throw new Exception('Invalid user ID');
            }
            
            // Validate required fields
            if (empty($fullName) || empty($email) || empty($username)) {
                throw new Exception('All required fields must be filled');
            }
            
            // Check if email already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ? AND id != ?");
            $checkStmt->bind_param("si", $email, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            $checkStmt->close();
            
            // Check if username already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ? AND id != ?");
            $checkStmt->bind_param("si", $username, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Username already exists');
            }
            $checkStmt->close();
            
            // Update user
            $stmt = $conn->prepare("UPDATE ten_users SET username = ?, email = ?, full_name = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssi", $username, $email, $fullName, $status, $userId);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update user: ' . $stmt->error);
            }
            $stmt->close();
            
            // Update roles - delete existing and add new ones
            $deleteStmt = $conn->prepare("DELETE FROM ten_user_roles WHERE user_id = ?");
            $deleteStmt->bind_param("i", $userId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Assign new roles
            if (!empty($roles) && is_array($roles)) {
                $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                $assignedBy = $_SESSION['ten_user_id'];
                
                foreach ($roles as $roleId) {
                    $roleId = intval($roleId);
                    $roleStmt->bind_param("iii", $userId, $roleId, $assignedBy);
                    $roleStmt->execute();
                }
                $roleStmt->close();
            }
            
            // Log activity
            logActivity('user_updated', 'user', $userId, "Updated user: $username");
            
            $response['success'] = true;
            $response['message'] = 'User updated successfully';
            break;
            
        case 'delete_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if ($userId <= 0) {
                throw new Exception('Invalid user ID');
            }
            
            // Prevent deleting yourself
            if ($userId == $_SESSION['ten_user_id']) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Get username before deletion for logging
            $userStmt = $conn->prepare("SELECT username FROM ten_users WHERE id = ?");
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $username = $userStmt->get_result()->fetch_assoc()['username'] ?? 'Unknown';
            $userStmt->close();
            
            // Delete user (cascade will handle related records)
            $stmt = $conn->prepare("DELETE FROM ten_users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete user: ' . $stmt->error);
            }
            $stmt->close();
            
            // Log activity
            logActivity('user_deleted', 'user', $userId, "Deleted user: $username");
            
            $response['success'] = true;
            $response['message'] = 'User deleted successfully';
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

$conn->close();
echo json_encode($response);
