<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!hasPermission('roles.manage') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';
$conn = getDBConnection();

try {
    switch ($action) {
        case 'create_role':
            $roleName = trim($_POST['role_name']);
            $roleKey = trim($_POST['role_key']);
            $roleDescription = trim($_POST['role_description'] ?? '');
            $roleLevel = intval($_POST['role_level']);
            
            $stmt = $conn->prepare("INSERT INTO ten_roles (role_key, role_name, role_description, role_level, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssi", $roleKey, $roleName, $roleDescription, $roleLevel);
            
            if ($stmt->execute()) {
                logActivity('create_role', 'role', $stmt->insert_id, "Created role: $roleName");
                echo json_encode(['success' => true, 'message' => 'Role created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create role']);
            }
            $stmt->close();
            break;
            
        case 'update_role':
            $roleId = intval($_POST['role_id']);
            $roleName = trim($_POST['role_name']);
            $roleDescription = trim($_POST['role_description'] ?? '');
            $roleLevel = intval($_POST['role_level']);
            
            $stmt = $conn->prepare("UPDATE ten_roles SET role_name = ?, role_description = ?, role_level = ?, updated_at = NOW() WHERE id = ? AND is_system = 0");
            $stmt->bind_param("ssii", $roleName, $roleDescription, $roleLevel, $roleId);
            
            if ($stmt->execute()) {
                logActivity('update_role', 'role', $roleId, "Updated role");
                echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update role']);
            }
            $stmt->close();
            break;
            
        case 'delete_role':
            $roleId = intval($_POST['role_id']);
            
            $stmt = $conn->prepare("DELETE FROM ten_roles WHERE id = ? AND is_system = 0");
            $stmt->bind_param("i", $roleId);
            
            if ($stmt->execute()) {
                logActivity('delete_role', 'role', $roleId, "Deleted role");
                echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete role']);
            }
            $stmt->close();
            break;
            
        case 'update_role_permissions':
            $roleId = intval($_POST['role_id']);
            $permissions = $_POST['permissions'] ?? [];
            
            $conn->query("DELETE FROM ten_role_permissions WHERE role_id = $roleId");
            
            if (!empty($permissions)) {
                $stmt = $conn->prepare("INSERT INTO ten_role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $permId) {
                    $stmt->bind_param("ii", $roleId, $permId);
                    $stmt->execute();
                }
                $stmt->close();
            }
            
            logActivity('update_permissions', 'role', $roleId, "Updated role permissions");
            echo json_encode(['success' => true, 'message' => 'Permissions updated successfully']);
            break;
            
        case 'get_role_permissions':
            $roleId = intval($_POST['role_id']);
            
            $stmt = $conn->prepare("SELECT permission_id FROM ten_role_permissions WHERE role_id = ?");
            $stmt->bind_param("i", $roleId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $permissions = [];
            while ($row = $result->fetch_assoc()) {
                $permissions[] = $row['permission_id'];
            }
            $stmt->close();
            
            echo json_encode(['success' => true, 'permissions' => $permissions]);
            break;

        case 'get_role':
            $roleId = intval($_POST['role_id']);
            
            $stmt = $conn->prepare("SELECT * FROM ten_roles WHERE id = ?");
            $stmt->bind_param("i", $roleId);
            $stmt->execute();
            $role = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($role) {
                echo json_encode(['success' => true, 'role' => $role]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Role not found']);
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
