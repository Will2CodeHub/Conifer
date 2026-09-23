<?php
/**
 * TEN Management - Role Handler
 * AJAX handler for role and permission management operations
 */

require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

// Check authorization
if (!hasPermission('roles.manage') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$conn = getDBConnection();
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        
        case 'get_role':
            $roleId = (int)$_GET['role_id'];
            
            $stmt = $conn->prepare("SELECT * FROM ten_roles WHERE id = ?");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $result = $stmt->get_result();
            $role = $result->fetch_assoc();
            
            if ($role) {
                echo json_encode(['success' => true, 'role' => $role]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Role not found']);
            }
            break;
            
        case 'get_role_permissions':
            $roleId = (int)$_GET['role_id'];
            
            // Get all available permissions with module information
            $allPermsQuery = "SELECT p.id, p.permission_key, p.permission_name, 
                            p.permission_description, p.permission_type, 
                            p.module as module,
                            m.module_name 
                            FROM ten_permissions p
                            LEFT JOIN ten_modules m ON p.module_id = m.id
                            ORDER BY 
                                COALESCE(p.module, m.module_name, 'system'),
                                p.permission_name";
            $allPerms = $conn->query($allPermsQuery);
            $allPermissions = $allPerms->fetch_all(MYSQLI_ASSOC);
            
            // Get permissions assigned to this role
            $rolePermsQuery = "SELECT permission_id 
                             FROM ten_role_permissions 
                             WHERE role_id = ?";
            $stmt = $conn->prepare($rolePermsQuery);
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $result = $stmt->get_result();
            $rolePermissions = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'permissions' => $allPermissions,
                'role_permissions' => $rolePermissions
            ]);
            break;
            
        case 'create_role':
            $roleName = trim($_POST['role_name']);
            $roleKey = trim($_POST['role_key']);
            $roleDescription = trim($_POST['role_description'] ?? '');
            $roleLevel = (int)$_POST['role_level'];
            
            // Validate
            if (empty($roleName) || empty($roleKey)) {
                echo json_encode(['success' => false, 'message' => 'Role name and key are required']);
                break;
            }
            
            // Check for duplicate key
            $checkStmt = $conn->prepare("SELECT id FROM ten_roles WHERE role_key = ?");
            $checkStmt->bind_param('s', $roleKey);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Role key already exists']);
                break;
            }
            
            // Insert role
            $stmt = $conn->prepare("INSERT INTO ten_roles (role_key, role_name, role_description, role_level, is_system, created_at) 
                                   VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->bind_param('sssi', $roleKey, $roleName, $roleDescription, $roleLevel);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['ten_user_id'], 'create', 'role', $stmt->insert_id, "Created role: $roleName");
                echo json_encode(['success' => true, 'message' => 'Role created successfully', 'role_id' => $stmt->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create role']);
            }
            break;
            
        case 'update_role':
            $roleId = (int)$_POST['role_id'];
            $roleName = trim($_POST['role_name']);
            $roleKey = trim($_POST['role_key']);
            $roleDescription = trim($_POST['role_description'] ?? '');
            $roleLevel = (int)$_POST['role_level'];
            
            // Validate
            if (empty($roleName) || empty($roleKey)) {
                echo json_encode(['success' => false, 'message' => 'Role name and key are required']);
                break;
            }
            
            // Check if it's a system role
            $checkStmt = $conn->prepare("SELECT is_system FROM ten_roles WHERE id = ?");
            $checkStmt->bind_param('i', $roleId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $role = $result->fetch_assoc();
            
            if ($role && $role['is_system']) {
                echo json_encode(['success' => false, 'message' => 'Cannot modify system roles']);
                break;
            }
            
            // Check for duplicate key (excluding current role)
            $checkStmt = $conn->prepare("SELECT id FROM ten_roles WHERE role_key = ? AND id != ?");
            $checkStmt->bind_param('si', $roleKey, $roleId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Role key already exists']);
                break;
            }
            
            // Update role
            $stmt = $conn->prepare("UPDATE ten_roles 
                                   SET role_name = ?, role_key = ?, role_description = ?, 
                                       role_level = ?, updated_at = NOW() 
                                   WHERE id = ?");
            $stmt->bind_param('sssii', $roleName, $roleKey, $roleDescription, $roleLevel, $roleId);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['ten_user_id'], 'update', 'role', $roleId, "Updated role: $roleName");
                echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update role']);
            }
            break;
            
        case 'delete_role':
            $roleId = (int)$_POST['role_id'];
            
            // Check if it's a system role
            $checkStmt = $conn->prepare("SELECT is_system, role_name FROM ten_roles WHERE id = ?");
            $checkStmt->bind_param('i', $roleId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $role = $result->fetch_assoc();
            
            if (!$role) {
                echo json_encode(['success' => false, 'message' => 'Role not found']);
                break;
            }
            
            if ($role['is_system']) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete system roles']);
                break;
            }
            
            // Delete role (cascading deletes will handle role_permissions and user_roles)
            $stmt = $conn->prepare("DELETE FROM ten_roles WHERE id = ?");
            $stmt->bind_param('i', $roleId);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['ten_user_id'], 'delete', 'role', $roleId, "Deleted role: " . $role['role_name']);
                echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete role']);
            }
            break;
            
        case 'update_role_permissions':
            $roleId = (int)$_POST['role_id'];
            $permissions = $_POST['permissions'] ?? [];
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Delete existing permissions
                $deleteStmt = $conn->prepare("DELETE FROM ten_role_permissions WHERE role_id = ?");
                $deleteStmt->bind_param('i', $roleId);
                $deleteStmt->execute();
                
                // Insert new permissions
                if (!empty($permissions)) {
                    $insertStmt = $conn->prepare("INSERT INTO ten_role_permissions (role_id, permission_id, granted_at) 
                                                 VALUES (?, ?, NOW())");
                    
                    foreach ($permissions as $permId) {
                        $permId = (int)$permId;
                        $insertStmt->bind_param('ii', $roleId, $permId);
                        $insertStmt->execute();
                    }
                }
                
                $conn->commit();
                
                // Get role name for logging
                $roleStmt = $conn->prepare("SELECT role_name FROM ten_roles WHERE id = ?");
                $roleStmt->bind_param('i', $roleId);
                $roleStmt->execute();
                $roleResult = $roleStmt->get_result();
                $role = $roleResult->fetch_assoc();
                
                logActivity($_SESSION['ten_user_id'], 'update', 'role_permissions', $roleId, 
                           "Updated permissions for role: " . ($role['role_name'] ?? 'Unknown'));
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Permissions updated successfully',
                    'permission_count' => count($permissions)
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        case 'create_permission':
            $permissionKey = trim($_POST['permission_key']);
            $permissionName = trim($_POST['permission_name']);
            $module = trim($_POST['module']);
            $permissionType = trim($_POST['permission_type']);
            $description = trim($_POST['permission_description'] ?? '');
            
            // Validate
            if (empty($permissionKey) || empty($permissionName) || empty($module) || empty($permissionType)) {
                echo json_encode(['success' => false, 'message' => 'All fields except description are required']);
                break;
            }
            
            // Validate permission type
            $validTypes = ['view', 'create', 'edit', 'delete', 'manage', 'execute'];
            if (!in_array($permissionType, $validTypes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid permission type']);
                break;
            }
            
            // Check for duplicate key
            $checkStmt = $conn->prepare("SELECT id FROM ten_permissions WHERE permission_key = ?");
            $checkStmt->bind_param('s', $permissionKey);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Permission key already exists']);
                break;
            }
            
            // Insert permission
            $stmt = $conn->prepare("INSERT INTO ten_permissions 
                                   (permission_key, permission_name, permission_description, module, permission_type, is_system, created_at) 
                                   VALUES (?, ?, ?, ?, ?, 0, NOW())");
            $stmt->bind_param('sssss', $permissionKey, $permissionName, $description, $module, $permissionType);
            
            if ($stmt->execute()) {
                $permId = $stmt->insert_id;
                
                // Automatically grant to Super User (role_id = 1)
                $grantStmt = $conn->prepare("INSERT INTO ten_role_permissions (role_id, permission_id, granted_at) VALUES (1, ?, NOW())");
                $grantStmt->bind_param('i', $permId);
                $grantStmt->execute();
                
                logActivity($_SESSION['ten_user_id'], 'create', 'permission', $permId, "Created permission: $permissionName");
                echo json_encode(['success' => true, 'message' => 'Permission created successfully and granted to Super User', 'permission_id' => $permId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create permission']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Role Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

$conn->close();
?>
