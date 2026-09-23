<?php
require_once '../config.php';
requireAdmin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$conn = getDBConnection();

try {
    switch ($action) {
        case 'create_module':
            $moduleName = trim($_POST['module_name']);
            $moduleKey = trim($_POST['module_key']);
            $moduleDescription = trim($_POST['module_description'] ?? '');
            $moduleIcon = trim($_POST['module_icon'] ?? 'fa-cube');
            $moduleUrl = trim($_POST['module_url'] ?? '');
            $moduleGroup = $_POST['module_group'];
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            
            $stmt = $conn->prepare("INSERT INTO ten_modules (module_key, module_name, module_description, module_icon, module_url, module_group, is_enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssi", $moduleKey, $moduleName, $moduleDescription, $moduleIcon, $moduleUrl, $moduleGroup, $isEnabled);
            
            if ($stmt->execute()) {
                logActivity('create_module', 'module', $stmt->insert_id, "Created module: $moduleName");
                echo json_encode(['success' => true, 'message' => 'Module created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create module']);
            }
            $stmt->close();
            break;
            
        case 'update_module':
            $moduleId = intval($_POST['module_id']);
            $moduleName = trim($_POST['module_name']);
            $moduleDescription = trim($_POST['module_description'] ?? '');
            $moduleIcon = trim($_POST['module_icon'] ?? 'fa-cube');
            $moduleUrl = trim($_POST['module_url'] ?? '');
            $moduleGroup = $_POST['module_group'];
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            
            $stmt = $conn->prepare("UPDATE ten_modules SET module_name = ?, module_description = ?, module_icon = ?, module_url = ?, module_group = ?, is_enabled = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssssii", $moduleName, $moduleDescription, $moduleIcon, $moduleUrl, $moduleGroup, $isEnabled, $moduleId);
            
            if ($stmt->execute()) {
                logActivity('update_module', 'module', $moduleId, "Updated module");
                echo json_encode(['success' => true, 'message' => 'Module updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update module']);
            }
            $stmt->close();
            break;
            
        case 'toggle_module':
            $moduleId = intval($_POST['module_id']);
            
            $stmt = $conn->prepare("UPDATE ten_modules SET is_enabled = NOT is_enabled, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $moduleId);
            
            if ($stmt->execute()) {
                logActivity('toggle_module', 'module', $moduleId, "Toggled module");
                echo json_encode(['success' => true, 'message' => 'Module status updated']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update module']);
            }
            $stmt->close();
            break;
            
        case 'delete_module':
            $moduleId = intval($_POST['module_id']);
            
            $stmt = $conn->prepare("DELETE FROM ten_modules WHERE id = ? AND is_system = 0");
            $stmt->bind_param("i", $moduleId);
            
            if ($stmt->execute()) {
                logActivity('delete_module', 'module', $moduleId, "Deleted module");
                echo json_encode(['success' => true, 'message' => 'Module deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete module']);
            }
            $stmt->close();
            break;
            
        case 'create_group':
            $groupName = trim($_POST['group_name']);
            $groupKey = trim($_POST['group_key']);
            $groupDescription = trim($_POST['group_description'] ?? '');
            $groupIcon = trim($_POST['group_icon'] ?? 'fa-folder');
            
            $stmt = $conn->prepare("INSERT INTO ten_module_groups (group_key, group_name, group_description, group_icon, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $groupKey, $groupName, $groupDescription, $groupIcon);
            
            if ($stmt->execute()) {
                logActivity('create_group', 'group', $stmt->insert_id, "Created group: $groupName");
                echo json_encode(['success' => true, 'message' => 'Group created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create group']);
            }
            $stmt->close();
            break;
            
        case 'update_group':
            $groupId = intval($_POST['group_id']);
            $groupName = trim($_POST['group_name']);
            $groupDescription = trim($_POST['group_description'] ?? '');
            $groupIcon = trim($_POST['group_icon'] ?? 'fa-folder');
            
            $stmt = $conn->prepare("UPDATE ten_module_groups SET group_name = ?, group_description = ?, group_icon = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $groupName, $groupDescription, $groupIcon, $groupId);
            
            if ($stmt->execute()) {
                logActivity('update_group', 'group', $groupId, "Updated group");
                echo json_encode(['success' => true, 'message' => 'Group updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update group']);
            }
            $stmt->close();
            break;
            
        case 'delete_group':
            $groupId = intval($_POST['group_id']);
            
            $stmt = $conn->prepare("DELETE FROM ten_module_groups WHERE id = ?");
            $stmt->bind_param("i", $groupId);
            
            if ($stmt->execute()) {
                logActivity('delete_group', 'group', $groupId, "Deleted group");
                echo json_encode(['success' => true, 'message' => 'Group deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete group']);
            }
            $stmt->close();
            break;
            
        case 'get_module':
            $moduleId = intval($_POST['module_id']);
            
            $stmt = $conn->prepare("SELECT * FROM ten_modules WHERE id = ?");
            $stmt->bind_param("i", $moduleId);
            $stmt->execute();
            $module = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($module) {
                echo json_encode(['success' => true, 'module' => $module]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Module not found']);
            }
            break;
            
        case 'get_group':
            $groupId = intval($_POST['group_id']);
            
            $stmt = $conn->prepare("SELECT * FROM ten_module_groups WHERE id = ?");
            $stmt->bind_param("i", $groupId);
            $stmt->execute();
            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($group) {
                echo json_encode(['success' => true, 'group' => $group]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Group not found']);
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