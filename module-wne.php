<?php
require_once 'config.php';
requireLogin();

// Check if user has access - either super admin or has WNE project assignment
$isSuperAdmin = isAdmin();
$conn = getDBConnection();

if (!$isSuperAdmin) {
    // Check if user has any WNE project assignments
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ten_wne_project_users WHERE user_id = ?");
    $userId = $_SESSION['ten_user_id'];
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result['count'] == 0) {
        header('Location: dashboard.php?error=unauthorized');
        exit();
    }
}

// Determine view mode
$viewMode = 'admin'; // Default for super admin
$viewAsUserId = null;

if (isset($_GET['view_as']) && $isSuperAdmin) {
    $viewAsUserId = intval($_GET['view_as']);
    $viewMode = 'user_preview';
}

if (!$isSuperAdmin) {
    $viewMode = 'user';
    $viewAsUserId = $_SESSION['ten_user_id'];
}

$pageTitle = $viewMode === 'admin' ? 'WNE Recruitment Management' : 'My Recruitment Projects';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TEN Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        body { padding-bottom: 80px; }
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 32px;
        }
        .page-header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .view-mode-badge {
            padding: 8px 16px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
        }
        .tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: #6b7280;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .tab:hover {
            color: #111827;
            background: #f9fafb;
        }
        .tab.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
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
            background: #3b82f6;
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
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .btn-secondary {
            padding: 12px 24px;
            background: #6b7280;
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
        .btn-secondary:hover {
            background: #4b5563;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            background: #eff6ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            font-size: 24px;
            margin-bottom: 16px;
        }
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .stat-card .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 24px;
        }
        .project-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.2s;
            cursor: pointer;
        }
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .project-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .project-code {
            font-size: 13px;
            color: #6b7280;
            font-family: 'Courier New', monospace;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-onhold {
            background: #fef3c7;
            color: #92400e;
        }
        .status-closed {
            background: #e5e7eb;
            color: #374151;
        }
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        .project-meta {
            margin: 16px 0;
            padding: 16px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
        }
        .project-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .project-meta-item:last-child {
            margin-bottom: 0;
        }
        .project-meta-item i {
            width: 16px;
            text-align: center;
        }
        .project-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .project-stat {
            text-align: center;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
        }
        .project-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        .project-stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        .project-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }
        .btn-icon {
            padding: 8px 16px;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: #374151;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-icon:hover {
            background: #e5e7eb;
        }
        .btn-icon.primary {
            background: #2563eb;
            color: white;
        }
        .btn-icon.primary:hover {
            background: #1d4ed8;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
            padding: 40px 20px;
        }
        .modal.active {
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin: auto;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            padding: 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .close-modal:hover {
            background: #f3f4f6;
            color: #111827;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            color: #374151;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6b7280;
            margin-bottom: 24px;
        }
        .user-select-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-top: 12px;
        }
        .user-select-item {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }
        .user-select-item:hover {
            background: #f9fafb;
        }
        .user-select-item:last-child {
            border-bottom: none;
        }
        .user-info {
            display: flex;
            flex-direction: column;
        }
        .user-name {
            font-weight: 600;
            color: #111827;
        }
        .user-email {
            font-size: 13px;
            color: #6b7280;
        }
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 12px;
        }
        .permission-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .permission-item input[type="checkbox"] {
            width: auto;
        }
        .content-wrapper {
            margin-top: 30px;
            max-width: 1600px;
        }
    </style>
</head>
<body>
    <script>
        // Define functions immediately so onclick handlers work
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            const tabContent = document.getElementById('tab-' + tabName);
            if (tabContent) {
                tabContent.classList.add('active');
            }
            
            // Highlight active tab button
            if (event && event.target) {
                event.target.classList.add('active');
            }
            
            // Load content for specific tabs
            if (tabName === 'projects') loadAllProjects();
            else if (tabName === 'users') loadUsers();
            else if (tabName === 'contracts') loadContracts();
            else if (tabName === 'candidates') loadAllCandidates();
            else if (tabName === 'reports') loadReports();
        }

        function openCreateProjectModal() {
            const modal = document.getElementById('projectModal');
            if (modal) {
                modal.classList.add('active');
                const form = document.getElementById('projectForm');
                if (form) form.reset();
                const title = document.getElementById('projectModalTitle');
                if (title) title.textContent = 'Create New Project';
            }
        }

        function closeProjectModal() {
            const modal = document.getElementById('projectModal');
            if (modal) modal.classList.remove('active');
        }

        function viewProject(projectId) {
            window.location.href = 'module-wne-project.php?id=' + projectId;
        }

        function viewUserProject(projectId) {
            window.location.href = 'module-wne-project.php?id=' + projectId;
        }

        function openAssignUserModal() {
            document.getElementById('assignUserModal').classList.add('active');
            loadProjectsForAssignment();
            loadUsersForAssignment();
        }
        
        function closeAssignUserModal() {
            document.getElementById('assignUserModal').classList.remove('active');
        }
        
        function loadProjectsForAssignment() {
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_projects'
            })
            .then(response => response.json())
            .then(data => {
                const select = document.getElementById('assignProjectId');
                if (select && data.success) {
                    select.innerHTML = '<option value="">Select project...</option>';
                    data.projects.forEach(project => {
                        select.innerHTML += `<option value="${project.id}">${project.project_name} (${project.project_code})</option>`;
                    });
                }
            });
        }
        
        function loadUsersForAssignment() {
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_users'
            })
            .then(response => response.json())
            .then(data => {
                const select = document.getElementById('assignUserId');
                if (select && data.success) {
                    select.innerHTML = '<option value="">Select user...</option>';
                    data.users.forEach(user => {
                        select.innerHTML += `<option value="${user.id}">${user.name} (${user.email})</option>`;
                    });
                }
            });
        }
        
        function viewAsUser(userId) {
            window.location.href = 'module-wne.php?view_as=' + userId;
        }
        
        function removeUserFromProject(projectUserId) {
            Swal.fire({
                title: 'Remove User?',
                text: 'This will remove the user from the project',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, remove'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'remove_user_from_project');
                    formData.append('project_user_id', projectUserId);
                    
                    fetch('/management/ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Removed!', data.message, 'success');
                            loadUsers();
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    });
                }
            });
        }
        
        function viewContract(contractId) {
            Swal.fire('Coming Soon', 'Contract detail view will be implemented', 'info');
        }
        
        function editContract(contractId) {
            Swal.fire('Coming Soon', 'Contract editing will be implemented', 'info');
        }
        
        function deleteProject(projectId, projectName) {
            Swal.fire({
                title: 'Delete Project?',
                html: `This will permanently delete <strong>${projectName}</strong> and all associated candidates, contracts, and history.<br><br>This cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete permanently'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete_project');
                    formData.append('project_id', projectId);
                    
                    fetch('/management/ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: data.message,
                                confirmButtonColor: '#059669'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message,
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    });
                }
            });
        }

        function openCreateContractModal() {
            Swal.fire('Coming Soon', 'Contract creation interface will be implemented next', 'info');
        }

        function loadAllProjects() {
            const grid = document.getElementById('allProjectsGrid');
            console.log('loadAllProjects called, grid element:', grid);
            if (!grid) return;
            
            grid.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #9ca3af;"></i></div>';
            
            console.log('Fetching projects...');
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_projects'
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Projects data received:', data);
                if (data.success && data.projects.length > 0) {
                    let html = '<div class="projects-grid">';
                    data.projects.forEach(project => {
                        const statusClass = 'status-' + project.project_status.toLowerCase().replace(' ', '');
                        html += `
                        <div class="project-card" onclick="viewProject(${project.id})">
                            <div class="project-header">
                                <div>
                                    <div class="project-title">${project.project_name}</div>
                                    <div class="project-code">${project.project_code}</div>
                                </div>
                                <span class="status-badge ${statusClass}">${project.project_status}</span>
                            </div>
                            <div class="project-meta">
                                ${project.employer_company ? `<div class="project-meta-item"><i class="fas fa-building"></i><span>${project.employer_company}</span></div>` : ''}
                                ${project.position_title ? `<div class="project-meta-item"><i class="fas fa-briefcase"></i><span>${project.position_title}</span></div>` : ''}
                                <div class="project-meta-item"><i class="fas fa-user"></i><span>${project.creator_name}</span></div>
                                <div class="project-meta-item"><i class="fas fa-calendar"></i><span>${new Date(project.created_at).toLocaleDateString()}</span></div>
                            </div>
                            <div class="project-stats">
                                <div class="project-stat">
                                    <div class="project-stat-value">${project.candidate_count}</div>
                                    <div class="project-stat-label">Candidates</div>
                                </div>
                                <div class="project-stat">
                                    <div class="project-stat-value">${project.num_positions}</div>
                                    <div class="project-stat-label">Positions</div>
                                </div>
                                <div class="project-stat">
                                    <div class="project-stat-value">${project.hired_count || 0}</div>
                                    <div class="project-stat-label">Hired</div>
                                </div>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                    grid.innerHTML = html;
                } else {
                    console.log('No projects or unsuccessful response');
                    grid.innerHTML = '<div class="empty-state"><i class="fas fa-folder-open"></i><h3>No projects found</h3><p>Create your first project to get started</p></div>';
                }
            })
            .catch(error => {
                console.error('Error loading projects:', error);
                grid.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading projects</h3><p>' + error.message + '</p></div>';
            });
        }

        function loadUsers() {
            const content = document.getElementById('usersContent');
            if (!content) return;
            
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #9ca3af;"></i></div>';
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_project_users'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.users.length > 0) {
                    let html = '<div style="background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="border-bottom: 2px solid #e5e7eb;">';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">User</th>';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Project</th>';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Role</th>';
                    html += '<th style="padding: 12px; text-align: center; font-weight: 600; color: #6b7280;">Permissions</th>';
                    html += '<th style="padding: 12px; text-align: center; font-weight: 600; color: #6b7280;">Actions</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.users.forEach(user => {
                        const permissions = [];
                        if (user.can_update_status) permissions.push('Update');
                        if (user.can_add_candidates) permissions.push('Add');
                        if (user.can_download_cvs) permissions.push('Download');
                        
                        html += `<tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 12px;">
                                <div style="font-weight: 600; color: #111827;">${user.user_name}</div>
                                <div style="font-size: 13px; color: #6b7280;">${user.user_email}</div>
                            </td>
                            <td style="padding: 12px; color: #374151;">${user.project_name}</td>
                            <td style="padding: 12px;"><span style="padding: 4px 8px; background: #eff6ff; color: #1e40af; border-radius: 6px; font-size: 12px; font-weight: 600;">${user.user_role}</span></td>
                            <td style="padding: 12px; text-align: center; font-size: 13px; color: #6b7280;">${permissions.join(', ') || 'View only'}</td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="event.stopPropagation(); viewAsUser(${user.user_id})" class="btn-icon" style="margin-right: 4px;" title="View As User"><i class="fas fa-eye"></i></button>
                                <button onclick="event.stopPropagation(); removeUserFromProject(${user.id})" class="btn-icon" style="background: #fee2e2; color: #991b1b;" title="Remove"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table></div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><h3>No user assignments</h3><p>Assign users to projects to get started</p></div>';
                }
            })
            .catch(error => {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading users</h3></div>';
            });
        }

        function loadContracts() {
            const content = document.getElementById('contractsContent');
            if (!content) return;
            
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #9ca3af;"></i></div>';
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_contracts'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.contracts.length > 0) {
                    let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px;">';
                    data.contracts.forEach(contract => {
                        const statusClass = 'status-' + contract.contract_status.toLowerCase();
                        html += `
                        <div style="background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                                <div>
                                    <div style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 4px;">${contract.contract_number}</div>
                                    <div style="font-size: 13px; color: #6b7280;">${contract.project_name}</div>
                                </div>
                                <span class="status-badge ${statusClass}">${contract.contract_status}</span>
                            </div>
                            <div style="margin: 16px 0; padding: 16px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center; gap: 8px; color: #6b7280; font-size: 14px; margin-bottom: 8px;">
                                    <i class="fas fa-building" style="width: 16px;"></i><span>${contract.client_company}</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px; color: #6b7280; font-size: 14px; margin-bottom: 8px;">
                                    <i class="fas fa-file-invoice-dollar" style="width: 16px;"></i><span>${contract.currency} ${parseFloat(contract.contract_value).toLocaleString()}</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px; color: #6b7280; font-size: 14px;">
                                    <i class="fas fa-percentage" style="width: 16px;"></i><span>${contract.fee_percentage}% fee</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px; margin-top: 16px;">
                                <button onclick="viewContract(${contract.id})" class="btn-icon primary" style="flex: 1;"><i class="fas fa-eye"></i> View</button>
                                <button onclick="editContract(${contract.id})" class="btn-icon"><i class="fas fa-edit"></i></button>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<div class="empty-state"><i class="fas fa-file-contract"></i><h3>No contracts yet</h3><p>Create contracts to track agreements</p></div>';
                }
            })
            .catch(error => {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading contracts</h3></div>';
            });
        }

        function loadAllCandidates() {
            const content = document.getElementById('candidatesContent');
            if (!content) return;
            
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #9ca3af;"></i></div>';
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_candidates_overview'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.candidates.length > 0) {
                    let html = '<div style="background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">';
                    html += '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="border-bottom: 2px solid #e5e7eb;">';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Candidate</th>';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Project</th>';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Stage</th>';
                    html += '<th style="padding: 12px; text-align: left; font-weight: 600; color: #6b7280;">Submitted</th>';
                    html += '<th style="padding: 12px; text-align: center; font-weight: 600; color: #6b7280;">Actions</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.candidates.forEach(candidate => {
                        html += `<tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 12px;">
                                <div style="font-weight: 600; color: #111827;">${candidate.full_name}</div>
                                <div style="font-size: 13px; color: #6b7280;">${candidate.email || 'No email'}</div>
                            </td>
                            <td style="padding: 12px; color: #374151;">${candidate.project_name}</td>
                            <td style="padding: 12px;"><span style="padding: 4px 8px; background: #eff6ff; color: #1e40af; border-radius: 6px; font-size: 12px; font-weight: 600;">${candidate.current_stage}</span></td>
                            <td style="padding: 12px; color: #6b7280; font-size: 14px;">${new Date(candidate.submitted_at).toLocaleDateString()}</td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="viewProject(${candidate.project_id})" class="btn-icon primary"><i class="fas fa-external-link-alt"></i> View</button>
                            </td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table></div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><h3>No candidates yet</h3><p>Add candidates to projects to start tracking</p></div>';
                }
            })
            .catch(error => {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading candidates</h3></div>';
            });
        }
    </script>
    
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-wrapper" style="margin-left:30px;">
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-users-cog"></i> <?php echo $pageTitle; ?></h1>
                    <p>Professional Applicant Tracking System for Who Needs Engineers</p>
                </div>
                <?php if ($viewMode === 'user_preview'): 
                    $stmt = $conn->prepare("SELECT name FROM ten_users WHERE id = ?");
                    $stmt->bind_param("i", $viewAsUserId);
                    $stmt->execute();
                    $userName = $stmt->get_result()->fetch_assoc()['name'];
                    $stmt->close();
                ?>
                <div class="view-mode-badge">
                    <i class="fas fa-eye"></i> Previewing as: <?php echo htmlspecialchars($userName); ?>
                    <a href="module-wne.php" style="margin-left: 12px; color: #92400e;"><i class="fas fa-times"></i></a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($viewMode === 'admin'): ?>
            <!-- SUPER ADMIN VIEW -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('overview')">Overview</button>
                <button class="tab" onclick="switchTab('projects')">Projects</button>
                <button class="tab" onclick="switchTab('users')">User Management</button>
                <button class="tab" onclick="switchTab('contracts')">Contracts</button>
                <button class="tab" onclick="switchTab('candidates')">All Candidates</button>
                <button class="tab" onclick="switchTab('reports')">Reports</button>
            </div>

            <!-- Overview Tab -->
            <div id="tab-overview" class="tab-content active">
                <?php
                $conn = getDBConnection();
                
                // Get statistics
                $stats = [
                    'total_projects' => 0,
                    'active_projects' => 0,
                    'total_candidates' => 0,
                    'active_candidates' => 0,
                    'total_contracts' => 0,
                    'total_users' => 0
                ];
                
                try {
                    $result = $conn->query("SELECT COUNT(*) as count FROM ten_wne_projects");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['total_projects'] = $row['count'];
                    }
                    
                    $result = $conn->query("SELECT COUNT(*) as count FROM ten_wne_projects WHERE project_status = 'Active'");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['active_projects'] = $row['count'];
                    }
                    
                    $result = $conn->query("SELECT COUNT(*) as count FROM ten_wne_project_candidates");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['total_candidates'] = $row['count'];
                    }
                    
                    $result = $conn->query("SELECT COUNT(*) as count FROM ten_wne_project_candidates WHERE is_active = 1");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['active_candidates'] = $row['count'];
                    }
                    
                    $result = $conn->query("SELECT COUNT(*) as count FROM ten_wne_contracts");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['total_contracts'] = $row['count'];
                    }
                    
                    $result = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM ten_wne_project_users");
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $stats['total_users'] = $row['count'];
                    }
                } catch (Exception $e) {
                    error_log("WNE Module stats error: " . $e->getMessage());
                }
                ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-project-diagram"></i></div>
                        <div class="stat-value"><?php echo $stats['active_projects']; ?></div>
                        <div class="stat-label">Active Projects</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-value"><?php echo $stats['active_candidates']; ?></div>
                        <div class="stat-label">Active Candidates</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-contract"></i></div>
                        <div class="stat-value"><?php echo $stats['total_contracts']; ?></div>
                        <div class="stat-label">Total Contracts</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                        <div class="stat-label">Assigned Users</div>
                    </div>
                </div>

                <div class="actions-bar">
                    <h3 style="font-size: 20px; color: #111827;">Recent Projects</h3>
                    <button class="btn-primary" onclick="openCreateProjectModal()">
                        <i class="fas fa-plus"></i> Create Project
                    </button>
                </div>

                <?php
                $projects = [];
                try {
                    $result = $conn->query("SELECT p.*, u.full_name as creator_name,
                                             (SELECT COUNT(*) FROM ten_wne_project_candidates WHERE project_id = p.id) as candidate_count
                                             FROM ten_wne_projects p
                                             LEFT JOIN ten_users u ON p.created_by = u.id
                                             ORDER BY p.created_at DESC
                                             LIMIT 6");
                    if ($result) {
                        $projects = $result->fetch_all(MYSQLI_ASSOC);
                    } else {
                        error_log("WNE projects query failed: " . $conn->error);
                    }
                } catch (Exception $e) {
                    error_log("WNE Module projects error: " . $e->getMessage());
                }
                // Debug - comment out after testing
                // echo "<!-- Projects found: " . count($projects) . " -->";
                ?>

                <?php if (empty($projects)): ?>
                <div class="empty-state">
                    <i class="fas fa-briefcase"></i>
                    <h3>No projects yet</h3>
                    <p>Create your first recruitment project to start tracking candidates</p>
                    <button class="btn-primary" onclick="openCreateProjectModal()">
                        <i class="fas fa-plus"></i> Create First Project
                    </button>
                </div>
                <?php else: ?>
                <div class="projects-grid" id="projectsGrid">
                    <?php foreach ($projects as $project): ?>
                    <div class="project-card" onclick="viewProject(<?php echo $project['id']; ?>)">
                        <div class="project-header">
                            <div>
                                <div class="project-title"><?php echo htmlspecialchars($project['project_name']); ?></div>
                                <div class="project-code"><?php echo htmlspecialchars($project['project_code']); ?></div>
                            </div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $project['project_status'])); ?>">
                                <?php echo $project['project_status']; ?>
                            </span>
                        </div>

                        <div class="project-meta">
                            <?php if ($project['employer_company']): ?>
                            <div class="project-meta-item">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($project['employer_company']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($project['position_title']): ?>
                            <div class="project-meta-item">
                                <i class="fas fa-briefcase"></i>
                                <span><?php echo htmlspecialchars($project['position_title']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="project-meta-item">
                                <i class="fas fa-user"></i>
                                <span><?php echo htmlspecialchars($project['creator_name']); ?></span>
                            </div>
                            <div class="project-meta-item">
                                <i class="fas fa-calendar"></i>
                                <span><?php echo date('M j, Y', strtotime($project['created_at'])); ?></span>
                            </div>
                        </div>

                        <div class="project-stats">
                            <div class="project-stat">
                                <div class="project-stat-value"><?php echo $project['candidate_count']; ?></div>
                                <div class="project-stat-label">Candidates</div>
                            </div>
                            <div class="project-stat">
                                <div class="project-stat-value"><?php echo $project['num_positions']; ?></div>
                                <div class="project-stat-label">Positions</div>
                            </div>
                            <div class="project-stat">
                                <div class="project-stat-value">0</div>
                                <div class="project-stat-label">Hired</div>
                            </div>
                        </div>
                        
                        <div class="project-actions" onclick="event.stopPropagation();" style="margin-top: 16px; display: flex; gap: 8px;">
                            <button onclick="viewProject(<?php echo $project['id']; ?>)" class="btn-icon primary" style="flex: 1;">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button onclick="deleteProject(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>')" class="btn-icon" style="background: #fee2e2; color: #991b1b;" title="Delete Project">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Projects Tab -->
            <div id="tab-projects" class="tab-content">
                <div class="actions-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="projectSearch" placeholder="Search projects...">
                    </div>
                    <button class="btn-primary" onclick="openCreateProjectModal()">
                        <i class="fas fa-plus"></i> Create Project
                    </button>
                </div>
                <div id="allProjectsGrid"></div>
            </div>

            <!-- Users Tab -->
            <div id="tab-users" class="tab-content">
                <div class="actions-bar">
                    <h3 style="font-size: 20px; color: #111827;">User Management</h3>
                    <button class="btn-primary" onclick="openAssignUserModal()">
                        <i class="fas fa-user-plus"></i> Assign User to Project
                    </button>
                </div>
                <div id="usersContent"></div>
            </div>

            <!-- Contracts Tab -->
            <div id="tab-contracts" class="tab-content">
                <div class="actions-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="contractSearch" placeholder="Search contracts...">
                    </div>
                    <button class="btn-primary" onclick="openCreateContractModal()">
                        <i class="fas fa-file-contract"></i> Add Contract
                    </button>
                </div>
                <div id="contractsContent"></div>
            </div>

            <!-- Candidates Tab -->
            <div id="tab-candidates" class="tab-content">
                <div class="actions-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="candidateSearch" placeholder="Search candidates...">
                    </div>
                </div>
                <div id="candidatesContent"></div>
            </div>

            <!-- Reports Tab -->
            <div id="tab-reports" class="tab-content">
                <h3>Reports & Analytics</h3>
                <p>Coming soon...</p>
            </div>

            <?php else: ?>
            <!-- USER VIEW -->
            <?php
            $userId = $viewAsUserId ?? $_SESSION['ten_user_id'];
            
            // Get user's projects
            $stmt = $conn->prepare("SELECT p.*, pu.user_role, pu.can_update_status, pu.can_add_candidates,
                                   (SELECT COUNT(*) FROM ten_wne_project_candidates WHERE project_id = p.id AND is_active = 1) as candidate_count
                                   FROM ten_wne_project_users pu
                                   JOIN ten_wne_projects p ON pu.project_id = p.id
                                   WHERE pu.user_id = ?
                                   ORDER BY p.created_at DESC");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userProjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            ?>

            <?php if (empty($userProjects)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No projects assigned</h3>
                <p>You haven't been assigned to any recruitment projects yet</p>
            </div>
            <?php else: ?>
            <div class="projects-grid">
                <?php foreach ($userProjects as $project): ?>
                <div class="project-card" onclick="viewUserProject(<?php echo $project['id']; ?>)">
                    <div class="project-header">
                        <div>
                            <div class="project-title"><?php echo htmlspecialchars($project['project_name']); ?></div>
                            <div class="project-code"><?php echo htmlspecialchars($project['project_code']); ?></div>
                        </div>
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $project['project_status'])); ?>">
                            <?php echo $project['project_status']; ?>
                        </span>
                    </div>

                    <div class="project-meta">
                        <?php if ($project['employer_company']): ?>
                        <div class="project-meta-item">
                            <i class="fas fa-building"></i>
                            <span><?php echo htmlspecialchars($project['employer_company']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($project['position_title']): ?>
                        <div class="project-meta-item">
                            <i class="fas fa-briefcase"></i>
                            <span><?php echo htmlspecialchars($project['position_title']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="project-meta-item">
                            <i class="fas fa-user-tag"></i>
                            <span>Role: <?php echo htmlspecialchars($project['user_role']); ?></span>
                        </div>
                    </div>

                    <div class="project-stats">
                        <div class="project-stat">
                            <div class="project-stat-value"><?php echo $project['candidate_count']; ?></div>
                            <div class="project-stat-label">Active Candidates</div>
                        </div>
                        <div class="project-stat">
                            <div class="project-stat-value"><?php echo $project['can_update_status'] ? 'Yes' : 'No'; ?></div>
                            <div class="project-stat-label">Can Update</div>
                        </div>
                        <div class="project-stat">
                            <div class="project-stat-value"><?php echo $project['can_add_candidates'] ? 'Yes' : 'No'; ?></div>
                            <div class="project-stat-label">Can Add</div>
                        </div>
                    </div>

                    <div class="project-actions" onclick="event.stopPropagation();">
                        <button class="btn-icon primary" onclick="viewUserProject(<?php echo $project['id']; ?>)">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create Project Modal -->
    <div class="modal" id="projectModal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="projectModalTitle">Create New Project</span>
                <button class="close-modal" onclick="closeProjectModal()">&times;</button>
            </div>
            <form id="projectForm">
                <div class="modal-body">
                    <input type="hidden" id="projectId" name="project_id">
                    <input type="hidden" name="action" value="create_project">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Project Name *</label>
                            <input type="text" name="project_name" required>
                        </div>
                        <div class="form-group">
                            <label>Project Code (optional - auto-generated as WNE-YYYY-MM-DD)</label>
                            <input type="text" name="project_code" placeholder="Leave blank for auto-generation">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Employer Company</label>
                            <input type="text" name="employer_company">
                        </div>
                        <div class="form-group">
                            <label>Position Title</label>
                            <input type="text" name="position_title">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Position Description</label>
                        <textarea name="position_description"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location">
                        </div>
                        <div class="form-group">
                            <label>Salary Range</label>
                            <input type="text" name="salary_range" placeholder="e.g., €50,000 - €70,000">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Employment Type</label>
                            <select name="employment_type">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Temporary">Temporary</option>
                                <option value="Internship">Internship</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Number of Positions</label>
                            <input type="number" name="num_positions" value="1" min="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date">
                        </div>
                        <div class="form-group">
                            <label>Target Close Date</label>
                            <input type="date" name="target_close_date">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Required Skills</label>
                        <textarea name="required_skills" placeholder="List key skills required for this position"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeProjectModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign User Modal -->
    <div class="modal" id="assignUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Assign User to Project</span>
                <button class="close-modal" onclick="closeAssignUserModal()">&times;</button>
            </div>
            <form id="assignUserForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_user_to_project">
                    
                    <div class="form-group">
                        <label>Project *</label>
                        <select id="assignProjectId" name="project_id" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>User *</label>
                        <select id="assignUserId" name="user_id" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="user_role" required>
                            <option value="WNE Admin">WNE Admin</option>
                            <option value="WNE Recruiter">WNE Recruiter</option>
                            <option value="Employer Contact">Employer Contact</option>
                            <option value="External Recruiter">External Recruiter</option>
                            <option value="Hiring Manager">Hiring Manager</option>
                            <option value="Viewer">Viewer</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label style="margin-bottom: 12px;">Permissions</label>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" id="canUpdate" name="can_update_status" value="1">
                                <label for="canUpdate" style="margin: 0; font-weight: normal;">Can update candidate status</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" id="canAdd" name="can_add_candidates" value="1">
                                <label for="canAdd" style="margin: 0; font-weight: normal;">Can add candidates</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" id="canViewAll" name="can_view_all_candidates" value="1" checked>
                                <label for="canViewAll" style="margin: 0; font-weight: normal;">Can view all candidates</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" id="canDownload" name="can_download_cvs" value="1">
                                <label for="canDownload" style="margin: 0; font-weight: normal;">Can download CVs</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeAssignUserModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Assign User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Wait for DOM to be ready for event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal on outside click
            const projectModal = document.getElementById('projectModal');
            if (projectModal) {
                projectModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeProjectModal();
                    }
                });
            }

            // Form submission
            const projectForm = document.getElementById('projectForm');
            if (projectForm) {
                projectForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    fetch('/management/ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message || 'Project created successfully',
                                confirmButtonColor: '#059669'
                            }).then(() => {
                                closeProjectModal();
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to create project',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred',
                            confirmButtonColor: '#dc2626'
                        });
                    });
                });
            }
            
            // Assign user form submission
            const assignUserForm = document.getElementById('assignUserForm');
            if (assignUserForm) {
                assignUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    fetch('/management/ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message || 'User assigned successfully',
                                confirmButtonColor: '#059669'
                            }).then(() => {
                                closeAssignUserModal();
                                loadUsers();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to assign user',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred',
                            confirmButtonColor: '#dc2626'
                        });
                    });
                });
            }
            
            // Close modals on outside click
            const assignUserModal = document.getElementById('assignUserModal');
            if (assignUserModal) {
                assignUserModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeAssignUserModal();
                    }
                });
            }
        });
    
        function loadReports() {
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_reports_data'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const r = data.reports;
                    let html = '<div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px;"><h4>User Activity</h4><table style="width: 100%; border-collapse: collapse;"><thead><tr><th style="padding: 12px; text-align: left;">User</th><th style="padding: 12px;">Actions</th><th style="padding: 12px;">Candidates</th></tr></thead><tbody>';
                    r.user_actions.forEach(u => {
                        html += `<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 12px;"><strong>${u.full_name}</strong><br><span style="font-size: 13px; color: #6b7280;">${u.email}</span></td><td style="padding: 12px; text-align: center;">${u.total_actions}</td><td style="padding: 12px; text-align: center;">${u.candidates_added}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                    html += '<div style="background: white; border-radius: 12px; padding: 24px;"><h4>Project Activity</h4><table style="width: 100%;"><thead><tr><th style="padding: 12px; text-align: left;">Project</th><th>Candidates</th><th>Hired</th></tr></thead><tbody>';
                    r.project_activity.forEach(p => {
                        html += `<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 12px;"><strong>${p.project_name}</strong><br><span style="font-size: 13px;">${p.project_code}</span></td><td style="padding: 12px; text-align: center;">${p.total_candidates}</td><td style="padding: 12px; text-align: center;">${p.hired_count}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                    document.getElementById('reportsContainer').innerHTML = html;
                } else {
                    document.getElementById('reportsContainer').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error</h3></div>';
                }
            });
        }

    </script>
</body>
</html>
