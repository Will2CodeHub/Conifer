<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

if (!hasPermission('users.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Sections and publications for the Section/Publication assignment dropdowns.
// These live in the admin_ten (news portal) database, same source the article
// editor and profile page use. Assigning them writes ten_users.section /
// ten_users.publication, which drive a journalist's article behaviour.
$allSections = [];
$allPublications = [];
try {
    $connAdmin = getDBConnection_TENAdmin();
    if ($connAdmin) {
        $secRes = $connAdmin->query("SELECT DISTINCT name FROM main_menu WHERE name != '' AND section_item = '1' AND parent_item = 0 AND id != 79 ORDER BY name");
        while ($secRes && ($r = $secRes->fetch_assoc())) { $allSections[] = $r['name']; }

        $pubRes = $connAdmin->query("SELECT publication, title FROM publications WHERE pub_live = '1' ORDER BY title");
        while ($pubRes && ($r = $pubRes->fetch_assoc())) { $allPublications[] = $r; }
        $connAdmin->close();
    }
} catch (Throwable $e) {
    error_log('module-users: failed loading sections/publications: ' . $e->getMessage());
}

// Get all users
$usersQuery = "SELECT u.*,
               (SELECT GROUP_CONCAT(r.role_name SEPARATOR ', ') 
                FROM ten_user_roles ur 
                JOIN ten_roles r ON ur.role_id = r.id 
                WHERE ur.user_id = u.id) as roles,
               (SELECT GROUP_CONCAT(ur.role_id) 
                FROM ten_user_roles ur 
                WHERE ur.user_id = u.id) as role_ids
               FROM ten_users u 
               ORDER BY u.created_at DESC";
$usersResult = $conn->query($usersQuery);
$usersArray = $usersResult->fetch_all(MYSQLI_ASSOC);
$usersResult->data_seek(0); // Reset pointer for table display

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
                        <?php foreach ($usersArray as $user): ?>
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
                                            <div class="user-name" style="color: #374151;"><?php echo htmlspecialchars($user['full_name']); ?></div>
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
                        <?php endforeach; ?>
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

                    <div class="form-group" id="inviteGroup">
                        <label class="checkbox-item" style="font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="send_invite" id="send_invite" value="1" style="width:auto;">
                            <?php echo t('users.send_invite', 'Email this user a welcome message with a link to set their password'); ?>
                        </label>
                        <small style="color:#6b7280;">They set their own password via a secure link (valid 7 days). Editorial users also receive a link to their role tutorial. You can leave the password above blank if you tick this.</small>
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
                                    <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" id="role_<?php echo $role['id']; ?>" data-role-name="<?php echo htmlspecialchars($role['role_name']); ?>">
                                    <label for="role_<?php echo $role['id']; ?>" style="margin: 0; font-weight: 500;">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('users.publications', 'Publications (TEN sites)'); ?></label>
                        <div class="checkbox-group" id="publicationGroup" style="max-height:180px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">
                            <?php foreach ($allPublications as $pub): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="publication[]" value="<?php echo htmlspecialchars($pub['publication']); ?>" id="pub_<?php echo htmlspecialchars($pub['publication']); ?>">
                                    <label for="pub_<?php echo htmlspecialchars($pub['publication']); ?>" style="margin:0;font-weight:500;">
                                        <?php echo htmlspecialchars($pub['title'] . ' (' . $pub['publication'] . ')'); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#6b7280;">Editorial roles (Journalist, Section Editor, Editor, Managing/General Editor) must be assigned at least one publication. Leave empty for non-editorial roles.</small>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('users.sections', 'Sections'); ?></label>
                        <div class="checkbox-group" id="sectionGroup" style="max-height:180px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">
                            <?php foreach ($allSections as $sectionName): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="section[]" value="<?php echo htmlspecialchars($sectionName); ?>" id="sec_<?php echo htmlspecialchars(preg_replace('/[^A-Za-z0-9]/','_',$sectionName)); ?>">
                                    <label for="sec_<?php echo htmlspecialchars(preg_replace('/[^A-Za-z0-9]/','_',$sectionName)); ?>" style="margin:0;font-weight:500;">
                                        <?php echo htmlspecialchars($sectionName); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#6b7280;">Assign section(s) to a Journalist or Section Editor to scope their articles. Leave empty to allow all sections (Editors and above see all sections in their publications).</small>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('users.byline', 'Byline'); ?></label>
                        <input type="text" name="byline" id="byline" placeholder="How their name appears on published articles">
                    </div>
                    <div class="form-group">
                        <label><?php echo t('users.bio', 'Biography'); ?></label>
                        <textarea name="bio" id="bio" rows="4" placeholder="Short biography shown on their articles"></textarea>
                    </div>

                    <div class="form-group" id="photoGroup" style="display:none;">
                        <label><?php echo t('users.photo', 'Profile / byline photo'); ?></label>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <img id="userPhotoPreview" src="" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;background:#f3f4f6;display:none;border:1px solid #e5e7eb;">
                            <input type="file" id="userPhotoFile" accept="image/*" style="flex:1;min-width:180px;">
                            <button type="button" class="action-btn btn-edit" onclick="uploadUserPhoto()"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                    </div>

                    <div class="form-group" id="adminActionsGroup" style="display:none;gap:10px;flex-wrap:wrap;">
                        <button type="button" class="action-btn btn-edit" onclick="sendResetEmail()"><i class="fas fa-key"></i> Send password reset email</button>
                        <a id="previewBylineLink" href="#" target="_blank" class="action-btn btn-edit" style="text-decoration:none;"><i class="fas fa-eye"></i> Preview byline &amp; bio</a>
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
    <script>
    // Inline JavaScript for user management
    
    // Form submission handler
    document.getElementById('userForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);

        // Editorial (TEN news) roles must have at least one publication assigned.
        const editorialRoles = ['Journalist', 'Section Editor', 'Editor', 'Managing Editor', 'General Editor'];
        const hasEditorialRole = Array.from(document.querySelectorAll('input[name="roles[]"]:checked'))
            .some(cb => editorialRoles.includes(cb.getAttribute('data-role-name')));
        const pubCount = document.querySelectorAll('input[name="publication[]"]:checked').length;
        if (hasEditorialRole && pubCount === 0) {
            Swal.fire('Publication required', 'Editorial roles (Journalist, Section Editor, Editor, Managing/General Editor) must be assigned at least one publication.', 'warning');
            return;
        }

        const submitBtn = this.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        try {
            const response = await fetch('/management/ajax/users.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    showConfirmButton: false,
                    timer: 1500
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while saving the user', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    });
    
    function editUser(userId) {
        const users = <?php echo json_encode($usersArray); ?>;
        const user = users.find(u => u.id == userId);
        
        if (!user) {
            Swal.fire('Error', 'User not found', 'error');
            return;
        }
        
        document.getElementById('modalTitle').textContent = 'Edit User';
        document.getElementById('userId').value = user.id;
        document.getElementById('fullName').value = user.full_name;
        document.getElementById('email').value = user.email;
        document.getElementById('username').value = user.username;
        document.getElementById('status').value = user.status;
        document.getElementById('formAction').value = 'update_user';

        // Tick the publication / section checkboxes from the user's stored
        // comma-separated lists.
        var userPubs = (user.publication || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
        document.querySelectorAll('input[name="publication[]"]').forEach(function(cb){ cb.checked = userPubs.includes(cb.value); });
        var userSecs = (user.section || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
        document.querySelectorAll('input[name="section[]"]').forEach(function(cb){ cb.checked = userSecs.includes(cb.value); });

        // Journalist profile: byline, bio, photo + admin actions (edit mode only).
        window._editUserId = user.id;
        document.getElementById('byline').value = user.byline || '';
        document.getElementById('bio').value = user.bio || '';
        var img = document.getElementById('userPhotoPreview');
        if (user.profile_image) { img.src = (user.profile_image.charAt(0) === '/' ? '' : '/') + user.profile_image.replace(/^\/?management\//,'/management/'); img.style.display = 'block'; }
        else { img.src = ''; img.style.display = 'none'; }
        document.getElementById('photoGroup').style.display = 'block';
        document.getElementById('adminActionsGroup').style.display = 'flex';
        document.getElementById('previewBylineLink').href = 'preview_byline.php?user_id=' + encodeURIComponent(user.id);

        document.getElementById('passwordGroup').style.display = 'none';
        document.getElementById('password').required = false;
        // Invites are for new users only.
        document.getElementById('inviteGroup').style.display = 'none';
        document.getElementById('send_invite').checked = false;

        // Uncheck all roles first
        document.querySelectorAll('input[name="roles[]"]').forEach(cb => cb.checked = false);

        // Check the roles this user has
        if (user.role_ids) {
            const roleIds = user.role_ids.split(',');
            roleIds.forEach(roleId => {
                const checkbox = document.querySelector('input[name="roles[]"][value="' + roleId.trim() + '"]');
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        }
        
        document.getElementById('userModal').classList.add('active');
    }
    
    function openCreateUserModal() {
        document.getElementById('modalTitle').textContent = 'Create User';
        document.getElementById('userForm').reset();
        document.getElementById('userId').value = '';
        document.getElementById('formAction').value = 'create_user';
        document.getElementById('passwordGroup').style.display = 'block';
        document.getElementById('password').required = true;
        document.getElementById('inviteGroup').style.display = 'block';
        document.getElementById('send_invite').checked = false;
        document.getElementById('byline').value = '';
        document.getElementById('bio').value = '';
        document.getElementById('photoGroup').style.display = 'none';
        document.getElementById('adminActionsGroup').style.display = 'none';
        window._editUserId = null;
        document.querySelectorAll('input[name="roles[]"]').forEach(cb => cb.checked = false);
        document.getElementById('userModal').classList.add('active');
    }

    async function uploadUserPhoto() {
        const uid = window._editUserId;
        const f = document.getElementById('userPhotoFile').files[0];
        if (!uid || !f) { Swal.fire('Select a photo', 'Choose an image file first.', 'info'); return; }
        const fd = new FormData();
        fd.append('action', 'admin_upload_photo');
        fd.append('user_id', uid);
        fd.append('photo', f);
        try {
            const res = await fetch('/management/ajax/users.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const img = document.getElementById('userPhotoPreview');
                img.src = (data.path || '') + '?v=' + Date.now();
                img.style.display = 'block';
                Swal.fire({ icon: 'success', title: 'Photo updated', showConfirmButton: false, timer: 1200 });
            } else { Swal.fire('Error', data.message || 'Upload failed', 'error'); }
        } catch (e) { Swal.fire('Error', 'Upload failed', 'error'); }
    }

    async function sendResetEmail() {
        const uid = window._editUserId;
        if (!uid) return;
        const c = await Swal.fire({ title: 'Send password reset email?', text: 'The user will receive a link to set a new password.', icon: 'question', showCancelButton: true, confirmButtonText: 'Send' });
        if (!c.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'send_reset');
        fd.append('user_id', uid);
        try {
            const res = await fetch('/management/ajax/users.php', { method: 'POST', body: fd });
            const data = await res.json();
            Swal.fire(data.success ? 'Sent' : 'Error', data.message || '', data.success ? 'success' : 'error');
        } catch (e) { Swal.fire('Error', 'Could not send email', 'error'); }
    }

    // When "email a set-password link" is ticked, the admin need not set a password.
    document.addEventListener('change', function(e){
        if (e.target && e.target.id === 'send_invite') {
            document.getElementById('password').required = !e.target.checked;
        }
    });
    
    function closeUserModal() {
        document.getElementById('userModal').classList.remove('active');
    }
    
    function deleteUser(userId) {
        Swal.fire({
            title: 'Delete User?',
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Yes, delete!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_user');
                    formData.append('user_id', userId);
                    
                    const response = await fetch('/management/ajax/users.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: data.message,
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'An error occurred while deleting the user', 'error');
                }
            }
        });
    }
    
    function searchUsers() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('usersTable');
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const text = rows[i].textContent || rows[i].innerText;
            rows[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
        }
    }
    
    function generatePassword() {
        const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
        let password = "";
        for (let i = 0; i < 12; i++) {
            password += charset.charAt(Math.floor(Math.random() * charset.length));
        }
        document.getElementById('password').value = password;
        document.getElementById('password').type = 'text';
        setTimeout(() => document.getElementById('password').type = 'password', 2000);
    }
    </script>
</body>
</html>
