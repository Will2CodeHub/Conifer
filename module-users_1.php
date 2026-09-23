<?php
require_once 'config.php';
requireLogin();

if (!hasPermission('users.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Get all users
$usersQuery = "SELECT u.*, 
               (SELECT GROUP_CONCAT(r.role_name SEPARATOR ', ') 
                FROM ten_user_roles ur 
                JOIN ten_roles r ON ur.role_id = r.id 
                WHERE ur.user_id = u.id) as roles
               FROM ten_users u 
               ORDER BY u.created_at DESC";
$users = $conn->query($usersQuery);

// Get all roles for assignment
$rolesQuery = "SELECT * FROM ten_roles ORDER BY role_level DESC";
$allRoles = $conn->query($rolesQuery)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$currentPage = 'users';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('users.title', 'User Management'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .page-header {
            margin-bottom: 32px;
        }
        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .page-header p {
            color: #6b7280;
            font-size: 15px;
        }
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }
        .search-box {
            flex: 1;
            min-width: 250px;
            max-width: 400px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
        }
        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .btn-primary {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .users-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .users-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .users-table th {
            background: #f9fafb;
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .users-table td {
            padding: 16px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        .users-table tr:hover {
            background: #f9fafb;
        }
        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-avatar-table {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            overflow: hidden;
            flex-shrink: 0;
        }
        .user-avatar-table img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .user-info {
            min-width: 0;
        }
        .user-name {
            font-weight: 600;
            color: #111827;
        }
        .user-email {
            font-size: 12px;
            color: #6b7280;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-inactive { background: #e5e7eb; color: #374151; }
        .status-suspended { background: #fee2e2; color: #991b1b; }
        .role-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .role-tag {
            padding: 3px 8px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 4px;
        }
        .btn-edit {
            background: #eff6ff;
            color: #1e40af;
        }
        .btn-edit:hover {
            background: #dbeafe;
        }
        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-delete:hover {
            background: #fecaca;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
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
            max-width: 600px;
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
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-item input[type="checkbox"] {
            width: auto;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-users"></i> <?php echo t('users.title', 'User Management'); ?></h1>
                <p><?php echo t('users.subtitle', 'Manage system users, roles, and permissions'); ?></p>
            </div>
            
            <div id="alert-container"></div>
            
            <div class="actions-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="<?php echo t('users.search', 'Search users...'); ?>" onkeyup="searchUsers()">
                </div>
                <?php if (hasPermission('users.create') || isAdmin()): ?>
                    <button class="btn-primary" onclick="openCreateUserModal()">
                        <i class="fas fa-plus"></i>
                        <?php echo t('users.create_user', 'Create User'); ?>
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="users-table">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th><?php echo t('users.col.user', 'User'); ?></th>
                            <th><?php echo t('users.col.roles', 'Roles'); ?></th>
                            <th><?php echo t('users.col.status', 'Status'); ?></th>
                            <th><?php echo t('users.col.last_login', 'Last Login'); ?></th>
                            <th><?php echo t('users.col.actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-table">
                                            <?php if ($user['profile_image']): ?>
                                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="">
                                            <?php else: ?>
                                                <i class="fas fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-info">
                                            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="role-tags">
                                        <?php if ($user['roles']): ?>
                                            <?php foreach (explode(', ', $user['roles']) as $role): ?>
                                                <span class="role-tag"><?php echo htmlspecialchars($role); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af; font-size: 12px;">No roles</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $user['status']; ?>">
                                        <?php echo ucfirst($user['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                                </td>
                                <td>
                                    <?php if (hasPermission('users.edit') || isAdmin()): ?>
                                        <button class="action-btn btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ((hasPermission('users.delete') || isAdmin()) && $user['id'] != $_SESSION['ten_user_id']): ?>
                                        <button class="action-btn btn-delete" onclick="deleteUser(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Create/Edit User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><?php echo t('users.create_user', 'Create User'); ?></h2>
                <button class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" name="action" id="formAction" value="create_user">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <div class="form-group">
                        <label><?php echo t('users.full_name', 'Full Name'); ?> *</label>
                        <input type="text" name="full_name" id="fullName" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('users.email', 'Email'); ?> *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('users.username', 'Username'); ?> *</label>
                        <input type="text" name="username" id="username" required>
                    </div>
                    
                    <div class="form-group" id="passwordGroup">
                        <label><?php echo t('users.password', 'Password'); ?> *</label>
                        <input type="password" name="password" id="password">
                        <button type="button" onclick="generatePassword()" style="margin-top: 8px; font-size: 13px; color: #667eea; background: none; border: none; cursor: pointer;">
                            <i class="fas fa-key"></i> <?php echo t('users.generate_password', 'Generate Password'); ?>
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('users.status', 'Status'); ?></label>
                        <select name="status" id="status">
                            <option value="active"><?php echo t('users.status.active', 'Active'); ?></option>
                            <option value="pending"><?php echo t('users.status.pending', 'Pending'); ?></option>
                            <option value="inactive"><?php echo t('users.status.inactive', 'Inactive'); ?></option>
                            <option value="suspended"><?php echo t('users.status.suspended', 'Suspended'); ?></option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('users.roles', 'Roles'); ?></label>
                        <div class="checkbox-group">
                            <?php foreach ($allRoles as $role): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" id="role_<?php echo $role['id']; ?>">
                                    <label for="role_<?php echo $role['id']; ?>" style="margin: 0; font-weight: 500;">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i>
                        <?php echo t('users.save', 'Save User'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="ajax/users.js"></script>
</body>
</html>
