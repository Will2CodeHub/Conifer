<?php
require_once 'config.php';
requireLogin();

if (!hasPermission('roles.manage') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Get all roles with permission counts
$rolesQuery = "SELECT r.*, 
               (SELECT COUNT(*) FROM ten_role_permissions WHERE role_id = r.id) as permission_count,
               (SELECT COUNT(*) FROM ten_user_roles WHERE role_id = r.id) as user_count
               FROM ten_roles r 
               ORDER BY r.role_level DESC";
$roles = $conn->query($rolesQuery);

// Get all permissions grouped by module
$permissionsQuery = "SELECT p.*, m.module_name 
                     FROM ten_permissions p
                     LEFT JOIN ten_modules m ON p.module_id = m.id
                     ORDER BY m.module_name, p.permission_name";
$allPermissions = $conn->query($permissionsQuery)->fetch_all(MYSQLI_ASSOC);

// Get all modules for sub-role creation
$modulesQuery = "SELECT * FROM ten_modules WHERE is_enabled = 1 ORDER BY module_name";
$allModules = $conn->query($modulesQuery)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$currentPage = 'roles';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('roles.title', 'Roles & Permissions'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
            border-bottom: 2px solid #e5e7eb;
        }
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: -2px;
        }
        .tab:hover {
            color: #667eea;
        }
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .role-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 2px solid #e5e7eb;
            transition: all 0.3s;
        }
        .role-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
            transform: translateY(-4px);
        }
        .role-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .role-name {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .role-level {
            padding: 4px 8px;
            background: #f3f4f6;
            color: #6b7280;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .role-description {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .role-stats {
            display: flex;
            gap: 16px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }
        .role-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #6b7280;
        }
        .role-stat i {
            color: #667eea;
        }
        .role-actions {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .system-badge {
            padding: 4px 8px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .permissions-section {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .permission-group {
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e5e7eb;
        }
        .permission-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .permission-group-title {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
        }
        .permission-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 12px;
        }
        .permission-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .permission-item input[type="checkbox"] {
            width: auto;
        }
        .permission-name {
            font-size: 13px;
            color: #374151;
            font-weight: 500;
        }
        .permission-type {
            padding: 2px 6px;
            background: #e5e7eb;
            color: #6b7280;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            margin-left: auto;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #9ca3af;
            cursor: pointer;
        }
        .modal-body {
            padding: 24px;
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
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }

        .checkbox-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .checkbox-item:hover {
            background: #f9fafb;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            cursor: pointer;
        }
        .checkbox-item label {
            flex: 1;
            cursor: pointer;
        }
        .btn-link {
            background: none;
            border: none;
            color: #667eea;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: none;
        }
        .btn-link:hover {
            text-decoration: underline;
        }
        .permission-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 8px;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header" style="margin-bottom: 32px;">
                <h1><i class="fas fa-user-shield"></i> <?php echo t('roles.title', 'Roles & Permissions'); ?></h1>
                <p><?php echo t('roles.subtitle', 'Manage user roles, permissions, and access control'); ?></p>
            </div>
            
            <div id="alert-container"></div>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('roles')"><?php echo t('roles.tab.roles', 'Roles'); ?></button>
                <button class="tab" onclick="switchTab('permissions')"><?php echo t('roles.tab.permissions', 'Permissions'); ?></button>
                <button class="tab" onclick="switchTab('subroles')"><?php echo t('roles.tab.subroles', 'Sub-Roles'); ?></button>
            </div>
            
            <!-- Roles Tab -->
            <div id="roles-tab" class="tab-content active">
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="openCreateRoleModal()">
                        <i class="fas fa-plus"></i>
                        <?php echo t('roles.create_role', 'Create Role'); ?>
                    </button>
                </div>
                
                <div class="roles-grid">
                    <?php while ($role = $roles->fetch_assoc()): ?>
                        <div class="role-card">
                            <div class="role-header">
                                <div>
                                    <div class="role-name">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        <?php if ($role['is_system']): ?>
                                            <span class="system-badge">System</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="role-level">Level <?php echo $role['role_level']; ?></span>
                                </div>
                            </div>
                            
                            <?php if ($role['role_description']): ?>
                                <div class="role-description">
                                    <?php echo htmlspecialchars($role['role_description']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="role-stats">
                                <div class="role-stat">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo $role['user_count']; ?> users</span>
                                </div>
                                <div class="role-stat">
                                    <i class="fas fa-key"></i>
                                    <span><?php echo $role['permission_count']; ?> permissions</span>
                                </div>
                            </div>
                            
                            <div class="role-actions">
                                <button class="btn btn-secondary" onclick="managePermissions(<?php echo $role['id']; ?>)">
                                    <i class="fas fa-key"></i>
                                    Permissions
                                </button>
                                <?php if (!$role['is_system']): ?>
                                    <button class="btn btn-secondary" onclick="editRole(<?php echo $role['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger" onclick="deleteRole(<?php echo $role['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            
            <!-- Permissions Tab -->
            <div id="permissions-tab" class="tab-content">
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="openCreatePermissionModal()">
                        <i class="fas fa-plus"></i>
                        <?php echo t('roles.create_permission', 'Create Permission'); ?>
                    </button>
                </div>
                
                <div class="permissions-section">
                    <?php
                    $groupedPermissions = [];
                    foreach ($allPermissions as $perm) {
                        $module = $perm['module_name'] ?: 'System';
                        if (!isset($groupedPermissions[$module])) {
                            $groupedPermissions[$module] = [];
                        }
                        $groupedPermissions[$module][] = $perm;
                    }
                    
                    foreach ($groupedPermissions as $module => $perms):
                    ?>
                        <div class="permission-group">
                            <div class="permission-group-title"><?php echo htmlspecialchars($module); ?></div>
                            <div class="permission-list">
                                <?php foreach ($perms as $perm): ?>
                                    <div class="permission-item">
                                        <span class="permission-name"><?php echo htmlspecialchars($perm['permission_name']); ?></span>
                                        <span class="permission-type"><?php echo strtoupper($perm['permission_type']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Sub-Roles Tab -->
            <div id="subroles-tab" class="tab-content">
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="openCreateSubroleModal()">
                        <i class="fas fa-plus"></i>
                        <?php echo t('roles.create_subrole', 'Create Sub-Role'); ?>
                    </button>
                </div>
                
                <div class="permissions-section">
                    <p style="color: #6b7280; text-align: center; padding: 40px;">
                        <?php echo t('roles.subroles_info', 'Sub-roles allow granular permissions within specific modules. For example, different editorial roles within each news portal.'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Role Modal -->
    <div class="modal" id="roleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="roleModalTitle"><?php echo t('roles.create_role', 'Create Role'); ?></h2>
                <button class="modal-close" onclick="closeRoleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" name="action" value="create_role">
                    <input type="hidden" name="role_id" id="roleId">
                    
                    <div class="form-group">
                        <label><?php echo t('roles.role_name', 'Role Name'); ?> *</label>
                        <input type="text" name="role_name" id="roleName" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('roles.role_key', 'Role Key'); ?> *</label>
                        <input type="text" name="role_key" id="roleKey" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('roles.description', 'Description'); ?></label>
                        <textarea name="role_description" id="roleDescription"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('roles.level', 'Role Level'); ?> *</label>
                        <input type="number" name="role_level" id="roleLevel" min="0" max="100" value="50" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i>
                        <?php echo t('roles.save', 'Save Role'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Permissions Modal -->
    <div class="modal" id="permissionsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('roles.manage_permissions', 'Manage Permissions'); ?></h2>
                <button class="modal-close" onclick="closePermissionsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="permissionsForm">
                    <input type="hidden" name="action" value="update_role_permissions">
                    <input type="hidden" name="role_id" id="permRoleId">
                    
                    <div id="permissionsContainer"></div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                        <i class="fas fa-save"></i>
                        <?php echo t('roles.save_permissions', 'Save Permissions'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="ajax/roles.js"></script>
</body>
</html>
