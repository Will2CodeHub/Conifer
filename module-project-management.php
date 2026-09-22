<?php
require_once 'config.php';
requireLogin();

// Match the sidebar gate (ten_modules.required_permission = 'projects.view') so
// the page can't be reached by direct URL by users without the permission
// (e.g. journalists).
if (!hasPermission('projects.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$currentUser = getCurrentUser();
$userId = $_SESSION['ten_user_id'];
$isAdmin = isAdmin();

// Get user's accessible projects
if ($isAdmin) {
    $projectsQuery = "SELECT p.*, 
                     (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id) as task_count,
                     (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id AND status = 'completed') as completed_tasks,
                     (SELECT COUNT(*) FROM ten_project_members WHERE project_id = p.id) as member_count,
                     u.full_name as creator_name
                     FROM ten_projects p
                     LEFT JOIN ten_users u ON p.created_by = u.id
                     ORDER BY p.status ASC, p.priority DESC, p.created_at DESC";
    $projectsResult = $conn->query($projectsQuery);
} else {
    $projectsStmt = $conn->prepare("SELECT p.*, 
                     (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id) as task_count,
                     (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id AND status = 'completed') as completed_tasks,
                     (SELECT COUNT(*) FROM ten_project_members WHERE project_id = p.id) as member_count,
                     u.full_name as creator_name,
                     pm.role as user_role
                     FROM ten_projects p
                     INNER JOIN ten_project_members pm ON p.id = pm.project_id
                     LEFT JOIN ten_users u ON p.created_by = u.id
                     WHERE pm.user_id = ?
                     ORDER BY p.status ASC, p.priority DESC, p.created_at DESC");
    $projectsStmt->bind_param("i", $userId);
    $projectsStmt->execute();
    $projectsResult = $projectsStmt->get_result();
}

$projects = [];
while ($row = $projectsResult->fetch_assoc()) {
    $projects[] = $row;
}

$usersQuery = "SELECT id, full_name, email FROM ten_users WHERE status = 'active' ORDER BY full_name";
$allUsers = $conn->query($usersQuery)->fetch_all(MYSQLI_ASSOC);

$conn->close();
$currentPage = 'project-management';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('projects.title', 'Project Management'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt@0.6.1/dist/frappe-gantt.min.css">
    <style>
        * { box-sizing: border-box; }

        /* MOBILE RESPONSIVE */
        body { overflow-x: hidden; max-width: 100vw; }
        .main-content, .content-area { overflow-x: hidden; max-width: 100%; }
        @media (max-width: 767px) {
            .page-header { padding: 16px !important; margin-bottom: 16px !important; }
            .page-header h1 { font-size: 20px !important; }
            .tabs { padding: 6px !important; gap: 4px !important; }
            .tab { padding: 10px 14px !important; font-size: 13px !important; }
        }
        .kanban-board { display: flex; gap: 12px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .kanban-column { flex: 0 0 300px; min-width: 300px; }
        .gantt-container, .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        #gantt-chart { min-width: 800px; }
        table { min-width: 800px; }
        @media (min-width: 1200px) {
            .kanban-board { display: grid; grid-template-columns: repeat(5, 1fr); }
            .kanban-column { flex: none; min-width: unset; }
            #gantt-chart, table { min-width: unset; }
        }
        
        .page-header {
            margin-bottom: 32px;
            background: white;
            padding: 32px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .page-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #111827;
        }
        .page-header p {
            font-size: 15px;
            color: #6b7280;
            margin: 0;
        }
        
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
            background: white;
            padding: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow-x: auto;
            border: 1px solid #e5e7eb;
        }
        .tab {
            padding: 12px 24px;
            background: transparent;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .tab:hover {
            background: #f9fafb;
            color: #4b5c87;
            border-color: #e5e7eb;
        }
        .tab.active {
            background: #4b5c87;
            color: white;
            border-color: #4b5c87;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: #4b5c87;
            color: white;
            border: 1px solid #4b5c87;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            background: #3d4a6d;
            border-color: #3d4a6d;
        }
        .btn-secondary {
            padding: 10px 20px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
        }
        .btn-danger {
            padding: 10px 20px;
            background: #c53030;
            color: white;
            border: 1px solid #c53030;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-danger:hover {
            background: #9b2c2c;
            border-color: #9b2c2c;
        }
        .btn-success {
            padding: 10px 20px;
            background: #2f855a;
            color: white;
            border: 1px solid #2f855a;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-success:hover {
            background: #276749;
            border-color: #276749;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-card-icon {
            width: 48px;
            height: 48px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            color: #4b5c87;
            font-size: 24px;
        }
        .stat-card-value {
            font-size: 36px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 8px;
        }
        .stat-card-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .project-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .project-card {
            background: white;
            border: 1px solid #e5e7eb;
            padding: 24px;
            transition: all 0.2s;
            position: relative;
        }
        .project-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #4b5c87;
        }
        .project-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }
        .project-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .project-key {
            display: inline-block;
            padding: 4px 10px;
            background: #f3f4f6;
            color: #4b5c87;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            border: 1px solid #e5e7eb;
        }
        .project-description {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .project-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            padding: 16px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }
        .project-stat {
            text-align: center;
        }
        .project-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        .project-stat-label {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .project-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .project-actions {
            display: flex;
            gap: 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid;
        }
        .status-planning { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .status-active { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .status-on-hold { background: #fed7aa; color: #92400e; border-color: #fdba74; }
        .status-completed { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .status-cancelled { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .status-todo { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
        .status-in-progress { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .status-review { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .status-blocked { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .status-pending { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
        .status-submitted { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .status-approved { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .status-rejected { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .status-missed { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        
        .priority-badge {
            display: inline-block;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid;
        }
        .priority-low { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
        .priority-medium { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .priority-high { background: #fed7aa; color: #92400e; border-color: #fdba74; }
        .priority-critical { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #f3f4f6;
            color: #374151;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #e5e7eb;
        }
        .badge-key {
            background: #f3f4f6;
            color: #4b5c87;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-fill {
            height: 100%;
            background: #4b5c87;
            transition: width 0.3s ease;
        }
        .progress-fill.completed {
            background: #2f855a;
        }
        
        .task-card {
            background: white;
            border: 1px solid #e5e7eb;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.2s;
        }
        .task-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #4b5c87;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
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
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
        }
        .modal-content-large {
            max-width: 1200px;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 32px;
            color: #9ca3af;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .modal-close:hover {
            color: #4b5c87;
        }
        .modal-body {
            padding: 24px;
            max-height: calc(100vh - 200px);
            overflow-y: auto;
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
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            color: #111827;
            transition: all 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #4b5c87;
            box-shadow: 0 0 0 3px rgba(75, 92, 135, 0.1);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            padding: 12px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin-bottom: 4px;
            transition: background 0.2s;
        }
        .checkbox-item:hover {
            background: #f9fafb;
        }
        .checkbox-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
            color: #374151;
        }
        
        .filters {
            background: white;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filters-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 6px;
        }
        
        .table-container {
            background: white;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            padding: 14px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        td {
            padding: 14px 16px;
            font-size: 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        tbody tr:hover {
            background: #f9fafb;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        .pagination {
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: white;
            border-top: 1px solid #e5e7eb;
        }
        
        .kanban-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            padding: 20px 0;
        }
        .kanban-column {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            min-height: 500px;
        }
        .kanban-header {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .kanban-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kanban-count {
            background: #4b5c87;
            color: white;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 700;
        }
        .kanban-body {
            padding: 16px;
        }
        .kanban-task {
            background: white;
            border: 1px solid #e5e7eb;
            padding: 16px;
            margin-bottom: 12px;
            cursor: move;
            transition: all 0.2s;
        }
        .kanban-task:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #4b5c87;
        }
        .kanban-task-title {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }
        .kanban-task-meta {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .gantt-container {
            background: white;
            border: 1px solid #e5e7eb;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .gantt-controls {
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .project-detail-tabs {
            display: flex;
            gap: 8px;
            padding: 0 24px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }
        .project-detail-tab {
            padding: 16px 20px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }
        .project-detail-tab:hover {
            color: #4b5c87;
            border-bottom-color: #e5e7eb;
        }
        .project-detail-tab.active {
            color: #4b5c87;
            border-bottom-color: #4b5c87;
        }
        .project-tab-content {
            display: none;
            padding: 24px;
        }
        .project-tab-content.active {
            display: block;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        .empty-state p {
            color: #9ca3af;
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .project-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .kanban-board {
                grid-template-columns: 1fr;
            }
        }
        
        /* Alert/notification styling with high z-index */
        .custom-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.3s ease-out;
            max-width: 400px;
        }
        
        .custom-alert.success {
            background: #10b981;
            color: white;
        }
        
        .custom-alert.error {
            background: #ef4444;
            color: white;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header">
                <h1>
                    <i class="fas fa-project-diagram"></i>
                    <?php echo t('projects.title', 'Project Management'); ?>
                </h1>
                <p><?php echo t('projects.subtitle', 'Manage projects, tasks, milestones and track progress'); ?></p>
            </div>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('overview')">
                    <i class="fas fa-chart-line"></i> Overview
                </button>
                <button class="tab" onclick="switchTab('projects')">
                    <i class="fas fa-folder"></i> Projects
                </button>
                <button class="tab" onclick="switchTab('my-tasks')">
                    <i class="fas fa-user-check"></i> My Tasks
                </button>
                <button class="tab" onclick="switchTab('all-tasks')">
                    <i class="fas fa-tasks"></i> All Tasks
                </button>
                <button class="tab" onclick="switchTab('kanban')">
                    <i class="fas fa-columns"></i> Kanban Board
                </button>
                <button class="tab" onclick="switchTab('gantt')">
                    <i class="fas fa-chart-gantt"></i> Gantt Chart
                </button>
            </div>
            
            <!-- Overview Tab -->
            <div id="overview-tab" class="tab-content active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <div class="stat-card-value" id="total-projects">0</div>
                        <div class="stat-card-label">Total Projects</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-card-value" id="total-tasks">0</div>
                        <div class="stat-card-label">Total Tasks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-card-value" id="completed-tasks">0</div>
                        <div class="stat-card-label">Completed Tasks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-card-value" id="overdue-tasks">0</div>
                        <div class="stat-card-label">Overdue Tasks</div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px; margin-bottom: 24px;">
                    <div style="background: white; padding: 24px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 20px 0;">
                            <i class="fas fa-chart-pie"></i> Tasks by Status
                        </h3>
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div style="background: white; padding: 24px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 20px 0;">
                            <i class="fas fa-exclamation-circle"></i> Tasks by Priority
                        </h3>
                        <canvas id="priorityChart"></canvas>
                    </div>
                </div>
                
                <div style="background: white; padding: 24px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 20px 0;">
                        <i class="fas fa-users"></i> Member Activity
                    </h3>
                    <canvas id="memberActivityChart"></canvas>
                </div>
            </div>
            
            <!-- Projects Tab -->
            <div id="projects-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">All Projects</h2>
                    <button class="btn-primary" onclick="openCreateProjectModal()">
                        <i class="fas fa-plus"></i> Create Project
                    </button>
                </div>
                <div id="projects-list" class="project-grid"></div>
            </div>
            
            <!-- My Tasks Tab -->
            <div id="my-tasks-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">My Tasks</h2>
                </div>
                
                <div class="filters">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label>Status</label>
                            <select id="task-filter-status" class="form-control" onchange="loadMyTasks()">
                                <option value="all">All Status</option>
                                <option value="todo">To Do</option>
                                <option value="in-progress">In Progress</option>
                                <option value="review">Review</option>
                                <option value="completed">Completed</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Project</label>
                            <select id="task-filter-project" class="form-control" onchange="loadMyTasks()">
                                <option value="all">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Priority</label>
                            <select id="task-filter-priority" class="form-control" onchange="loadMyTasks()">
                                <option value="all">All Priority</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filters-row" style="margin-top: 12px;">
                        <div class="filter-group">
                            <label>Date Filter Type</label>
                            <select id="task-filter-date-type" class="form-control" onchange="loadMyTasks()">
                                <option value="">No Date Filter</option>
                                <option value="created">Created Date</option>
                                <option value="start">Start Date</option>
                                <option value="due">Due Date</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>From Date</label>
                            <input type="date" id="task-filter-date-from" class="form-control" onchange="loadMyTasks()">
                        </div>
                        <div class="filter-group">
                            <label>To Date</label>
                            <input type="date" id="task-filter-date-to" class="form-control" onchange="loadMyTasks()">
                        </div>
                        <div class="filter-group" style="display: flex; align-items: flex-end;">
                            <button onclick="clearMyTasksFilters()" class="btn-secondary" style="width: 100%;">
                                <i class="fas fa-times"></i> Clear Filters
                            </button>
                        </div>
                    </div>
                </div>
                
                <div id="tasks-list"></div>
            </div>
            
            <!-- All Tasks Tab -->
            <div id="all-tasks-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">All Tasks</h2>
                </div>
                
                <div class="filters">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" id="all-tasks-search" class="form-control" placeholder="Search tasks..." onkeyup="loadAllTasks()">
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select id="all-tasks-status-filter" class="form-control" onchange="loadAllTasks()">
                                <option value="all">All Status</option>
                                <option value="todo">To Do</option>
                                <option value="in-progress">In Progress</option>
                                <option value="review">Review</option>
                                <option value="completed">Completed</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Priority</label>
                            <select id="all-tasks-priority-filter" class="form-control" onchange="loadAllTasks()">
                                <option value="all">All Priorities</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Due Date</th>
                                <th>Progress</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="all-tasks-tbody"></tbody>
                    </table>
                </div>
                <div class="pagination" id="all-tasks-pagination"></div>
            </div>
            
            <!-- Kanban Board Tab -->
            <div id="kanban-tab" class="tab-content">
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0 0 16px 0;">Kanban Board</h2>
                </div>
                
                <div class="filters">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label>Project</label>
                            <select id="kanban-project-filter" class="form-control" onchange="filterKanban()">
                                <option value="all">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Priority</label>
                            <select id="kanban-priority-filter" class="form-control" onchange="filterKanban()">
                                <option value="all">All Priority</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div id="kanban-board" class="kanban-board"></div>
            </div>
            
            
            <!-- Gantt Chart Tab -->
            <div id="gantt-tab" class="tab-content">
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0 0 16px 0;">Gantt Chart</h2>
                </div>
                
                <div class="filters">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label>Project</label>
                            <select id="gantt-project-filter" class="form-control" onchange="filterGantt()">
                                <option value="all">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select id="gantt-status-filter" class="form-control" onchange="filterGantt()">
                                <option value="all">All Status</option>
                                <option value="todo">To Do</option>
                                <option value="in-progress">In Progress</option>
                                <option value="review">Review</option>
                                <option value="completed">Completed</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="gantt-container">
                    <div class="gantt-controls">
                        <button class="btn-secondary gantt-view-btn" onclick="changeGanttView('Quarter Day')">Quarter Day</button>
                        <button class="btn-secondary gantt-view-btn" onclick="changeGanttView('Half Day')">Half Day</button>
                        <button class="btn-secondary gantt-view-btn" onclick="changeGanttView('Day')">Day</button>
                        <button class="btn-secondary gantt-view-btn active" onclick="changeGanttView('Week')">Week</button>
                        <button class="btn-secondary gantt-view-btn" onclick="changeGanttView('Month')">Month</button>
                    </div>
                    <div id="gantt-chart"></div>
                </div>
            </div>
            </div>
        </div>
    </div>
    
    <!-- Project Modal -->
    <div class="modal" id="projectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="projectModalTitle">Create Project</h2>
                <button class="modal-close" onclick="closeProjectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="projectForm">
                    <input type="hidden" name="action" value="create_project">
                    <input type="hidden" name="project_id" id="projectId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Project Name *</label>
                            <input type="text" name="project_name" id="projectName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Project Key * (2-10 uppercase letters)</label>
                            <input type="text" name="project_key" id="projectKey" class="form-control" required pattern="[A-Z]{2,10}" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="projectDescription" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="projectStatus" class="form-control">
                                <option value="planning">Planning</option>
                                <option value="active" selected>Active</option>
                                <option value="on-hold">On Hold</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" id="projectPriority" class="form-control">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="projectStartDate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" id="projectEndDate" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Budget</label>
                        <input type="number" name="budget" id="projectBudget" class="form-control" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label>Team Members</label>
                        <div class="checkbox-group">
                            <?php foreach ($allUsers as $user): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" name="members[]" value="<?php echo $user['id']; ?>" id="member-<?php echo $user['id']; ?>">
                                <label for="member-<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['email']); ?>)</label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Save Project
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Project Detail Modal -->
    <div class="modal" id="projectDetailModal">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <div>
                    <h2 id="projectDetailTitle">Project Name</h2>
                    <span id="projectDetailKey" class="project-key">KEY</span>
                </div>
                <button class="modal-close" onclick="closeProjectDetailModal()">&times;</button>
            </div>
            
            <div class="project-detail-tabs">
                <button class="project-detail-tab active" onclick="switchProjectTab('overview')">Overview</button>
                <button class="project-detail-tab" onclick="switchProjectTab('tasks')">Tasks</button>
                <button class="project-detail-tab" onclick="switchProjectTab('gantt')">Gantt</button>
                <button class="project-detail-tab" onclick="switchProjectTab('kanban')">Kanban</button>
                <button class="project-detail-tab" onclick="switchProjectTab('activity')">Activity</button>
                <button class="project-detail-tab" onclick="switchProjectTab('notes')">Notes</button>
            </div>
            
            <div id="project-overview-tab" class="project-tab-content active">
                <div class="stats-grid" style="margin-bottom: 24px;">
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-tasks"></i></div>
                        <div class="stat-card-value" id="detail-total-tasks">0</div>
                        <div class="stat-card-label">Total Tasks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-card-value" id="detail-completed-tasks">0</div>
                        <div class="stat-card-label">Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-card-value" id="detail-total-members">0</div>
                        <div class="stat-card-label">Team Members</div>
                    </div>
                </div>
                <div id="project-overview-info"></div>
                <div style="margin-top: 24px;">
                    <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 16px 0;">Milestones</h3>
                    <div id="project-milestones-list"></div>
                </div>
            </div>
            
            <div id="project-tasks-tab" class="project-tab-content">
                <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                    <h3 style="margin: 0;">Project Tasks</h3>
                    <button class="btn-primary" onclick="openCreateTaskModal(currentProjectId)">
                        <i class="fas fa-plus"></i> Add Task
                    </button>
                </div>
                
                <div class="tabs" style="margin-bottom: 20px;">
                    <div class="tab active" onclick="switchTaskStatusTab('todo')">
                        <i class="fas fa-list"></i> To Do
                    </div>
                    <div class="tab" onclick="switchTaskStatusTab('in-progress')">
                        <i class="fas fa-spinner"></i> In Progress
                    </div>
                    <div class="tab" onclick="switchTaskStatusTab('review')">
                        <i class="fas fa-eye"></i> Review
                    </div>
                    <div class="tab" onclick="switchTaskStatusTab('completed')">
                        <i class="fas fa-check-circle"></i> Completed
                    </div>
                    <div class="tab" onclick="switchTaskStatusTab('blocked')">
                        <i class="fas fa-ban"></i> Blocked
                    </div>
                    <div class="tab" onclick="switchTaskStatusTab('all')">
                        <i class="fas fa-tasks"></i> All
                    </div>
                </div>
                
                <div id="project-tasks-list"></div>
            </div>
            
            <div id="project-kanban-tab" class="project-tab-content">
                <div id="project-kanban-board" class="kanban-board"></div>
            </div>
            
            <div id="project-gantt-tab" class="project-tab-content">
                <div id="project-gantt-chart"></div>
            </div>
            
            
            <div id="project-activity-tab" class="project-tab-content">
                <div style="margin-bottom: 20px;">
                    <h3 style="margin: 0 0 16px 0;">Project Activity</h3>
                    
                    <div class="filters">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label>Activity Type</label>
                                <select id="activity-type-filter" class="form-control" onchange="loadProjectActivity(currentProjectId)">
                                    <option value="all">All Activities</option>
                                    <option value="task_created">Task Created</option>
                                    <option value="task_updated">Task Updated</option>
                                    <option value="task_assigned">Task Assigned</option>
                                    <option value="task_completed">Task Completed</option>
                                    <option value="project_created">Project Created</option>
                                    <option value="project_updated">Project Updated</option>
                                    <option value="milestone_created">Milestone Created</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>User</label>
                                <select id="activity-user-filter" class="form-control" onchange="loadProjectActivity(currentProjectId)">
                                    <option value="all">All Users</option>
                                    <?php foreach ($allUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>From Date</label>
                                <input type="date" id="activity-date-from" class="form-control" onchange="loadProjectActivity(currentProjectId)">
                            </div>
                            <div class="filter-group">
                                <label>To Date</label>
                                <input type="date" id="activity-date-to" class="form-control" onchange="loadProjectActivity(currentProjectId)">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="project-activity-list"></div>
            </div>

            <!-- Notes tab (project notes + conversations); populated by js/project_notes.js -->
            <div id="project-notes-tab" class="project-tab-content"></div>
        </div>
    </div>

    <!-- Task Modal -->
    <div class="modal" id="taskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="taskModalTitle">Create Task</h2>
                <button class="modal-close" onclick="closeTaskModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="taskForm">
                    <input type="hidden" name="action" value="create_task">
                    <input type="hidden" name="task_id" id="taskId">
                    <input type="hidden" name="project_id" id="taskProjectId">
                    
                    <div class="form-group">
                        <label>Task Name *</label>
                        <input type="text" name="task_name" id="taskName" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="taskDescription" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="taskStatus" class="form-control">
                                <option value="todo" selected>To Do</option>
                                <option value="in-progress">In Progress</option>
                                <option value="review">Review</option>
                                <option value="completed">Completed</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" id="taskPriority" class="form-control">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Milestone</label>
                            <select name="milestone_id" id="taskMilestone" class="form-control">
                                <option value="">No Milestone</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Assign To</label>
                            <select name="assigned_to" id="taskAssignedTo" class="form-control">
                                <option value="">Unassigned</option>
                                <?php foreach ($allUsers as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" id="hasStartDate" onchange="toggleStartDate()">
                            <span>Set Start Date</span>
                        </label>
                    </div>
                    
                    <div class="form-row" id="startDateRow" style="display: none;">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="taskStartDate" class="form-control">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" id="hasDueDate" onchange="toggleDueDate()">
                            <span>Set Due Date</span>
                        </label>
                    </div>
                    
                    <div class="form-row" id="dueDateRow" style="display: none;">
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date" id="taskDueDate" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Estimated Hours</label>
                            <input type="number" name="estimated_hours" id="taskEstimatedHours" class="form-control" step="0.5" min="0">
                        </div>
                        <div class="form-group">
                            <label>Actual Hours</label>
                            <input type="number" name="actual_hours" id="taskActualHours" class="form-control" step="0.5" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Progress (%)</label>
                        <input type="number" name="progress" id="taskProgress" class="form-control" min="0" max="100" value="0">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Save Task
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Milestone Modal -->
    <div class="modal" id="milestoneModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="milestoneModalTitle">Create Milestone</h2>
                <button class="modal-close" onclick="closeMilestoneModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="milestoneForm">
                    <input type="hidden" name="action" value="create_milestone">
                    <input type="hidden" name="milestone_id" id="milestoneId">
                    <input type="hidden" name="project_id" id="milestoneProjectId">
                    
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="milestone_name" id="milestoneName" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="milestoneDescription" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Type</label>
                            <select name="milestone_type" id="milestoneType" class="form-control">
                                <option value="milestone" selected>Milestone</option>
                                <option value="gate">Gate</option>
                                <option value="deliverable">Deliverable</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Due Date *</label>
                            <input type="date" name="due_date" id="milestoneDueDate" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="milestoneStatus" class="form-control">
                            <option value="pending" selected>Pending</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="missed">Missed</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Save
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="/management/ajax/project_management.js?v=<?php echo time(); ?>"></script>
    <script src="/management/js/project_notes.js?v=<?php echo time(); ?>"></script>
    <script>
        const projectsData = <?php echo json_encode($projects); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing project management...');
            loadOverview();
            loadProjects();
            loadMyTasks();
            loadAllTasks();
        });
        
        function switchTab(tabName) {
            console.log('Switching to tab:', tabName);
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            const tabContent = document.getElementById(`${tabName}-tab`);
            if (tabContent) {
                tabContent.classList.add('active');
            }
            
            if (tabName === 'overview') {
                loadOverview();
            } else if (tabName === 'projects') {
                loadProjects();
            } else if (tabName === 'my-tasks') {
                loadMyTasks();
            } else if (tabName === 'all-tasks') {
                loadAllTasks();
            } else if (tabName === 'kanban') {
                filterKanban();
            } else if (tabName === 'gantt') {
                filterGantt();
            }
        }
        
        function filterKanban() {
            const projectFilter = document.getElementById('kanban-project-filter')?.value || 'all';
            const priorityFilter = document.getElementById('kanban-priority-filter')?.value || 'all';
            
            const params = new URLSearchParams({ 
                action: 'get_all_tasks', 
                status: 'all'
            });
            
            if (projectFilter !== 'all') {
                params.append('project_id', projectFilter);
            }
            if (priorityFilter !== 'all') {
                params.append('priority', priorityFilter);
            }
            
            fetch('/management/ajax/project_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                console.log('Kanban data:', data);
                if (data.success && data.tasks) {
                    displayKanbanBoard(data.tasks);
                } else {
                    console.error('Kanban failed:', data);
                }
            })
            .catch(error => console.error('Kanban error:', error));
        }
        
        function filterGantt() {
            console.log('=== filterGantt called ===');
            const projectFilter = document.getElementById('gantt-project-filter')?.value || 'all';
            const statusFilter = document.getElementById('gantt-status-filter')?.value || 'all';
            console.log('Filters - Project:', projectFilter, 'Status:', statusFilter);
            
            const params = new URLSearchParams({ 
                action: 'get_all_tasks', 
                status: statusFilter
            });
            
            if (projectFilter !== 'all') {
                params.append('project_id', projectFilter);
            }
            
            console.log('Fetching with params:', params.toString());
            
            fetch('/management/ajax/project_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(response => {
                console.log('Response received:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Gantt data received:', data);
                console.log('Task count:', data.tasks ? data.tasks.length : 0);
                
                if (data.success && data.tasks) {
                    console.log('Calling renderGanttChart with', data.tasks.length, 'tasks');
                    console.log('Container ID: gantt-chart');
                    console.log('Container exists?', document.getElementById('gantt-chart') !== null);
                    
                    if (typeof renderGanttChart === 'function') {
                        console.log('renderGanttChart function exists, calling it...');
                        renderGanttChart(data.tasks, 'gantt-chart');
                        console.log('renderGanttChart called');
                    } else {
                        console.error('renderGanttChart function NOT FOUND!');
                    }
                } else {
                    console.error('Gantt failed - success:', data.success, 'tasks:', data.tasks);
                }
            })
            .catch(error => {
                console.error('Gantt fetch error:', error);
            });
        }
        
        function showAlert(type, message) {
            Swal.fire({
                icon: type,
                title: type === 'success' ? 'Success!' : 'Error!',
                text: message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
        }
    </script>
</body>
</html>
