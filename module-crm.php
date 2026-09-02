<?php
require_once 'config.php';
requireLogin();
if (!hasModulePermission('crm.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}
$currentUser = getCurrentUser();
$currentPage = 'crm';
$canManage = hasModulePermission('crm.manage') || isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        :root { --primary:#667eea; --primary-dark:#5a6fd6; --bg:#f8fafc; --card:#fff; --border:#e5e7eb; --text:#111827; --muted:#6b7280; }
        body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); }
        /* ── Layout ── */
        .crm-wrapper { display:flex; height:calc(100vh - 64px); overflow:hidden; }
        .crm-sidebar { width:280px; min-width:280px; background:var(--card); border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; }
        .crm-main { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        /* ── Page Header ── */
        .page-header { padding:20px 28px 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .page-header h1 { font-size:26px; font-weight:700; color:var(--text); margin:0; }
        .breadcrumb { font-size:13px; color:var(--muted); margin-top:2px; }
        .header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        /* ── Stats Bar ── */
        .stats-bar { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; padding:16px 28px; }
        .stat-card { background:var(--card); border-radius:12px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,.08); display:flex; align-items:center; gap:14px; }
        .stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; }
        .stat-label { font-size:12px; color:var(--muted); font-weight:500; }
        .stat-value { font-size:22px; font-weight:700; color:var(--text); line-height:1.2; }
        /* ── Two-panel ── */
        .two-panel { display:flex; flex:1; overflow:hidden; }
        /* ── Fullscreen ── */
        .two-panel.crm-expanded { position:fixed; top:0; left:0; right:0; bottom:0; z-index:10001; background:var(--card); }
        .two-panel.crm-expanded .left-panel { height:100%; max-height:none; }
        .two-panel.crm-expanded .right-panel { height:100%; }
        /* ── Left Panel ── */
        .left-panel { width:280px; min-width:280px; border-right:1px solid var(--border); background:var(--card); display:flex; flex-direction:column; overflow:hidden; }
        .left-panel-header { padding:16px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border); }
        .left-panel-header h3 { font-size:14px; font-weight:600; margin:0; }
        .projects-list { flex:1; overflow-y:auto; padding:8px 0; }
        .project-item { padding:0; }
        .project-header { display:flex; align-items:center; gap:8px; padding:10px 16px; cursor:pointer; transition:background .15s; font-size:14px; font-weight:600; }
        .project-header:hover { background:#f9fafb; }
        .project-header .proj-icon { width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
        .project-header .proj-arrow { margin-left:auto; color:var(--muted); font-size:11px; transition:transform .2s; }
        .project-header.open .proj-arrow { transform:rotate(90deg); }
        .subproject-list { display:none; padding:0 0 4px 16px; }
        .subproject-list.open { display:block; }
        .subproject-item { display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; border-radius:8px; margin:2px 8px; transition:background .15s; font-size:13px; }
        .subproject-item:hover { background:#f3f4f6; }
        .subproject-item.active { background:#ede9fe; color:var(--primary); font-weight:600; }
        .subproject-item .sp-type { font-size:10px; padding:2px 6px; border-radius:4px; margin-left:auto; white-space:nowrap; font-weight:600; }
        /* ── Right Panel ── */
        .right-panel { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .welcome-card { margin:40px auto; max-width:480px; text-align:center; padding:48px 32px; background:var(--card); border-radius:16px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
        .welcome-card i { font-size:48px; color:#c4b5fd; margin-bottom:16px; }
        .welcome-card h2 { font-size:22px; font-weight:700; margin-bottom:8px; }
        .welcome-card p { color:var(--muted); font-size:14px; }
        /* ── Subproject Header ── */
        .sp-header { padding:16px 24px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .sp-title { font-size:18px; font-weight:700; }
        .sp-badge { font-size:11px; padding:3px 10px; border-radius:20px; font-weight:600; }
        .sp-stats { display:flex; gap:20px; margin-left:auto; }
        .sp-stat { text-align:center; }
        .sp-stat-val { font-size:18px; font-weight:700; }
        .sp-stat-lbl { font-size:11px; color:var(--muted); }
        .view-toggle { display:flex; background:#f3f4f6; border-radius:8px; padding:3px; }
        .view-btn { padding:6px 14px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; color:var(--muted); transition:all .15s; }
        .view-btn.active { background:#fff; color:var(--primary); box-shadow:0 1px 3px rgba(0,0,0,.1); }
        /* ── Pipeline ── */
        .pipeline-outer { flex:1; overflow:hidden; display:flex; flex-direction:column; }
        .pipeline-scroll { flex:1; overflow-x:auto; overflow-y:hidden; padding:16px 24px; display:flex; gap:16px; align-items:stretch; }
        .stage-col { min-width:260px; max-width:260px; display:flex; flex-direction:column; }
        .stage-col-header { padding:12px 14px; border-radius:10px 10px 0 0; display:flex; align-items:center; gap:8px; }
        .stage-col-header .stage-title { font-size:13px; font-weight:600; flex:1; }
        .stage-col-header .stage-count { font-size:12px; background:rgba(0,0,0,.08); padding:2px 8px; border-radius:10px; font-weight:600; }
        .stage-cards { background:#f3f4f6; border-radius:0 0 10px 10px; flex:1; min-height:0; overflow-y:auto; padding:8px; display:flex; flex-direction:column; gap:8px; min-height:80px; }
        .lead-card { background:#fff; border-radius:8px; padding:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); cursor:pointer; transition:box-shadow .15s; border-left:3px solid transparent; }
        .lead-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.12); }
        .lead-name { font-size:13px; font-weight:600; margin-bottom:2px; }
        .lead-company { font-size:12px; color:var(--muted); }
        .lead-email { font-size:11px; color:var(--muted); margin-top:4px; }
        .lead-actions { display:flex; gap:4px; margin-top:8px; justify-content:flex-end; }
        .lead-actions button { padding:3px 8px; border:1px solid var(--border); background:#fff; border-radius:5px; cursor:pointer; font-size:11px; color:var(--muted); transition:all .15s; }
        .lead-actions button:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
        .add-lead-btn { margin:8px; padding:8px; border:2px dashed var(--border); background:transparent; border-radius:8px; cursor:pointer; font-size:13px; color:var(--muted); width:calc(100% - 16px); transition:all .15s; }
        .add-lead-btn:hover { border-color:var(--primary); color:var(--primary); }
        /* ── List View ── */
        .list-view-wrap { flex:1; overflow:auto; padding:0 24px 24px; }
        .data-table { width:100%; border-collapse:collapse; font-size:13px; }
        .data-table th { padding:10px 14px; text-align:left; font-weight:600; font-size:12px; color:var(--muted); border-bottom:2px solid var(--border); background:#f9fafb; position:sticky; top:0; }
        .data-table td { padding:10px 14px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .data-table tr:hover td { background:#f9fafb; }
        .stage-badge { font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600; }
        .status-badge { font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600; }
        .status-active { background:#dcfce7; color:#166534; }
        .status-converted { background:#dbeafe; color:#1e40af; }
        .status-lost { background:#fee2e2; color:#991b1b; }
        .status-unsubscribed { background:#f3f4f6; color:#6b7280; }
        /* ── Stats Section ── */
        .stats-section { padding:16px 24px; border-top:1px solid var(--border); background:#f9fafb; }
        .stats-section h4 { font-size:13px; font-weight:600; margin-bottom:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        .bar-chart { display:flex; gap:12px; align-items:flex-end; height:80px; }
        .bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .bar { width:100%; border-radius:4px 4px 0 0; transition:height .3s; min-height:4px; }
        .bar-label { font-size:10px; color:var(--muted); text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:60px; }
        .bar-val { font-size:10px; font-weight:600; color:var(--text); }
        /* ── Buttons ── */
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }
        .btn-primary { background:#667eea; color:#fff; }
        .btn-primary:hover { background:#5a6fd6; }
        .btn-secondary { background:#f3f4f6; color:var(--text); border:1px solid var(--border); }
        .btn-secondary:hover { background:#e5e7eb; }
        .btn-danger { background:#fee2e2; color:#991b1b; }
        .btn-danger:hover { background:#fecaca; }
        .btn-sm { padding:5px 10px; font-size:12px; }
        .btn-icon { width:32px; height:32px; padding:0; justify-content:center; border-radius:8px; }
        /* ── Modals ── */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10100; display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s; }
        .modal-overlay.open { opacity:1; pointer-events:all; }
        .modal { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.2); width:580px; max-width:95vw; max-height:90vh; display:flex; flex-direction:column; transform:translateY(20px); transition:transform .2s; }
        .modal-overlay.open .modal { transform:translateY(0); }
        .modal-lg { width:780px; }
        .modal-xl { width:960px; }
        .modal-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
        .modal-header h3 { font-size:18px; font-weight:700; margin:0; }
        .modal-close { width:32px; height:32px; border:none; background:#f3f4f6; border-radius:8px; cursor:pointer; font-size:16px; color:var(--muted); display:flex; align-items:center; justify-content:center; transition:all .15s; }
        .modal-close:hover { background:#e5e7eb; color:var(--text); }
        .modal-body { padding:24px; overflow-y:auto; flex:1; }
        .modal-footer { padding:16px 24px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px; }
        /* ── Forms ── */
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-grid.cols-1 { grid-template-columns:1fr; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group.span-2 { grid-column:span 2; }
        .form-group label { font-size:13px; font-weight:500; color:var(--text); }
        .form-group input, .form-group select, .form-group textarea {
            padding:9px 12px; border:2px solid var(--border); border-radius:8px; font-size:13px; font-family:inherit; transition:border-color .15s; width:100%; box-sizing:border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:var(--primary); }
        .form-group textarea { resize:vertical; min-height:80px; }
        /* ── Lead Detail ── */
        .lead-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .detail-section h4 { font-size:13px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--border); }
        .detail-field { display:flex; gap:8px; margin-bottom:10px; font-size:13px; }
        .detail-field .label { color:var(--muted); min-width:90px; flex-shrink:0; }
        .detail-field .value { color:var(--text); font-weight:500; word-break:break-word; }
        .activity-timeline { display:flex; flex-direction:column; gap:12px; }
        .activity-item { display:flex; gap:12px; padding:12px; background:#f9fafb; border-radius:8px; }
        .activity-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
        .activity-content { flex:1; }
        .activity-subject { font-size:13px; font-weight:600; margin-bottom:2px; }
        .activity-body { font-size:12px; color:var(--muted); }
        .activity-meta { font-size:11px; color:#9ca3af; margin-top:4px; }
        .stage-history { display:flex; flex-direction:column; gap:8px; }
        .history-item { display:flex; align-items:center; gap:10px; font-size:13px; padding:8px 12px; background:#f9fafb; border-radius:8px; }
        .history-arrow { color:var(--muted); }
        /* ── Settings Panel ── */
        .settings-tabs { display:flex; gap:2px; padding:0 24px; border-bottom:1px solid var(--border); }
        .settings-tab { padding:12px 18px; cursor:pointer; font-size:13px; font-weight:500; color:var(--muted); border-bottom:2px solid transparent; transition:all .15s; }
        .settings-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
        .settings-content { padding:24px; overflow-y:auto; flex:1; }
        .settings-item { display:flex; align-items:center; gap:12px; padding:12px; border:1px solid var(--border); border-radius:10px; margin-bottom:10px; background:#fff; }
        .settings-item-icon { width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
        .settings-item-info { flex:1; }
        .settings-item-name { font-size:14px; font-weight:600; }
        .settings-item-meta { font-size:12px; color:var(--muted); }
        .settings-item-actions { display:flex; gap:6px; }
        .stage-order-badge { width:24px; height:24px; background:#f3f4f6; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:600; color:var(--muted); flex-shrink:0; }
        /* ── Misc ── */
        .empty-state { text-align:center; padding:40px; color:var(--muted); }
        .empty-state i { font-size:32px; margin-bottom:12px; opacity:.4; }
        .empty-state p { font-size:14px; }
        .color-preview { width:20px; height:20px; border-radius:4px; display:inline-block; vertical-align:middle; margin-right:6px; }
        .color-row { display:flex; align-items:center; gap:8px; }
        .loading-spinner { text-align:center; padding:40px; color:var(--muted); }
        .win-badge { background:#dcfce7; color:#166534; font-size:10px; padding:1px 5px; border-radius:4px; margin-left:4px; font-weight:600; }
        .loss-badge { background:#fee2e2; color:#991b1b; font-size:10px; padding:1px 5px; border-radius:4px; margin-left:4px; font-weight:600; }
        .swal2-container { z-index:10200 !important; }
        @media(max-width:900px){
            .stats-bar { grid-template-columns:repeat(2,1fr); }
            .two-panel { flex-direction:column; }
            .left-panel { width:100%; min-width:0; border-right:none; border-bottom:1px solid var(--border); max-height:180px; }
            .form-grid { grid-template-columns:1fr; }
            .form-group.span-2 { grid-column:span 1; }
        }
        @media(max-width:600px){
            .stats-bar { display:none; }
            .page-header { padding:12px 16px 0; }
            .page-header h1 { font-size:20px; }
            .left-panel { max-height:150px; }
            .pipeline-scroll { padding:8px 12px; gap:10px; }
            .stage-col { min-width:220px; max-width:220px; }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content" style="padding:0;overflow:hidden;display:flex;flex-direction:column;height:100vh;">
<?php include 'includes/header.php'; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fa-solid fa-chart-network" style="color:var(--primary);margin-right:10px;"></i>CRM</h1>
            <div class="breadcrumb">Dashboard &rsaquo; CRM</div>
        </div>
        <div class="header-actions">
            <?php if ($canManage): ?>
            <button class="btn btn-secondary" onclick="openImportModal()"><i class="fas fa-file-import"></i> Import CSV</button>
            <button class="btn btn-primary" onclick="openNewLeadModal()"><i class="fas fa-plus"></i> New Lead</button>
            <?php endif; ?>
            <button class="btn btn-secondary btn-icon" onclick="openSettingsModal()" title="Settings"><i class="fas fa-cog"></i></button>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(102,126,234,0.12)"><i class="fas fa-folder" style="color:#667eea"></i></div>
            <div><div class="stat-label">Active Projects</div><div class="stat-value" id="stat-projects">—</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(6,182,212,0.12)"><i class="fas fa-users" style="color:#06b6d4"></i></div>
            <div><div class="stat-label">Active Leads</div><div class="stat-value" id="stat-leads">—</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(16,185,129,0.12)"><i class="fas fa-trophy" style="color:#10b981"></i></div>
            <div><div class="stat-label">Won This Month</div><div class="stat-value" id="stat-won">—</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(245,158,11,0.12)"><i class="fas fa-percent" style="color:#f59e0b"></i></div>
            <div><div class="stat-label">Conversion Rate</div><div class="stat-value" id="stat-conv">—</div></div>
        </div>
    </div>

    <!-- Two-Panel -->
    <div class="two-panel">

        <!-- Left Panel: Projects & Subprojects -->
        <div class="left-panel">
            <div class="left-panel-header">
                <h3><i class="fas fa-layer-group" style="color:var(--primary);margin-right:6px;"></i>Projects</h3>
                <div style="display:flex;gap:6px;align-items:center;">
                    <?php if ($canManage): ?>
                    <button class="btn btn-primary btn-sm btn-icon" onclick="openProjectModal()" title="Add Project"><i class="fas fa-plus"></i></button>
                    <?php endif; ?>
                    <button class="btn btn-secondary btn-sm btn-icon" id="fullscreen-btn" onclick="toggleFullscreen()" title="Fullscreen"><i class="fas fa-expand" id="fullscreen-icon"></i></button>
                </div>
            </div>
            <div class="projects-list" id="projects-list">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel" id="right-panel">
            <div style="display:flex;flex:1;align-items:center;justify-content:center;">
                <div class="welcome-card">
                    <i class="fas fa-hand-pointer"></i>
                    <h2>Select a campaign</h2>
                    <p>Choose a project and subproject from the left panel to view its pipeline and manage leads.</p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════ -->

<!-- Project Modal -->
<div class="modal-overlay" id="modal-project">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-project-title">New Project</h3>
            <button class="modal-close" onclick="closeModal('modal-project')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="proj-id">
            <div class="form-grid">
                <div class="form-group span-2">
                    <label>Project Name *</label>
                    <input type="text" id="proj-name" placeholder="e.g. Q3 Outreach Campaign">
                </div>
                <div class="form-group span-2">
                    <label>Description</label>
                    <textarea id="proj-desc" placeholder="Brief description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <div class="color-row">
                        <input type="color" id="proj-color" value="#667eea" style="width:48px;height:38px;padding:2px;cursor:pointer;border-radius:6px;">
                        <span id="proj-color-preview" style="font-size:13px;color:var(--muted);">#667eea</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Icon (Font Awesome class)</label>
                    <input type="text" id="proj-icon" placeholder="fa-folder" value="fa-folder">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-project')">Cancel</button>
            <button class="btn btn-primary" onclick="saveProject()">Save Project</button>
        </div>
    </div>
</div>

<!-- Subproject Modal -->
<div class="modal-overlay" id="modal-subproject">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-sp-title">New Campaign</h3>
            <button class="modal-close" onclick="closeModal('modal-subproject')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="sp-id">
            <input type="hidden" id="sp-project-id">
            <div class="form-grid">
                <div class="form-group span-2">
                    <label>Campaign Name *</label>
                    <input type="text" id="sp-name" placeholder="e.g. LinkedIn Outreach April 2025">
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select id="sp-type-id">
                        <option value="">Loading types...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="sp-status">
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="form-group span-2">
                    <label>Goal</label>
                    <input type="text" id="sp-goal" placeholder="e.g. Generate 50 qualified leads">
                </div>
                <div class="form-group span-2">
                    <label>Description</label>
                    <textarea id="sp-desc" placeholder="Campaign description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Target Leads</label>
                    <input type="number" id="sp-target" placeholder="50" min="0">
                </div>
                <div class="form-group">
                    <!-- empty -->
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" id="sp-start">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" id="sp-end">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-subproject')">Cancel</button>
            <button class="btn btn-primary" onclick="saveSubproject()">Save Campaign</button>
        </div>
    </div>
</div>

<!-- Lead Modal -->
<div class="modal-overlay" id="modal-lead">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 id="modal-lead-title">Add Lead</h3>
            <button class="modal-close" onclick="closeModal('modal-lead')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="lead-id">
            <input type="hidden" id="lead-subproject-id">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" id="lead-fname" placeholder="John">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" id="lead-lname" placeholder="Doe">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="lead-email" placeholder="john@example.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="lead-phone" placeholder="+49 123 456789">
                </div>
                <div class="form-group">
                    <label>Company</label>
                    <input type="text" id="lead-company" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" id="lead-position" placeholder="Marketing Director">
                </div>
                <div class="form-group">
                    <label>Country</label>
                    <input type="text" id="lead-country" placeholder="Germany">
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="lead-city" placeholder="Berlin">
                </div>
                <div class="form-group">
                    <label>Website</label>
                    <input type="text" id="lead-website" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label>LinkedIn</label>
                    <input type="text" id="lead-linkedin" placeholder="linkedin.com/in/johndoe">
                </div>
                <div class="form-group">
                    <label>Source</label>
                    <select id="lead-source">
                        <option value="">— Select source —</option>
                        <option value="linkedin">LinkedIn</option>
                        <option value="email">Email</option>
                        <option value="referral">Referral</option>
                        <option value="website">Website</option>
                        <option value="cold_call">Cold Call</option>
                        <option value="event">Event</option>
                        <option value="import">Import</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group span-2">
                    <label>Notes</label>
                    <textarea id="lead-notes" placeholder="Additional notes..."></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-lead')">Cancel</button>
            <button class="btn btn-primary" onclick="saveLead()">Save Lead</button>
        </div>
    </div>
</div>

<!-- Lead Detail Modal -->
<div class="modal-overlay" id="modal-lead-detail">
    <div class="modal modal-xl">
        <div class="modal-header">
            <h3 id="modal-ld-name">Lead Detail</h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="modal-ld-stage-badge" class="stage-badge"></span>
                <button class="modal-close" onclick="closeModal('modal-lead-detail')"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="modal-body" id="lead-detail-body">
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal-overlay" id="modal-import">
    <div class="modal">
        <div class="modal-header">
            <h3>Import Leads from CSV</h3>
            <button class="modal-close" onclick="closeModal('modal-import')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0369a1;">
                <strong>CSV Format:</strong> The first row must be a header. Required columns: <code>first_name</code>. Optional: <code>last_name, email, phone, company, position, country</code>
            </div>
            <div class="form-grid cols-1">
                <div class="form-group">
                    <label>Campaign</label>
                    <select id="import-sp-id">
                        <option value="">— Select campaign —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Paste CSV Data *</label>
                    <textarea id="import-csv" placeholder="first_name,last_name,email,phone,company&#10;John,Doe,john@example.com,+49123,Acme Corp" style="min-height:200px;font-family:monospace;font-size:12px;"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-import')">Cancel</button>
            <button class="btn btn-primary" onclick="runImport()"><i class="fas fa-file-import"></i> Import</button>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal-overlay" id="modal-settings">
    <div class="modal modal-lg" style="height:85vh;">
        <div class="modal-header">
            <h3><i class="fas fa-cog" style="margin-right:8px;color:var(--primary);"></i>CRM Settings</h3>
            <button class="modal-close" onclick="closeModal('modal-settings')"><i class="fas fa-times"></i></button>
        </div>
        <div style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
            <div class="settings-tabs">
                <div class="settings-tab active" onclick="switchSettingsTab('types',this)">Project Types</div>
                <div class="settings-tab" onclick="switchSettingsTab('stages',this)">Pipeline Stages</div>
            </div>
            <!-- Types Tab -->
            <div class="settings-content" id="settings-tab-types">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <p style="font-size:13px;color:var(--muted);margin:0;">Define project types (e.g. LinkedIn Outreach, Email Campaign). Each type can have its own pipeline stages.</p>
                    <?php if ($canManage): ?>
                    <button class="btn btn-primary btn-sm" onclick="openTypeForm()"><i class="fas fa-plus"></i> Add Type</button>
                    <?php endif; ?>
                </div>
                <div id="settings-type-form" style="display:none;background:#f9fafb;border-radius:10px;padding:16px;margin-bottom:16px;">
                    <input type="hidden" id="stype-id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Type Name *</label>
                            <input type="text" id="stype-name" placeholder="LinkedIn Outreach">
                        </div>
                        <div class="form-group">
                            <label>Icon (FA class)</label>
                            <input type="text" id="stype-icon" placeholder="fa-linkedin" value="fa-tag">
                        </div>
                        <div class="form-group">
                            <label>Color</label>
                            <input type="color" id="stype-color" value="#667eea" style="height:38px;cursor:pointer;border-radius:6px;padding:2px;">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" id="stype-desc" placeholder="Brief description">
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button class="btn btn-primary btn-sm" onclick="saveType()">Save</button>
                        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('settings-type-form').style.display='none'">Cancel</button>
                    </div>
                </div>
                <div id="settings-types-list">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
            <!-- Stages Tab -->
            <div class="settings-content" id="settings-tab-stages" style="display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <label style="font-size:13px;font-weight:500;">Filter by Type:</label>
                        <select id="stages-filter-type" onchange="loadStagesForSettings()" style="padding:6px 10px;border:2px solid var(--border);border-radius:8px;font-size:13px;">
                            <option value="">Global Stages</option>
                        </select>
                    </div>
                    <?php if ($canManage): ?>
                    <button class="btn btn-primary btn-sm" onclick="openStageForm()"><i class="fas fa-plus"></i> Add Stage</button>
                    <?php endif; ?>
                </div>
                <div id="settings-stage-form" style="display:none;background:#f0f4ff;border:1px solid #c7d2fe;border-radius:10px;padding:16px;margin-bottom:16px;">
                    <input type="hidden" id="sstage-id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Stage Name *</label>
                            <input type="text" id="sstage-name" placeholder="Contacted">
                        </div>
                        <div class="form-group">
                            <label>Color</label>
                            <input type="color" id="sstage-color" value="#667eea" style="height:38px;cursor:pointer;border-radius:6px;padding:2px;">
                        </div>
                        <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" id="sstage-order" value="1" min="1">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" id="sstage-desc" placeholder="Stage description">
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="sstage-win" style="width:auto;"> Win Stage
                            </label>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="sstage-loss" style="width:auto;"> Loss Stage
                            </label>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button class="btn btn-primary btn-sm" onclick="saveStage()">Save</button>
                        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('settings-stage-form').style.display='none'">Cancel</button>
                    </div>
                </div>
                <div id="settings-stages-list">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Activity Modal -->
<div class="modal-overlay" id="modal-activity">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Activity</h3>
            <button class="modal-close" onclick="closeModal('modal-activity')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="act-lead-id">
            <div class="form-grid cols-1">
                <div class="form-group">
                    <label>Activity Type</label>
                    <select id="act-type">
                        <option value="email">Email</option>
                        <option value="call">Call</option>
                        <option value="meeting">Meeting</option>
                        <option value="note" selected>Note</option>
                        <option value="task">Task</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <input type="text" id="act-subject" placeholder="e.g. Follow-up email sent">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="datetime-local" id="act-date">
                </div>
                <div class="form-group">
                    <label>Content</label>
                    <textarea id="act-content" placeholder="Details..."></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-activity')">Cancel</button>
            <button class="btn btn-primary" onclick="saveActivity()"><i class="fas fa-plus"></i> Add Activity</button>
        </div>
    </div>
</div>

<script>
// ══════════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════════
const AJAX = 'ajax/crm.php';
const CAN_MANAGE = <?php echo $canManage ? 'true' : 'false'; ?>;
let state = {
    projects: [],
    subprojects: {},
    types: [],
    currentProjectId: null,
    currentSubprojectId: null,
    currentView: 'pipeline',
    pipelineData: null,
    activeLeadId: null,
};

// ── AJAX helper ──
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const res = await fetch(AJAX, { method: 'POST', body: fd });
    return res.json();
}

// ══════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
    loadProjects();
    // Set default activity date to now
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('act-date').value = now.toISOString().slice(0,16);
    // Color preview
    document.getElementById('proj-color').addEventListener('input', function() {
        document.getElementById('proj-color-preview').textContent = this.value;
    });
});

// ══════════════════════════════════════════════════════════
// DASHBOARD
// ══════════════════════════════════════════════════════════
async function loadDashboard() {
    const r = await api('get_dashboard');
    if (!r.success) return;
    const d = r.data;
    document.getElementById('stat-projects').textContent = d.total_projects;
    document.getElementById('stat-leads').textContent = d.total_leads;
    document.getElementById('stat-won').textContent = d.won_this_month;
    document.getElementById('stat-conv').textContent = d.conversion_rate + '%';
}

// ══════════════════════════════════════════════════════════
// PROJECTS
// ══════════════════════════════════════════════════════════
async function loadProjects() {
    const r = await api('get_projects');
    if (!r.success) return;
    state.projects = r.data;
    renderProjectsList();
    populateImportSelect();
}

function renderProjectsList() {
    const el = document.getElementById('projects-list');
    if (!state.projects.length) {
        el.innerHTML = '<div class="empty-state"><i class="fas fa-folder-open"></i><p>No projects yet.</p></div>';
        return;
    }
    el.innerHTML = state.projects.map(p => `
        <div class="project-item">
            <div class="project-header ${state.subprojects[p.id] ? 'open' : ''}" onclick="toggleProject(${p.id}, this)">
                <div class="proj-icon" style="background:${hexToRgba(p.color,0.12)};color:${p.color}"><i class="fas ${p.icon}"></i></div>
                <span style="flex:1;">${esc(p.project_name)}</span>
                <span style="font-size:11px;color:var(--muted);font-weight:400;">${p.sub_count}</span>
                ${CAN_MANAGE ? `<button class="btn btn-sm btn-icon" style="margin-left:4px;" onclick="event.stopPropagation();openProjectModal(${p.id})" title="Edit"><i class="fas fa-pen" style="font-size:10px;"></i></button>` : ''}
                <i class="fas fa-chevron-right proj-arrow"></i>
            </div>
            <div class="subproject-list ${state.subprojects[p.id] ? 'open' : ''}" id="sp-list-${p.id}">
                ${renderSubprojectList(p.id)}
                ${CAN_MANAGE ? `<div style="padding:4px 8px;">
                    <button class="btn btn-secondary btn-sm" style="width:100%;font-size:11px;" onclick="openSubprojectModal(${p.id})">
                        <i class="fas fa-plus"></i> Add Campaign
                    </button>
                </div>` : ''}
            </div>
        </div>
    `).join('');
}

function renderSubprojectList(projectId) {
    const sps = state.subprojects[projectId];
    if (!sps) return '<div style="padding:8px 12px;font-size:12px;color:var(--muted);">Loading...</div>';
    if (!sps.length) return '<div style="padding:8px 12px;font-size:12px;color:var(--muted);">No campaigns yet.</div>';
    return sps.map(sp => `
        <div class="subproject-item ${state.currentSubprojectId == sp.id ? 'active' : ''}" onclick="selectSubproject(${sp.id}, ${projectId})">
            <i class="fas ${sp.type_icon || 'fa-tag'}" style="color:${sp.type_color || '#667eea'};font-size:12px;"></i>
            <span style="flex:1;">${esc(sp.subproject_name)}</span>
            <span class="sp-type" style="background:${hexToRgba(sp.type_color||'#667eea',0.12)};color:${sp.type_color||'#667eea'}">${esc(sp.type_name || '')}</span>
        </div>
    `).join('');
}

async function toggleProject(projectId, headerEl) {
    headerEl.classList.toggle('open');
    const listEl = document.getElementById('sp-list-' + projectId);
    listEl.classList.toggle('open');
    if (!state.subprojects[projectId]) {
        await loadSubprojects(projectId);
    }
}

async function loadSubprojects(projectId) {
    const r = await api('get_subprojects', { project_id: projectId });
    if (!r.success) return;
    state.subprojects[projectId] = r.data;
    const listEl = document.getElementById('sp-list-' + projectId);
    if (listEl) {
        const addBtn = listEl.querySelector('button.btn-secondary')?.closest('div');
        listEl.innerHTML = renderSubprojectList(projectId);
        if (addBtn) listEl.appendChild(addBtn);
    }
}

function selectSubproject(subprojectId, projectId) {
    state.currentSubprojectId = subprojectId;
    state.currentProjectId = projectId;
    // Update active state in sidebar
    document.querySelectorAll('.subproject-item').forEach(el => el.classList.remove('active'));
    event.currentTarget.classList.add('active');
    loadPipeline(subprojectId);
    loadDashboard();
}

// ══════════════════════════════════════════════════════════
// PIPELINE / LIST VIEW
// ══════════════════════════════════════════════════════════
async function loadPipeline(subprojectId) {
    const rpanel = document.getElementById('right-panel');
    rpanel.innerHTML = '<div class="loading-spinner" style="padding:60px;text-align:center;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

    // Find subproject info
    let sp = null;
    for (const pid in state.subprojects) {
        const found = state.subprojects[pid].find(s => s.id == subprojectId);
        if (found) { sp = found; break; }
    }

    const [pipelineRes, statsRes] = await Promise.all([
        api('get_leads', { subproject_id: subprojectId }),
        api('get_subproject_stats', { subproject_id: subprojectId })
    ]);

    if (!pipelineRes.success) {
        rpanel.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${pipelineRes.message}</p></div>`;
        return;
    }

    state.pipelineData = pipelineRes.data;
    const { stages, leads } = pipelineRes.data;
    const stats = statsRes.success ? statsRes.data : null;

    // Group leads by stage
    const leadsByStage = {};
    stages.forEach(s => leadsByStage[s.id] = []);
    leads.forEach(l => {
        if (leadsByStage[l.current_stage_id] !== undefined) {
            leadsByStage[l.current_stage_id].push(l);
        } else {
            // Unassigned
            if (!leadsByStage['_unassigned']) leadsByStage['_unassigned'] = [];
            leadsByStage['_unassigned'].push(l);
        }
    });

    const spName = sp ? sp.subproject_name : 'Campaign';
    const spType = sp ? sp.type_name : '';
    const spColor = sp ? (sp.type_color || '#667eea') : '#667eea';

    rpanel.innerHTML = `
        <div class="sp-header">
            <div>
                <div class="sp-title">${esc(spName)}</div>
                <div style="margin-top:4px;display:flex;align-items:center;gap:8px;">
                    <span class="sp-badge" style="background:${hexToRgba(spColor,0.12)};color:${spColor}">${esc(spType)}</span>
                    ${sp ? `<span style="font-size:12px;color:var(--muted);">${sp.status}</span>` : ''}
                </div>
            </div>
            <div class="sp-stats">
                <div class="sp-stat"><div class="sp-stat-val">${leads.length}</div><div class="sp-stat-lbl">Total Leads</div></div>
                ${stats ? `<div class="sp-stat"><div class="sp-stat-val">${stats.won_leads}</div><div class="sp-stat-lbl">Won</div></div>
                <div class="sp-stat"><div class="sp-stat-val">${stats.conversion_rate}%</div><div class="sp-stat-lbl">Conv. Rate</div></div>` : ''}
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <div class="view-toggle">
                    <button class="view-btn ${state.currentView==='pipeline'?'active':''}" onclick="switchView('pipeline')"><i class="fas fa-columns"></i> Pipeline</button>
                    <button class="view-btn ${state.currentView==='list'?'active':''}" onclick="switchView('list')"><i class="fas fa-list"></i> List</button>
                </div>
                ${CAN_MANAGE ? `
                <button class="btn btn-secondary btn-sm" onclick="openSubprojectModal(${state.currentProjectId}, ${subprojectId})" title="Edit campaign"><i class="fas fa-pen"></i></button>
                ` : ''}
            </div>
        </div>
        <div id="view-container" style="flex:1;display:flex;flex-direction:column;overflow:hidden;"></div>
        ${stats ? renderStatsSection(stats) : ''}
    `;

    renderCurrentView(stages, leadsByStage, leads);
}

function switchView(view) {
    state.currentView = view;
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    event.currentTarget.classList.add('active');
    if (state.pipelineData) {
        const { stages, leads } = state.pipelineData;
        const leadsByStage = {};
        stages.forEach(s => leadsByStage[s.id] = []);
        leads.forEach(l => {
            if (leadsByStage[l.current_stage_id] !== undefined) leadsByStage[l.current_stage_id].push(l);
        });
        renderCurrentView(stages, leadsByStage, leads);
    }
}

function renderCurrentView(stages, leadsByStage, leads) {
    const container = document.getElementById('view-container');
    if (!container) return;
    if (state.currentView === 'pipeline') {
        renderPipelineView(container, stages, leadsByStage);
    } else {
        renderListView(container, stages, leads);
    }
}

function renderPipelineView(container, stages, leadsByStage) {
    if (!stages.length) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>No pipeline stages defined for this type. Configure stages in Settings.</p></div>';
        return;
    }
    container.innerHTML = `
        <div class="pipeline-scroll" id="pipeline-board">
            ${stages.map((stage, idx) => {
                const stageLeads = leadsByStage[stage.id] || [];
                const textColor = colorIsLight(stage.color) ? '#333' : '#fff';
                return `
                <div class="stage-col">
                    <div class="stage-col-header" style="background:${hexToRgba(stage.color,0.10)};color:${stage.color};border-bottom:2px solid ${hexToRgba(stage.color,0.35)};">
                        <span class="stage-title">
                            ${esc(stage.stage_name)}
                            ${stage.is_win_stage ? '<span style="font-size:10px;opacity:.8;">(Win)</span>' : ''}
                            ${stage.is_loss_stage ? '<span style="font-size:10px;opacity:.8;">(Loss)</span>' : ''}
                        </span>
                        <span class="stage-count">${stageLeads.length}</span>
                    </div>
                    <div class="stage-cards" id="stage-cards-${stage.id}">
                        ${stageLeads.map(l => renderLeadCard(l, stage, stages, idx)).join('')}
                        ${!stageLeads.length ? '<div class="empty-state" style="padding:20px;"><i class="fas fa-inbox"></i><p>No leads</p></div>' : ''}
                    </div>
                    ${CAN_MANAGE && idx === 0 ? `<button class="add-lead-btn" onclick="openNewLeadModal(${state.currentSubprojectId})"><i class="fas fa-plus"></i> Add Lead</button>` : ''}
                </div>
            `}).join('')}
        </div>
    `;
}

function renderLeadCard(lead, stage, allStages, stageIdx) {
    const prevStage = stageIdx > 0 ? allStages[stageIdx - 1] : null;
    const nextStage = stageIdx < allStages.length - 1 ? allStages[stageIdx + 1] : null;
    return `
        <div class="lead-card" style="border-left-color:${stage.color};" onclick="openLeadDetail(${lead.id})">
            <div class="lead-name">${esc(lead.first_name)} ${esc(lead.last_name)}</div>
            ${lead.company ? `<div class="lead-company"><i class="fas fa-building" style="font-size:10px;"></i> ${esc(lead.company)}</div>` : ''}
            ${lead.email ? `<div class="lead-email"><i class="fas fa-envelope" style="font-size:10px;"></i> ${esc(lead.email)}</div>` : ''}
            ${CAN_MANAGE ? `<div class="lead-actions">
                ${prevStage ? `<button onclick="event.stopPropagation();moveLead(${lead.id},${prevStage.id},'${esc(prevStage.stage_name)}')" title="Move to ${esc(prevStage.stage_name)}"><i class="fas fa-arrow-left"></i></button>` : ''}
                <button onclick="event.stopPropagation();openLeadModal(${lead.id})" title="Edit"><i class="fas fa-pen"></i></button>
                <button onclick="event.stopPropagation();deleteLead(${lead.id})" title="Delete" style="color:#ef4444;"><i class="fas fa-trash"></i></button>
                ${nextStage ? `<button onclick="event.stopPropagation();moveLead(${lead.id},${nextStage.id},'${esc(nextStage.stage_name)}')" title="Move to ${esc(nextStage.stage_name)}"><i class="fas fa-arrow-right"></i></button>` : ''}
            </div>` : ''}
        </div>
    `;
}

function renderListView(container, stages, leads) {
    const stageMap = {};
    stages.forEach(s => stageMap[s.id] = s);
    container.innerHTML = `
        <div class="list-view-wrap">
            ${CAN_MANAGE ? `<div style="margin-bottom:12px;"><button class="btn btn-primary btn-sm" onclick="openNewLeadModal(${state.currentSubprojectId})"><i class="fas fa-plus"></i> Add Lead</button></div>` : ''}
            ${!leads.length ? '<div class="empty-state"><i class="fas fa-users"></i><p>No leads in this campaign.</p></div>' : `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Stage</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${leads.map(l => {
                        const s = stageMap[l.current_stage_id];
                        return `<tr>
                            <td style="font-weight:600;cursor:pointer;" onclick="openLeadDetail(${l.id})">${esc(l.first_name)} ${esc(l.last_name)}</td>
                            <td>${esc(l.company || '—')}</td>
                            <td>${l.email ? `<a href="mailto:${esc(l.email)}" style="color:var(--primary);">${esc(l.email)}</a>` : '—'}</td>
                            <td>${esc(l.phone || '—')}</td>
                            <td>${s ? `<span class="stage-badge" style="background:${hexToRgba(s.color,0.12)};color:${s.color}">${esc(s.stage_name)}</span>` : '—'}</td>
                            <td><span class="status-badge status-${l.status}">${l.status}</span></td>
                            <td>${esc(l.source || '—')}</td>
                            <td>
                                ${CAN_MANAGE ? `<div style="display:flex;gap:4px;">
                                    <button class="btn btn-secondary btn-sm btn-icon" onclick="openLeadModal(${l.id})" title="Edit"><i class="fas fa-pen" style="font-size:11px;"></i></button>
                                    <button class="btn btn-danger btn-sm btn-icon" onclick="deleteLead(${l.id})" title="Delete"><i class="fas fa-trash" style="font-size:11px;"></i></button>
                                </div>` : `<button class="btn btn-secondary btn-sm btn-icon" onclick="openLeadDetail(${l.id})" title="View"><i class="fas fa-eye" style="font-size:11px;"></i></button>`}
                            </td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>`}
        </div>
    `;
}

function renderStatsSection(stats) {
    if (!stats.leads_by_stage || !stats.leads_by_stage.length) return '';
    const maxCnt = Math.max(1, ...stats.leads_by_stage.map(s => parseInt(s.cnt) || 0));
    return `
        <div class="stats-section">
            <h4><i class="fas fa-chart-bar" style="margin-right:6px;"></i>Lead Distribution by Stage</h4>
            <div class="bar-chart">
                ${stats.leads_by_stage.map(s => {
                    const pct = Math.max(4, Math.round(((parseInt(s.cnt)||0) / maxCnt) * 72));
                    return `<div class="bar-wrap">
                        <div class="bar-val">${s.cnt}</div>
                        <div class="bar" style="background:${s.color || '#667eea'};height:${pct}px;"></div>
                        <div class="bar-label" title="${esc(s.stage_name)}">${esc(s.stage_name)}</div>
                    </div>`;
                }).join('')}
            </div>
        </div>
    `;
}

// ══════════════════════════════════════════════════════════
// LEAD ACTIONS
// ══════════════════════════════════════════════════════════
async function moveLead(leadId, toStageId, stageName) {
    const result = await Swal.fire({
        title: `Move to "${stageName}"?`,
        input: 'text',
        inputLabel: 'Add a note (optional)',
        inputPlaceholder: 'e.g. Replied to email',
        showCancelButton: true,
        confirmButtonText: 'Move Lead',
        confirmButtonColor: '#667eea',
        cancelButtonText: 'Cancel',
    });
    if (!result.isConfirmed) return;
    const r = await api('move_lead', { lead_id: leadId, to_stage_id: toStageId, notes: result.value || '' });
    if (r.success) {
        showToast('Lead moved successfully', 'success');
        await loadPipeline(state.currentSubprojectId);
        loadDashboard();
    } else {
        showToast(r.message, 'error');
    }
}

async function deleteLead(leadId) {
    const result = await Swal.fire({
        title: 'Delete Lead?',
        text: 'This will permanently delete the lead and all its activities.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancel',
    });
    if (!result.isConfirmed) return;
    const r = await api('delete_lead', { id: leadId });
    if (r.success) {
        showToast('Lead deleted', 'success');
        await loadPipeline(state.currentSubprojectId);
        loadDashboard();
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// PROJECT MODAL
// ══════════════════════════════════════════════════════════
function openProjectModal(id = null) {
    document.getElementById('proj-id').value = id || '';
    document.getElementById('modal-project-title').textContent = id ? 'Edit Project' : 'New Project';
    if (id) {
        const proj = state.projects.find(p => p.id == id);
        if (proj) {
            document.getElementById('proj-name').value = proj.project_name;
            document.getElementById('proj-desc').value = proj.description || '';
            document.getElementById('proj-color').value = proj.color || '#667eea';
            document.getElementById('proj-color-preview').textContent = proj.color || '#667eea';
            document.getElementById('proj-icon').value = proj.icon || 'fa-folder';
        }
    } else {
        document.getElementById('proj-name').value = '';
        document.getElementById('proj-desc').value = '';
        document.getElementById('proj-color').value = '#667eea';
        document.getElementById('proj-color-preview').textContent = '#667eea';
        document.getElementById('proj-icon').value = 'fa-folder';
    }
    openModal('modal-project');
}

async function saveProject() {
    const id = document.getElementById('proj-id').value;
    const name = document.getElementById('proj-name').value.trim();
    if (!name) { showToast('Project name is required', 'error'); return; }
    const data = {
        project_name: name,
        description: document.getElementById('proj-desc').value,
        color: document.getElementById('proj-color').value,
        icon: document.getElementById('proj-icon').value || 'fa-folder',
    };
    if (id) data.id = id;
    const r = await api(id ? 'update_project' : 'create_project', data);
    if (r.success) {
        showToast(r.message, 'success');
        closeModal('modal-project');
        await loadProjects();
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// SUBPROJECT MODAL
// ══════════════════════════════════════════════════════════
async function openSubprojectModal(projectId, subprojectId = null) {
    document.getElementById('sp-project-id').value = projectId;
    document.getElementById('sp-id').value = subprojectId || '';
    document.getElementById('modal-sp-title').textContent = subprojectId ? 'Edit Campaign' : 'New Campaign';

    // Load types into select
    if (!state.types.length) {
        const tr = await api('get_types');
        if (tr.success) state.types = tr.data;
    }
    const sel = document.getElementById('sp-type-id');
    sel.innerHTML = '<option value="">— Select type —</option>' +
        state.types.map(t => `<option value="${t.id}">${esc(t.type_name)}</option>`).join('');

    if (subprojectId) {
        const sps = state.subprojects[projectId] || [];
        const sp = sps.find(s => s.id == subprojectId);
        if (sp) {
            document.getElementById('sp-name').value = sp.subproject_name;
            document.getElementById('sp-type-id').value = sp.type_id;
            document.getElementById('sp-status').value = sp.status;
            document.getElementById('sp-goal').value = sp.goal || '';
            document.getElementById('sp-desc').value = sp.description || '';
            document.getElementById('sp-target').value = sp.target_leads || '';
            document.getElementById('sp-start').value = sp.start_date || '';
            document.getElementById('sp-end').value = sp.end_date || '';
        }
    } else {
        document.getElementById('sp-name').value = '';
        document.getElementById('sp-type-id').value = '';
        document.getElementById('sp-status').value = 'active';
        document.getElementById('sp-goal').value = '';
        document.getElementById('sp-desc').value = '';
        document.getElementById('sp-target').value = '';
        document.getElementById('sp-start').value = '';
        document.getElementById('sp-end').value = '';
    }
    openModal('modal-subproject');
}

async function saveSubproject() {
    const id = document.getElementById('sp-id').value;
    const pid = document.getElementById('sp-project-id').value;
    const name = document.getElementById('sp-name').value.trim();
    const typeId = document.getElementById('sp-type-id').value;
    if (!name || !typeId) { showToast('Name and Type are required', 'error'); return; }
    const data = {
        project_id: pid,
        type_id: typeId,
        subproject_name: name,
        description: document.getElementById('sp-desc').value,
        goal: document.getElementById('sp-goal').value,
        target_leads: document.getElementById('sp-target').value || 0,
        start_date: document.getElementById('sp-start').value,
        end_date: document.getElementById('sp-end').value,
        status: document.getElementById('sp-status').value,
    };
    if (id) data.id = id;
    const r = await api(id ? 'update_subproject' : 'create_subproject', data);
    if (r.success) {
        showToast(r.message, 'success');
        closeModal('modal-subproject');
        await loadSubprojects(pid);
        renderProjectsList();
        if (id && state.currentSubprojectId == id) loadPipeline(id);
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// LEAD MODAL (Add/Edit)
// ══════════════════════════════════════════════════════════
function openNewLeadModal(spId = null) {
    const subId = spId || state.currentSubprojectId;
    if (!subId) { showToast('Please select a campaign first', 'error'); return; }
    document.getElementById('lead-id').value = '';
    document.getElementById('lead-subproject-id').value = subId;
    document.getElementById('modal-lead-title').textContent = 'Add Lead';
    ['fname','lname','email','phone','company','position','website','linkedin','country','city','notes'].forEach(f => {
        document.getElementById('lead-' + f).value = '';
    });
    document.getElementById('lead-source').value = '';
    openModal('modal-lead');
}

async function openLeadModal(leadId) {
    // Load lead data for editing
    const r = await api('get_lead_detail', { lead_id: leadId });
    if (!r.success) { showToast(r.message, 'error'); return; }
    const lead = r.data.lead;
    document.getElementById('lead-id').value = lead.id;
    document.getElementById('lead-subproject-id').value = lead.subproject_id;
    document.getElementById('modal-lead-title').textContent = 'Edit Lead';
    document.getElementById('lead-fname').value = lead.first_name || '';
    document.getElementById('lead-lname').value = lead.last_name || '';
    document.getElementById('lead-email').value = lead.email || '';
    document.getElementById('lead-phone').value = lead.phone || '';
    document.getElementById('lead-company').value = lead.company || '';
    document.getElementById('lead-position').value = lead.position || '';
    document.getElementById('lead-website').value = lead.website || '';
    document.getElementById('lead-linkedin').value = lead.linkedin || '';
    document.getElementById('lead-country').value = lead.country || '';
    document.getElementById('lead-city').value = lead.city || '';
    document.getElementById('lead-notes').value = lead.notes || '';
    document.getElementById('lead-source').value = lead.source || '';
    openModal('modal-lead');
}

async function saveLead() {
    const id = document.getElementById('lead-id').value;
    const fname = document.getElementById('lead-fname').value.trim();
    if (!fname) { showToast('First name is required', 'error'); return; }
    const data = {
        subproject_id: document.getElementById('lead-subproject-id').value,
        first_name: fname,
        last_name: document.getElementById('lead-lname').value,
        email: document.getElementById('lead-email').value,
        phone: document.getElementById('lead-phone').value,
        company: document.getElementById('lead-company').value,
        position: document.getElementById('lead-position').value,
        website: document.getElementById('lead-website').value,
        linkedin: document.getElementById('lead-linkedin').value,
        country: document.getElementById('lead-country').value,
        city: document.getElementById('lead-city').value,
        notes: document.getElementById('lead-notes').value,
        source: document.getElementById('lead-source').value,
    };
    if (id) data.id = id;
    const r = await api(id ? 'update_lead' : 'create_lead', data);
    if (r.success) {
        showToast(r.message, 'success');
        closeModal('modal-lead');
        if (state.currentSubprojectId) {
            await loadPipeline(state.currentSubprojectId);
            loadDashboard();
        }
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// LEAD DETAIL MODAL
// ══════════════════════════════════════════════════════════
async function openLeadDetail(leadId) {
    state.activeLeadId = leadId;
    openModal('modal-lead-detail');
    document.getElementById('lead-detail-body').innerHTML = '<div class="loading-spinner" style="padding:40px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const r = await api('get_lead_detail', { lead_id: leadId });
    if (!r.success) {
        document.getElementById('lead-detail-body').innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${r.message}</p></div>`;
        return;
    }
    const { lead, activities, history } = r.data;
    document.getElementById('modal-ld-name').textContent = `${lead.first_name} ${lead.last_name}`;
    const badge = document.getElementById('modal-ld-stage-badge');
    badge.textContent = lead.stage_name || '';
    const _sc = lead.stage_color || '#667eea';
    badge.style.background = hexToRgba(_sc, 0.12);
    badge.style.color = _sc;

    const activityTypeIcons = { email:'fa-envelope', call:'fa-phone', meeting:'fa-handshake', note:'fa-sticky-note', task:'fa-check-square', other:'fa-circle' };
    const activityTypeColors = { email:'#3b82f6', call:'#10b981', meeting:'#f59e0b', note:'#8b5cf6', task:'#ec4899', other:'#6b7280' };

    document.getElementById('lead-detail-body').innerHTML = `
        <div class="lead-detail-grid">
            <!-- Left: Lead Info -->
            <div>
                <div class="detail-section">
                    <h4>Contact Information</h4>
                    ${field('Name', `${lead.first_name} ${lead.last_name}`)}
                    ${field('Email', lead.email ? `<a href="mailto:${esc(lead.email)}" style="color:var(--primary);">${esc(lead.email)}</a>` : '—')}
                    ${field('Phone', lead.phone || '—')}
                    ${field('Company', lead.company || '—')}
                    ${field('Position', lead.position || '—')}
                    ${field('Country', [lead.city, lead.country].filter(Boolean).join(', ') || '—')}
                    ${lead.website ? field('Website', `<a href="${esc(lead.website)}" target="_blank" style="color:var(--primary);">${esc(lead.website)}</a>`) : ''}
                    ${lead.linkedin ? field('LinkedIn', `<a href="${esc(lead.linkedin)}" target="_blank" style="color:var(--primary);">View Profile</a>`) : ''}
                    ${field('Source', lead.source || '—')}
                    ${field('Status', `<span class="status-badge status-${lead.status}">${lead.status}</span>`)}
                    ${field('Last Contacted', lead.last_contacted ? formatDate(lead.last_contacted) : 'Never')}
                </div>
                ${lead.notes ? `<div class="detail-section"><h4>Notes</h4><p style="font-size:13px;color:var(--text);line-height:1.6;">${esc(lead.notes)}</p></div>` : ''}
                <!-- Stage History -->
                ${history.length ? `
                <div class="detail-section" style="margin-top:16px;">
                    <h4>Stage History</h4>
                    <div class="stage-history">
                        ${history.map(h => `
                            <div class="history-item">
                                <span style="color:var(--muted);font-size:11px;">${formatDate(h.created_at)}</span>
                                <span>${esc(h.from_stage || 'Start')}</span>
                                <span class="history-arrow"><i class="fas fa-arrow-right"></i></span>
                                <span class="stage-badge" style="background:${hexToRgba(h.to_color||'#667eea',0.12)};color:${h.to_color||'#667eea'}">${esc(h.to_stage||'')}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>` : ''}
            </div>
            <!-- Right: Activities -->
            <div>
                <div class="detail-section">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);">
                        <h4 style="margin:0;padding:0;border:none;">Activities (${activities.length})</h4>
                        ${CAN_MANAGE ? `<button class="btn btn-primary btn-sm" onclick="openActivityModal(${leadId})"><i class="fas fa-plus"></i> Add</button>` : ''}
                    </div>
                    ${activities.length ? `
                    <div class="activity-timeline">
                        ${activities.map(a => `
                            <div class="activity-item">
                                <div class="activity-icon" style="background:${hexToRgba(activityTypeColors[a.activity_type]||'#6b7280',0.12)};color:${activityTypeColors[a.activity_type]||'#6b7280'}">
                                    <i class="fas ${activityTypeIcons[a.activity_type]||'fa-circle'}"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-subject">${esc(a.subject)}</div>
                                    ${a.content ? `<div class="activity-body">${esc(a.content)}</div>` : ''}
                                    <div class="activity-meta">${formatDate(a.activity_date)} · ${a.activity_type}</div>
                                </div>
                                ${CAN_MANAGE ? `<button class="btn btn-danger btn-sm btn-icon" onclick="deleteActivity(${a.id})" title="Delete"><i class="fas fa-trash" style="font-size:10px;"></i></button>` : ''}
                            </div>
                        `).join('')}
                    </div>` : `<div class="empty-state"><i class="fas fa-comment-slash"></i><p>No activities recorded yet.</p></div>`}
                </div>
            </div>
        </div>
        ${CAN_MANAGE ? `
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:10px;">
            <button class="btn btn-secondary" onclick="closeModal('modal-lead-detail');openLeadModal(${leadId})"><i class="fas fa-pen"></i> Edit Lead</button>
            <button class="btn btn-danger" onclick="closeModal('modal-lead-detail');deleteLead(${leadId})"><i class="fas fa-trash"></i> Delete Lead</button>
        </div>` : ''}
    `;
}

function field(label, value) {
    return `<div class="detail-field"><span class="label">${label}:</span><span class="value">${value}</span></div>`;
}

// ══════════════════════════════════════════════════════════
// ACTIVITY MODAL
// ══════════════════════════════════════════════════════════
function openActivityModal(leadId) {
    document.getElementById('act-lead-id').value = leadId;
    document.getElementById('act-type').value = 'note';
    document.getElementById('act-subject').value = '';
    document.getElementById('act-content').value = '';
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('act-date').value = now.toISOString().slice(0,16);
    openModal('modal-activity');
}

async function saveActivity() {
    const leadId = document.getElementById('act-lead-id').value;
    const subject = document.getElementById('act-subject').value.trim();
    if (!subject) { showToast('Subject is required', 'error'); return; }
    const r = await api('add_activity', {
        lead_id: leadId,
        activity_type: document.getElementById('act-type').value,
        subject: subject,
        content: document.getElementById('act-content').value,
        activity_date: document.getElementById('act-date').value,
    });
    if (r.success) {
        showToast('Activity added', 'success');
        closeModal('modal-activity');
        openLeadDetail(leadId); // Refresh detail
    } else {
        showToast(r.message, 'error');
    }
}

async function deleteActivity(actId) {
    const result = await Swal.fire({
        title: 'Delete activity?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#ef4444',
    });
    if (!result.isConfirmed) return;
    const r = await api('delete_activity', { id: actId });
    if (r.success) {
        showToast('Activity deleted', 'success');
        if (state.activeLeadId) openLeadDetail(state.activeLeadId);
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// IMPORT MODAL
// ══════════════════════════════════════════════════════════
function openImportModal() {
    populateImportSelect();
    document.getElementById('import-csv').value = '';
    openModal('modal-import');
}

function populateImportSelect() {
    const sel = document.getElementById('import-sp-id');
    sel.innerHTML = '<option value="">— Select campaign —</option>';
    for (const pid in state.subprojects) {
        const proj = state.projects.find(p => p.id == pid);
        const projName = proj ? proj.project_name : 'Project';
        state.subprojects[pid].forEach(sp => {
            const opt = document.createElement('option');
            opt.value = sp.id;
            opt.textContent = `${projName} › ${sp.subproject_name}`;
            sel.appendChild(opt);
        });
    }
}

async function runImport() {
    const spId = document.getElementById('import-sp-id').value;
    const csv = document.getElementById('import-csv').value.trim();
    if (!spId) { showToast('Select a campaign', 'error'); return; }
    if (!csv) { showToast('Paste CSV data', 'error'); return; }
    const r = await api('import_leads', { subproject_id: spId, csv_data: csv });
    if (r.success) {
        showToast(r.message, 'success');
        closeModal('modal-import');
        if (state.currentSubprojectId == spId) await loadPipeline(state.currentSubprojectId);
        loadDashboard();
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// SETTINGS MODAL
// ══════════════════════════════════════════════════════════
async function openSettingsModal() {
    openModal('modal-settings');
    await loadTypes();
    // Populate type filter for stages tab
    const sel = document.getElementById('stages-filter-type');
    sel.innerHTML = '<option value="">Global Stages</option>' +
        state.types.map(t => `<option value="${t.id}">${esc(t.type_name)}</option>`).join('');
    // Auto-select first real type so stages are visible by default
    if (state.types.length > 0) {
        sel.value = state.types[0].id;
    }
    await loadStagesForSettings();
}

function switchSettingsTab(tab, el) {
    document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('settings-tab-types').style.display = tab === 'types' ? '' : 'none';
    document.getElementById('settings-tab-stages').style.display = tab === 'stages' ? '' : 'none';
}

async function loadTypes() {
    const r = await api('get_types');
    if (!r.success) return;
    state.types = r.data;
    const el = document.getElementById('settings-types-list');
    if (!r.data.length) {
        el.innerHTML = '<div class="empty-state"><i class="fas fa-tags"></i><p>No types defined yet.</p></div>';
        return;
    }
    el.innerHTML = r.data.map(t => `
        <div class="settings-item">
            <div class="settings-item-icon" style="background:${hexToRgba(t.color,0.12)};color:${t.color}"><i class="fas ${t.icon}"></i></div>
            <div class="settings-item-info">
                <div class="settings-item-name">${esc(t.type_name)}</div>
                <div class="settings-item-meta">${t.stage_count} stages · ${t.sub_count} campaigns</div>
            </div>
            ${CAN_MANAGE ? `<div class="settings-item-actions">
                <button class="btn btn-secondary btn-sm btn-icon" onclick="openTypeForm(${t.id})" title="Edit"><i class="fas fa-pen" style="font-size:11px;"></i></button>
                <button class="btn btn-danger btn-sm btn-icon" onclick="deleteType(${t.id})" title="Delete"><i class="fas fa-trash" style="font-size:11px;"></i></button>
            </div>` : ''}
        </div>
    `).join('');
}

function openTypeForm(id = null) {
    const form = document.getElementById('settings-type-form');
    form.style.display = '';
    document.getElementById('stype-id').value = id || '';
    if (id) {
        const t = state.types.find(x => x.id == id);
        if (t) {
            document.getElementById('stype-name').value = t.type_name;
            document.getElementById('stype-icon').value = t.icon || 'fa-tag';
            document.getElementById('stype-color').value = t.color || '#667eea';
            document.getElementById('stype-desc').value = t.description || '';
        }
    } else {
        document.getElementById('stype-name').value = '';
        document.getElementById('stype-icon').value = 'fa-tag';
        document.getElementById('stype-color').value = '#667eea';
        document.getElementById('stype-desc').value = '';
    }
    document.getElementById('stype-name').focus();
}

async function saveType() {
    const id = document.getElementById('stype-id').value;
    const name = document.getElementById('stype-name').value.trim();
    if (!name) { showToast('Type name required', 'error'); return; }
    const data = {
        type_name: name,
        icon: document.getElementById('stype-icon').value || 'fa-tag',
        color: document.getElementById('stype-color').value,
        description: document.getElementById('stype-desc').value,
    };
    if (id) data.id = id;
    const r = await api(id ? 'update_type' : 'create_type', data);
    if (r.success) {
        showToast(r.message, 'success');
        document.getElementById('settings-type-form').style.display = 'none';
        await loadTypes();
        // Refresh type select in stages filter
        const sel = document.getElementById('stages-filter-type');
        sel.innerHTML = '<option value="">Global Stages</option>' +
            state.types.map(t => `<option value="${t.id}">${esc(t.type_name)}</option>`).join('');
    } else {
        showToast(r.message, 'error');
    }
}

async function deleteType(id) {
    const t = state.types.find(x => x.id == id);
    if (t && parseInt(t.sub_count) > 0) {
        showToast('Cannot delete: type is used by campaigns', 'error');
        return;
    }
    const result = await Swal.fire({ title: 'Delete type?', text: 'This will also delete all its pipeline stages.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Delete' });
    if (!result.isConfirmed) return;
    const r = await api('delete_type', { id });
    if (r.success) {
        showToast(r.message, 'success');
        await loadTypes();
    } else {
        showToast(r.message, 'error');
    }
}

async function loadStagesForSettings() {
    const typeId = document.getElementById('stages-filter-type').value;
    const data = typeId ? { type_id: typeId } : { type_id: '' };
    const r = await api('get_stages', data);
    const el = document.getElementById('settings-stages-list');
    if (!r.success) { el.innerHTML = '<div class="empty-state"><p>Failed to load stages.</p></div>'; return; }
    if (!r.data.length) {
        el.innerHTML = '<div class="empty-state"><i class="fas fa-layer-group"></i><p>No stages defined for this type yet.</p></div>';
        return;
    }
    el.innerHTML = r.data.map(s => `
        <div class="settings-item">
            <div class="stage-order-badge">${s.display_order}</div>
            <div class="settings-item-icon" style="background:${s.color}"><i class="fas fa-circle" style="font-size:8px;"></i></div>
            <div class="settings-item-info">
                <div class="settings-item-name">
                    ${esc(s.stage_name)}
                    ${s.is_win_stage ? '<span class="win-badge">WIN</span>' : ''}
                    ${s.is_loss_stage ? '<span class="loss-badge">LOSS</span>' : ''}
                </div>
                <div class="settings-item-meta">${s.description || 'No description'}</div>
            </div>
            ${CAN_MANAGE ? `<div class="settings-item-actions">
                <button class="btn btn-secondary btn-sm btn-icon" onclick="openStageForm(${s.id})" title="Edit"><i class="fas fa-pen" style="font-size:11px;"></i></button>
                <button class="btn btn-danger btn-sm btn-icon" onclick="deleteStage(${s.id})" title="Delete"><i class="fas fa-trash" style="font-size:11px;"></i></button>
            </div>` : ''}
        </div>
    `).join('');
}

function openStageForm(id = null) {
    const form = document.getElementById('settings-stage-form');
    form.style.display = '';
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    document.getElementById('sstage-id').value = id || '';
    if (id) {
        // Find in loaded list — we reload from DOM data attributes
        const items = document.querySelectorAll('#settings-stages-list .settings-item');
        // Just clear for now, user can re-fill
    }
    if (!id) {
        document.getElementById('sstage-name').value = '';
        document.getElementById('sstage-color').value = '#667eea';
        document.getElementById('sstage-order').value = '1';
        document.getElementById('sstage-desc').value = '';
        document.getElementById('sstage-win').checked = false;
        document.getElementById('sstage-loss').checked = false;
    }
    document.getElementById('sstage-name').focus();
}

async function saveStage() {
    const id = document.getElementById('sstage-id').value;
    const name = document.getElementById('sstage-name').value.trim();
    if (!name) { showToast('Stage name required', 'error'); return; }
    const typeId = document.getElementById('stages-filter-type').value;
    const data = {
        stage_name: name,
        description: document.getElementById('sstage-desc').value,
        color: document.getElementById('sstage-color').value,
        display_order: document.getElementById('sstage-order').value || 1,
        is_win_stage: document.getElementById('sstage-win').checked ? 1 : 0,
        is_loss_stage: document.getElementById('sstage-loss').checked ? 1 : 0,
        type_id: typeId,
    };
    if (id) data.id = id;
    const r = await api(id ? 'update_stage' : 'create_stage', data);
    if (r.success) {
        showToast(r.message, 'success');
        document.getElementById('settings-stage-form').style.display = 'none';
        await loadStagesForSettings();
    } else {
        showToast(r.message, 'error');
    }
}

async function deleteStage(id) {
    const result = await Swal.fire({ title: 'Delete stage?', text: 'Cannot delete if leads are currently in this stage.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Delete' });
    if (!result.isConfirmed) return;
    const r = await api('delete_stage', { id });
    if (r.success) {
        showToast(r.message, 'success');
        await loadStagesForSettings();
    } else {
        showToast(r.message, 'error');
    }
}

// ══════════════════════════════════════════════════════════
// MODAL HELPERS
// ══════════════════════════════════════════════════════════
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
// ESC to close
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    }
});

// ══════════════════════════════════════════════════════════
// UTILITIES
// ══════════════════════════════════════════════════════════
function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function formatDate(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) + ' ' +
           d.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
}
function colorIsLight(hex) {
    if (!hex) return false;
    const c = hex.replace('#','');
    const r = parseInt(c.substr(0,2),16);
    const g = parseInt(c.substr(2,2),16);
    const b = parseInt(c.substr(4,2),16);
    return (r*299 + g*587 + b*114) / 1000 > 155;
}
function hexToRgba(hex, alpha) {
    if (!hex) return `rgba(102,126,234,${alpha})`;
    const c = hex.replace('#','');
    const r = parseInt(c.substr(0,2),16);
    const g = parseInt(c.substr(2,2),16);
    const b = parseInt(c.substr(4,2),16);
    return `rgba(${r},${g},${b},${alpha})`;
}
function toggleFullscreen() {
    const panel = document.querySelector('.two-panel');
    const icon = document.getElementById('fullscreen-icon');
    const expanded = panel.classList.toggle('crm-expanded');
    icon.className = expanded ? 'fas fa-compress' : 'fas fa-expand';
    document.getElementById('fullscreen-btn').title = expanded ? 'Exit Fullscreen' : 'Fullscreen';
}
function showToast(message, type = 'success') {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
    });
}
</script>
</body>
</html>
