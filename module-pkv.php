<?php
require_once 'config.php';
requireLogin();

// Check PKV permissions - strict role-based check, admin status alone is not enough
$hasPKVAccess = hasModulePermission('pkv.view');
if (!$hasPKVAccess) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$currentUser = getCurrentUser();
$hasPKVManage = hasModulePermission('pkv.view_all');
$isExternalBroker = hasModulePermission('pkv.view_assigned') && !$hasPKVManage;
$canAssignEnquiries = hasModulePermission('pkv.assign_broker') || $hasPKVManage;
$canManageAll = $hasPKVManage;

$conn = getDBConnection();

// Get enquiry counts by state
$stateCountsQuery = "SELECT state, COUNT(*) as count FROM ten_pkv_enquiries ";
if ($isExternalBroker) {
    $stateCountsQuery .= "WHERE assigned_broker_id = ? ";
}
$stateCountsQuery .= "GROUP BY state";

if ($isExternalBroker) {
    $stmt = $conn->prepare($stateCountsQuery);
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $stateCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $stateCounts = $conn->query($stateCountsQuery)->fetch_all(MYSQLI_ASSOC);
}

// Convert to associative array
$stateCountsArray = [];
foreach ($stateCounts as $sc) {
    $stateCountsArray[$sc['state']] = $sc['count'];
}

// Get brokers/insurance companies for dropdown
$brokersQuery = "SELECT id, company_name, contact_person, email FROM ten_pkv_brokers WHERE is_active = 1 ORDER BY company_name";
$brokers = $conn->query($brokersQuery)->fetch_all(MYSQLI_ASSOC);

// Get admin users for email recipient selection
$adminUsersQuery = "SELECT DISTINCT u.id, u.full_name, u.email 
                    FROM ten_users u
                    LEFT JOIN ten_user_roles ur ON u.id = ur.user_id
                    LEFT JOIN ten_role_permissions rp ON ur.role_id = rp.role_id
                    LEFT JOIN ten_permissions p ON rp.permission_id = p.id
                    WHERE (u.is_admin = 1 OR p.permission_key IN ('pkv_manage_all', 'pkv_assign'))
                    AND u.email IS NOT NULL
                    AND u.status = 'active'
                    ORDER BY u.full_name";
$adminUsers = $conn->query($adminUsersQuery)->fetch_all(MYSQLI_ASSOC);

// Get note templates
$noteTemplatesQuery = "SELECT id, title, content, applicable_states FROM ten_pkv_note_templates WHERE is_active = 1 ORDER BY display_order, title";
$noteTemplates = $conn->query($noteTemplatesQuery)->fetch_all(MYSQLI_ASSOC);

// Get email templates
$emailTemplatesQuery = "SELECT id, title, subject, body, applicable_states FROM ten_pkv_email_templates WHERE is_active = 1 ORDER BY display_order, title";
$emailTemplates = $conn->query($emailTemplatesQuery)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$currentPage = 'pkv';
$pageTitle = t('pkv.title', 'PKV Management');
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            box-sizing: border-box;
        }
        
        .pkv-container {
            max-width: 100%;
            padding: 20px;
        }
        
        @media (min-width: 768px) {
            .pkv-container {
                padding: 30px;
            }
        }
        
        /* Stats Grid - Mobile First with Collapsible */
        .stats-wrapper {
            margin-bottom: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
            }
        }
        
        .stats-grid.collapsed .stat-card:nth-child(n+5) {
            display: none;
        }
        
        .toggle-states-btn {
            width: 100%;
            padding: 12px;
            background: white;
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            color: #667eea;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .toggle-states-btn:hover {
            border-color: #667eea;
            background: #f9fafb;
        }
        
        .toggle-states-btn i {
            transition: transform 0.3s;
        }
        
        .toggle-states-btn.expanded i {
            transform: rotate(180deg);
        }
        
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 0px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border-bottom: 4px solid transparent;
            min-width: 0;
        }
        
        @media (min-width: 768px) {
            .stat-card {
                padding: 20px;
            }
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .stat-card.active {
            border-bottom-color: #667eea;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
        }
        
        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        @media (min-width: 768px) {
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
            word-break: break-word;
        }
        
        @media (min-width: 768px) {
            .stat-value {
                font-size: 28px;
            }
        }
        
        @media (min-width: 1024px) {
            .stat-value {
                font-size: 32px;
            }
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            word-break: break-word;
            hyphens: auto;
        }
        
        @media (min-width: 768px) {
            .stat-label {
                font-size: 13px;
            }
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        @media (min-width: 768px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
        
        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        
        @media (min-width: 768px) {
            .card-header h2 {
                font-size: 20px;
            }
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 12px;
            flex: 1;
            min-width: 200px;
        }
        
        .search-box input {
            border: none;
            background: none;
            outline: none;
            flex: 1;
            font-size: 14px;
            color: #111827;
        }
        
        .search-box i {
            color: #6b7280;
            margin-right: 8px;
        }
        
        .card-body {
            padding: 20px;
            overflow-x: auto;
        }
        
        /* Table - Mobile First */
        .enquiry-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        .enquiry-table th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        
        .enquiry-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        
        .enquiry-table tr:hover {
            background: #f9fafb;
        }
        
        .state-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .state-new { background: #dbeafe; color: #1e40af; }
        .state-contacted { background: #fef3c7; color: #92400e; }
        .state-awaiting-client { background: #fce7f3; color: #9f1239; }
        .state-awaiting-insurer { background: #e0e7ff; color: #3730a3; }
        .state-documents-pending { background: #fed7aa; color: #9a3412; }
        .state-quote-provided { background: #ddd6fe; color: #5b21b6; }
        .state-negotiating { background: #fbcfe8; color: #831843; }
        .state-contract-prep { background: #cffafe; color: #155e75; }
        .state-contract-sent { background: #d1fae5; color: #065f46; }
        .state-signed { background: #86efac; color: #14532d; }
        .state-on-hold { background: #fef08a; color: #713f12; }
        .state-closed-success { background: #34d399; color: #064e3b; }
        .state-closed-failed { background: #fca5a5; color: #7f1d1d; }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }
        
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            min-width: 40px;
        }
        
        .pagination button:hover:not(:disabled) {
            background: #f9fafb;
            border-color: #667eea;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination button.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination-info {
            font-size: 14px;
            color: #6b7280;
            margin-right: 8px;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f9fafb;
            color: #374151;
            border: 1px solid #e5e7eb;
        }
        
        .btn-secondary:hover {
            background: #f3f4f6;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Modal - WITH SCROLLBAR */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            /*overflow-y: auto;*/
            -webkit-overflow-scrolling: touch;
        }
        
        .modal.active {
            display: block;
        }
        
        .modal-dialog {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            margin: 20px auto;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            background: white;
            border-radius: 12px 12px 0 0;
        }
        
        .modal-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: #f9fafb;
            color: #6b7280;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1 1 auto;
            min-height: 0;
        }
        
        /* Force scrollbar to always show */
        .modal-body {
            scrollbar-width: thin;
            scrollbar-color: #888 #f1f1f1;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 10px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 5px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            flex-shrink: 0;
            background: white;
            border-radius: 0 0 12px 12px;
        }
        
        /* Form Styling - IMPROVED */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-label.required:after {
            content: ' *';
            color: #ef4444;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #111827;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control[readonly] {
            background: #f9fafb;
            cursor: not-allowed;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .form-value {
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 14px;
            color: #111827;
        }
        
        .form-value a {
            color: #667eea;
            text-decoration: none;
        }
        
        .form-value a:hover {
            text-decoration: underline;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .checkbox-group:hover {
            background: #f3f4f6;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            margin: 0;
        }
        
        .checkbox-group label {
            cursor: pointer;
            margin: 0;
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }
        
        /* Email Recipients Section */
        .recipients-section {
            background: #f9fafb;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        .recipients-section h4 {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin: 0 0 12px 0;
        }
        
        .recipients-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        @media (min-width: 640px) {
            .recipients-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .recipient-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .recipient-checkbox:hover {
            border-color: #667eea;
            background: #f9fafb;
        }
        
        .recipient-checkbox input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
        }
        
        .recipient-checkbox label {
            margin: 0;
            cursor: pointer;
            font-size: 13px;
            color: #374151;
            flex: 1;
        }
        
        .recipient-email {
            font-size: 12px;
            color: #6b7280;
            display: block;
        }
        
        .manual-email-input {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        
        .manual-email-input input {
            flex: 1;
        }
        
        .manual-email-input button {
            flex-shrink: 0;
        }
        
        .selected-emails {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .email-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: #667eea;
            color: white;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .email-tag button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .email-tag button:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Activity Log */
        .activity-log {
            margin-top: 24px;
        }
        
        .activity-item {
            padding: 16px;
            background: #f9fafb;
            border-left: 3px solid #667eea;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .activity-user {
            font-weight: 600;
            color: #111827;
            font-size: 14px;
        }
        
        .activity-time {
            font-size: 12px;
            color: #6b7280;
        }
        
        .activity-action {
            font-size: 13px;
            color: #374151;
            margin-bottom: 4px;
        }
        
        .activity-note {
            font-size: 13px;
            color: #6b7280;
            font-style: italic;
            background: white;
            padding: 8px;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        /* Loading State */
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .loading i {
            font-size: 32px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        
        @media (max-width: 767px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
        
        .detail-section {
            margin-bottom: 24px;
        }
        
        .detail-section:last-child {
            margin-bottom: 0;
        }
        
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .detail-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        
        .detail-subtitle {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 0 0 12px 0;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="pkv-container">
            <div class="page-header" style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0 0 8px 0;">
                    <i class="fas fa-briefcase-medical"></i> <?php echo $pageTitle; ?>
                </h1>
                <p style="color: #6b7280; font-size: 14px; margin: 0;">
                    <?php echo t('pkv.subtitle', 'Manage PKV insurance enquiries and track client progress'); ?>
                </p>
            </div>
            
            <!-- Stats Grid with Collapse -->
            <div class="stats-wrapper">
                <div class="stats-grid collapsed" id="statsGrid">
                    <?php
                    $states = [
                        'all' => ['icon' => 'fa-list', 'label' => t('pkv.state.all', 'All Enquiries'), 'color' => '#667eea'],
                        'new' => ['icon' => 'fa-star', 'label' => t('pkv.state.new', 'New'), 'color' => '#3b82f6'],
                        'contacted' => ['icon' => 'fa-phone', 'label' => t('pkv.state.contacted', 'Contacted'), 'color' => '#f59e0b'],
                        'awaiting_client_response' => ['icon' => 'fa-clock', 'label' => t('pkv.state.awaiting_client', 'Awaiting Client'), 'color' => '#ec4899'],
                        'awaiting_insurer_response' => ['icon' => 'fa-hourglass-half', 'label' => t('pkv.state.awaiting_insurer', 'Awaiting Insurer'), 'color' => '#6366f1'],
                        'documents_pending' => ['icon' => 'fa-file-alt', 'label' => t('pkv.state.documents_pending', 'Documents Pending'), 'color' => '#ea580c'],
                        'quote_provided' => ['icon' => 'fa-file-invoice-dollar', 'label' => t('pkv.state.quote_provided', 'Quote Provided'), 'color' => '#8b5cf6'],
                        'negotiating' => ['icon' => 'fa-handshake', 'label' => t('pkv.state.negotiating', 'Negotiating'), 'color' => '#db2777'],
                        'contract_preparation' => ['icon' => 'fa-pen', 'label' => t('pkv.state.contract_prep', 'Contract Prep'), 'color' => '#0891b2'],
                        'contract_sent' => ['icon' => 'fa-paper-plane', 'label' => t('pkv.state.contract_sent', 'Contract Sent'), 'color' => '#059669'],
                        'contract_signed' => ['icon' => 'fa-check-circle', 'label' => t('pkv.state.signed', 'Signed'), 'color' => '#16a34a'],
                        'on_hold' => ['icon' => 'fa-pause', 'label' => t('pkv.state.on_hold', 'On Hold'), 'color' => '#ca8a04'],
                        'closed_success' => ['icon' => 'fa-trophy', 'label' => t('pkv.state.closed_success', 'Success'), 'color' => '#10b981'],
                        'closed_failed' => ['icon' => 'fa-times-circle', 'label' => t('pkv.state.closed_failed', 'Failed'), 'color' => '#ef4444']
                    ];
                    
                    $totalCount = array_sum($stateCountsArray);
                    
                    foreach ($states as $stateKey => $stateInfo) {
                        $count = $stateKey === 'all' ? $totalCount : ($stateCountsArray[$stateKey] ?? 0);
                        ?>
                        <div class="stat-card" onclick="filterByState('<?php echo $stateKey; ?>')" data-state="<?php echo $stateKey; ?>">
                            <div class="stat-card-header">
                                <div class="stat-icon" style="background: <?php echo $stateInfo['color']; ?>22; color: <?php echo $stateInfo['color']; ?>">
                                    <i class="fas <?php echo $stateInfo['icon']; ?>"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo $count; ?></div>
                            <div class="stat-label"><?php echo $stateInfo['label']; ?></div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
                <button class="toggle-states-btn" id="toggleStatesBtn" onclick="toggleStates()">
                    <span id="toggleStatesText"><?php echo t('pkv.view_more_states', 'View More States'); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            
            <!-- Enquiries List -->
            <div class="content-card">
                <div class="card-header">
                    <h2 id="cardTitle"><?php echo t('pkv.enquiries', 'Client Enquiries'); ?></h2>
                    <div class="header-actions">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="<?php echo t('pkv.search', 'Search clients...'); ?>" />
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="enquiriesTableContainer">
                        <div class="loading">
                            <i class="fas fa-spinner"></i>
                            <p><?php echo t('common.loading', 'Loading...'); ?></p>
                        </div>
                    </div>
                    <div id="paginationContainer"></div>
                </div>
            </div>
            
            <?php if (isAdmin()): ?>
            <!-- Activity Log -->
            <div class="content-card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> <?php echo t('pkv.activity_log', 'Activity Log'); ?></h2>
                </div>
                <div class="card-body">
                    <div id="activityLogContainer">
                        <div class="loading">
                            <i class="fas fa-spinner"></i>
                            <p><?php echo t('common.loading', 'Loading...'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Enquiry Details Modal -->
    <div id="enquiryDetailsModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?php echo t('pkv.client_details', 'Client Details'); ?></h3>
                    <button class="modal-close" onclick="closeEnquiryModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Content loaded dynamically -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- State Change Modal -->
    <div id="stateChangeModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?php echo t('pkv.change_state', 'Change State'); ?></h3>
                    <button class="modal-close" onclick="closeStateChangeModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="stateChangeForm" onsubmit="submitStateChange(event)">
                    <div class="modal-body">
                        <input type="hidden" id="stateChangeEnquiryId" name="enquiry_id">
                        <input type="hidden" id="stateChangeEnquiryData" value="">
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.current_state', 'Current State'); ?></label>
                            <input type="text" id="currentState" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required"><?php echo t('pkv.new_state', 'New State'); ?></label>
                            <select id="newState" name="new_state" class="form-control" required onchange="updateTemplateOptions()">
                                <option value=""><?php echo t('pkv.select_state', 'Select new state...'); ?></option>
                                <option value="new"><?php echo t('pkv.state.new', 'New'); ?></option>
                                <option value="contacted"><?php echo t('pkv.state.contacted', 'Contacted'); ?></option>
                                <option value="awaiting_client_response"><?php echo t('pkv.state.awaiting_client', 'Awaiting Client Response'); ?></option>
                                <option value="awaiting_insurer_response"><?php echo t('pkv.state.awaiting_insurer', 'Awaiting Insurer Response'); ?></option>
                                <option value="documents_pending"><?php echo t('pkv.state.documents_pending', 'Documents Pending'); ?></option>
                                <option value="quote_provided"><?php echo t('pkv.state.quote_provided', 'Quote Provided'); ?></option>
                                <option value="negotiating"><?php echo t('pkv.state.negotiating', 'Negotiating'); ?></option>
                                <option value="contract_preparation"><?php echo t('pkv.state.contract_prep', 'Contract Preparation'); ?></option>
                                <option value="contract_sent"><?php echo t('pkv.state.contract_sent', 'Contract Sent'); ?></option>
                                <option value="contract_signed"><?php echo t('pkv.state.signed', 'Contract Signed'); ?></option>
                                <option value="on_hold"><?php echo t('pkv.state.on_hold', 'On Hold'); ?></option>
                                <option value="closed_success"><?php echo t('pkv.state.closed_success', 'Closed - Success'); ?></option>
                                <option value="closed_failed"><?php echo t('pkv.state.closed_failed', 'Closed - Failed'); ?></option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.note_template', 'Note Template'); ?></label>
                            <select id="noteTemplate" class="form-control" onchange="applyNoteTemplate()">
                                <option value=""><?php echo t('pkv.select_template', 'Select template...'); ?></option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.note', 'Note'); ?></label>
                            <textarea id="stateChangeNote" name="note" class="form-control" placeholder="<?php echo t('pkv.note_placeholder', 'Optional note about this state change...'); ?>"></textarea>
                        </div>
                        
                        <?php if (!$isExternalBroker): ?>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="sendEmailCheck" name="send_email" value="1">
                                <label for="sendEmailCheck"><?php echo t('pkv.send_email', 'Send email notification'); ?></label>
                            </div>
                        </div>
                        
                        <div id="emailSection" style="display: none;">
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.email_template', 'Email Template'); ?></label>
                                <select id="emailTemplate" class="form-control" onchange="applyEmailTemplate()">
                                    <option value=""><?php echo t('pkv.select_template', 'Select template...'); ?></option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <div class="recipients-section">
                                    <h4><?php echo t('pkv.select_recipients', 'Select Recipients'); ?></h4>
                                    
                                    <div class="recipients-grid" id="recipientsGrid">
                                        <!-- Broker -->
                                        <div class="recipient-checkbox" id="brokerRecipientBox" style="display: none;">
                                            <input type="checkbox" id="emailToBroker" value="broker">
                                            <label for="emailToBroker">
                                                <strong><?php echo t('pkv.assigned_broker', 'Assigned Broker'); ?></strong>
                                                <span class="recipient-email" id="brokerEmail"></span>
                                            </label>
                                        </div>
                                        
                                        <!-- Admin Users -->
                                        <?php foreach ($adminUsers as $admin): ?>
                                        <div class="recipient-checkbox">
                                            <input type="checkbox" class="email-recipient" value="<?php echo htmlspecialchars($admin['email']); ?>">
                                            <label>
                                                <strong><?php echo htmlspecialchars($admin['full_name']); ?></strong>
                                                <span class="recipient-email"><?php echo htmlspecialchars($admin['email']); ?></span>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="manual-email-input">
                                        <input type="email" id="manualEmail" class="form-control" placeholder="<?php echo t('pkv.enter_email', 'Enter email address...'); ?>">
                                        <button type="button" class="btn btn-secondary" onclick="addManualEmail()">
                                            <i class="fas fa-plus"></i> <?php echo t('common.add', 'Add'); ?>
                                        </button>
                                    </div>
                                    
                                    <div class="selected-emails" id="selectedEmails">
                                        <!-- Selected emails will appear here as tags -->
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.email_subject', 'Email Subject'); ?></label>
                                <input type="text" id="emailSubject" name="email_subject" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.email_body', 'Email Body'); ?></label>
                                <textarea id="emailBody" name="email_body" class="form-control" rows="6"></textarea>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeStateChangeModal()">
                            <?php echo t('common.cancel', 'Cancel'); ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <?php echo t('pkv.update_state', 'Update State'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Client Modal -->
    <div id="editClientModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?php echo t('pkv.edit_client', 'Edit Client Details'); ?></h3>
                    <button class="modal-close" onclick="closeEditClientModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="editClientForm" onsubmit="submitEditClient(event)">
                    <div class="modal-body">
                        <input type="hidden" id="editEnquiryId" name="enquiry_id">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required"><?php echo t('pkv.first_name', 'First Name'); ?></label>
                                <input type="text" id="editFirstName" name="first_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required"><?php echo t('pkv.last_name', 'Last Name'); ?></label>
                                <input type="text" id="editLastName" name="last_name" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.email', 'Email'); ?></label>
                                <input type="email" id="editEmail" name="email" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.phone', 'Phone'); ?></label>
                                <input type="text" id="editPhone" name="phone" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.dob', 'Date of Birth'); ?></label>
                                <input type="date" id="editDOB" name="date_of_birth" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo t('pkv.nationality', 'Nationality'); ?></label>
                                <input type="text" id="editNationality" name="nationality" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.additional_info', 'Additional Information'); ?></label>
                            <textarea id="editAdditionalInfo" name="additional_info" class="form-control" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditClientModal()">
                            <?php echo t('common.cancel', 'Cancel'); ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <?php echo t('common.save', 'Save Changes'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Change Broker Modal -->
    <div id="changeBrokerModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><?php echo t('pkv.change_broker', 'Change Assigned Broker'); ?></h3>
                    <button class="modal-close" onclick="closeChangeBrokerModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="changeBrokerForm" onsubmit="submitChangeBroker(event)">
                    <div class="modal-body">
                        <input type="hidden" id="brokerEnquiryId" name="enquiry_id">
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.current_broker', 'Current Broker'); ?></label>
                            <input type="text" id="currentBroker" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required"><?php echo t('pkv.new_broker', 'New Broker'); ?></label>
                            <select id="newBroker" name="broker_id" class="form-control" required>
                                <option value=""><?php echo t('pkv.select_broker', 'Select broker...'); ?></option>
                                <?php foreach ($brokers as $broker): ?>
                                <option value="<?php echo $broker['id']; ?>">
                                    <?php echo htmlspecialchars($broker['company_name']); ?>
                                    <?php if ($broker['contact_person']): ?>
                                        (<?php echo htmlspecialchars($broker['contact_person']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.note', 'Note'); ?></label>
                            <textarea id="brokerChangeNote" name="note" class="form-control" placeholder="<?php echo t('pkv.broker_note_placeholder', 'Optional note about broker change...'); ?>"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeChangeBrokerModal()">
                            <?php echo t('common.cancel', 'Cancel'); ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <?php echo t('pkv.change_broker', 'Change Broker'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const isExternalBroker = <?php echo $isExternalBroker ? 'true' : 'false'; ?>;
        const canManageAll = <?php echo $canManageAll ? 'true' : 'false'; ?>;
        const isAdmin = <?php echo isAdmin() ? 'true' : 'false'; ?>;
        
        const noteTemplates = <?php echo json_encode($noteTemplates); ?>;
        const emailTemplates = <?php echo json_encode($emailTemplates); ?>;
        const brokers = <?php echo json_encode($brokers); ?>;
        const adminUsers = <?php echo json_encode($adminUsers); ?>;
        
        // State names mapping
        const stateNames = {
            'all': '<?php echo t('pkv.state.all', 'All Enquiries'); ?>',
            'new': '<?php echo t('pkv.state.new', 'New'); ?>',
            'contacted': '<?php echo t('pkv.state.contacted', 'Contacted'); ?>',
            'awaiting_client_response': '<?php echo t('pkv.state.awaiting_client', 'Awaiting Client'); ?>',
            'awaiting_insurer_response': '<?php echo t('pkv.state.awaiting_insurer', 'Awaiting Insurer'); ?>',
            'documents_pending': '<?php echo t('pkv.state.documents_pending', 'Documents Pending'); ?>',
            'quote_provided': '<?php echo t('pkv.state.quote_provided', 'Quote Provided'); ?>',
            'negotiating': '<?php echo t('pkv.state.negotiating', 'Negotiating'); ?>',
            'contract_preparation': '<?php echo t('pkv.state.contract_prep', 'Contract Preparation'); ?>',
            'contract_sent': '<?php echo t('pkv.state.contract_sent', 'Contract Sent'); ?>',
            'contract_signed': '<?php echo t('pkv.state.signed', 'Contract Signed'); ?>',
            'on_hold': '<?php echo t('pkv.state.on_hold', 'On Hold'); ?>',
            'closed_success': '<?php echo t('pkv.state.closed_success', 'Closed - Success'); ?>',
            'closed_failed': '<?php echo t('pkv.state.closed_failed', 'Closed - Failed'); ?>'
        };
        
        let enquiriesData = [];
        let currentFilter = 'all';
        let currentPage = 1;
        let totalPages = 1;
        let totalItems = 0;
        let searchQuery = '';
        const itemsPerPage = 20;
        let selectedEmails = new Set();
        let currentEnquiry = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('PKV Module initialized');
            loadEnquiries();
            
            // Setup search with debounce
            const searchInput = document.getElementById('searchInput');
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    searchQuery = this.value;
                    currentPage = 1;
                    loadEnquiries();
                }, 300);
            });
            
            // Setup email checkbox
            const sendEmailCheck = document.getElementById('sendEmailCheck');
            if (sendEmailCheck) {
                sendEmailCheck.addEventListener('change', function() {
                    document.getElementById('emailSection').style.display = this.checked ? 'block' : 'none';
                });
            }
            
            // Setup email recipient checkboxes
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('email-recipient') || e.target.id === 'emailToBroker') {
                    updateSelectedEmails();
                }
            });
            
            // If external broker, auto-email admins on state change
            if (isExternalBroker) {
                document.getElementById('stateChangeForm').addEventListener('submit', function(e) {
                    const formData = new FormData(this);
                    formData.set('notify_admins', '1');
                });
            }
            
            // Load activity log if admin
            if (isAdmin) {
                loadActivityLog();
            }
        });
        
        // Toggle states visibility
        function toggleStates() {
            const grid = document.getElementById('statsGrid');
            const btn = document.getElementById('toggleStatesBtn');
            const text = document.getElementById('toggleStatesText');
            const icon = btn.querySelector('i');
            
            if (grid.classList.contains('collapsed')) {
                grid.classList.remove('collapsed');
                text.textContent = '<?php echo t('pkv.hide_states', 'Hide States'); ?>';
                btn.classList.add('expanded');
            } else {
                grid.classList.add('collapsed');
                text.textContent = '<?php echo t('pkv.view_more_states', 'View More States'); ?>';
                btn.classList.remove('expanded');
            }
        }
        
        // Filter by state
        function filterByState(state) {
            currentFilter = state;
            currentPage = 1;
            
            // Update active stat card
            document.querySelectorAll('.stat-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`.stat-card[data-state="${state}"]`)?.classList.add('active');
            
            // Update table title
            updateTableTitle();
            
            loadEnquiries();
        }
        
        // Update table title based on filter
        function updateTableTitle() {
            const cardTitle = document.getElementById('cardTitle');
            if (currentFilter === 'all') {
                cardTitle.textContent = '<?php echo t('pkv.enquiries', 'Client Enquiries'); ?>';
            } else {
                const stateName = stateNames[currentFilter] || currentFilter;
                cardTitle.textContent = '<?php echo t('pkv.enquiries', 'Client Enquiries'); ?> (' + stateName + ')';
            }
        }
        
        // Load enquiries with pagination and search
        function loadEnquiries() {
            console.log('Loading enquiries...', { currentFilter, currentPage, searchQuery });
            const container = document.getElementById('enquiriesTableContainer');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p><?php echo t('common.loading', 'Loading...'); ?></p></div>';
            
            const params = new URLSearchParams({
                state: currentFilter,
                page: currentPage,
                per_page: itemsPerPage,
                search: searchQuery
            });
            
            // Try ajax/ directory first (most common)
            const ajaxPath = '/management/ajax/pkv_get_enquiries.php';
            console.log('Fetching:', `${ajaxPath}?${params}`);
            
            fetch(`${ajaxPath}?${params}`)
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Data received:', data);
                    if (data.success) {
                        enquiriesData = data.enquiries || [];
                        totalPages = data.total_pages || 1;
                        totalItems = data.total || 0;
                        renderEnquiriesTable(data.enquiries || []);
                        renderPagination();
                    } else {
                        container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${data.message || 'Failed to load enquiries'}</p></div>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading enquiries:', error);
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>An error occurred. Check console for details.</p></div>';
                });
        }
        
        // Render enquiries table
        function renderEnquiriesTable(enquiries) {
            const container = document.getElementById('enquiriesTableContainer');
            
            if (enquiries.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p><?php echo t('pkv.no_enquiries', 'No enquiries found'); ?></p>
                    </div>
                `;
                return;
            }
            
            let tableHTML = `
                <table class="enquiry-table">
                    <thead>
                        <tr>
                            <th><?php echo t('pkv.id', 'ID'); ?></th>
                            <th><?php echo t('pkv.name', 'Name'); ?></th>
                            <th><?php echo t('pkv.contact', 'Contact'); ?></th>
                            <th><?php echo t('pkv.state', 'State'); ?></th>
                            ${!isExternalBroker ? '<th><?php echo t('pkv.broker', 'Broker'); ?></th>' : ''}
                            <th><?php echo t('pkv.created', 'Created'); ?></th>
                            <th><?php echo t('common.actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            enquiries.forEach(enquiry => {
                const stateClass = getStateClass(enquiry.state);
                const stateName = getStateName(enquiry.state);
                
                tableHTML += `
                    <tr>
                        <td>#${enquiry.id}</td>
                        <td style="font-weight: 600;">${escapeHtml(enquiry.first_name)} ${escapeHtml(enquiry.last_name)}</td>
                        <td>
                            ${enquiry.email ? `<div style="font-size: 13px;">${escapeHtml(enquiry.email)}</div>` : ''}
                            ${enquiry.phone ? `<div style="font-size: 12px; color: #6b7280;">${escapeHtml(enquiry.phone)}</div>` : ''}
                        </td>
                        <td><span class="state-badge ${stateClass}">${stateName}</span></td>
                        ${!isExternalBroker ? `<td>${enquiry.broker_name || '<em>Unassigned</em>'}</td>` : ''}
                        <td>${formatDate(enquiry.created_at)}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-secondary" onclick="viewEnquiry(${enquiry.id})" title="<?php echo t('common.view', 'View'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="changeState(${enquiry.id})" title="<?php echo t('pkv.change_state', 'Change State'); ?>">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tableHTML += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = tableHTML;
        }
        
        // Render pagination
        function renderPagination() {
            const paginationContainer = document.getElementById('paginationContainer');
            
            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }
            
            const startItem = (currentPage - 1) * itemsPerPage + 1;
            const endItem = Math.min(currentPage * itemsPerPage, totalItems);
            
            let paginationHTML = '<div class="pagination">';
            
            // Info
            paginationHTML += `<span class="pagination-info">Showing ${startItem}-${endItem} of ${totalItems}</span>`;
            
            // Previous button
            paginationHTML += `
                <button ${currentPage === 1 ? 'disabled' : ''} onclick="changePage(${currentPage - 1})" type="button">
                    <i class="fas fa-chevron-left"></i>
                </button>
            `;
            
            // Page numbers
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(totalPages, startPage + maxButtons - 1);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            if (startPage > 1) {
                paginationHTML += `<button onclick="changePage(1)" type="button">1</button>`;
                if (startPage > 2) paginationHTML += `<button disabled type="button">...</button>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                    <button class="${i === currentPage ? 'active' : ''}" onclick="changePage(${i})" type="button">
                        ${i}
                    </button>
                `;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) paginationHTML += `<button disabled type="button">...</button>`;
                paginationHTML += `<button onclick="changePage(${totalPages})" type="button">${totalPages}</button>`;
            }
            
            // Next button
            paginationHTML += `
                <button ${currentPage === totalPages ? 'disabled' : ''} onclick="changePage(${currentPage + 1})" type="button">
                    <i class="fas fa-chevron-right"></i>
                </button>
            `;
            
            paginationHTML += '</div>';
            
            paginationContainer.innerHTML = paginationHTML;
        }
        
        // Change page
        function changePage(page) {
            if (page < 1 || page > totalPages || page === currentPage) return;
            currentPage = page;
            loadEnquiries();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // Replace placeholders in template
        function replacePlaceholders(text, enquiry) {
            if (!text || !enquiry) return text;
            
            return text
                .replace(/\[CLIENT_FIRST_NAME\]/g, enquiry.first_name || '')
                .replace(/\[CLIENT_LAST_NAME\]/g, enquiry.last_name || '')
                .replace(/\[CLIENT_FULL_NAME\]/g, `${enquiry.first_name || ''} ${enquiry.last_name || ''}`.trim())
                .replace(/\[CLIENT_EMAIL\]/g, enquiry.email || '')
                .replace(/\[CLIENT_PHONE\]/g, enquiry.phone || '')
                .replace(/\[ENQUIRY_ID\]/g, enquiry.id || '')
                .replace(/\[CURRENT_STATE\]/g, getStateName(enquiry.state || ''))
                .replace(/\[BROKER_NAME\]/g, enquiry.broker_name || 'Unassigned');
        }
        
        // View enquiry details
        function viewEnquiry(id) {
            fetch(`/management/ajax/pkv_get_enquiry_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showEnquiryModal(data.enquiry, data.history);
                    } else {
                        showError(data.message || 'Failed to load enquiry details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('An error occurred');
                });
        }
        
        // Show enquiry modal
        function showEnquiryModal(enquiry, history) {
            currentEnquiry = enquiry;
            const modal = document.getElementById('enquiryDetailsModal');
            const modalBody = document.getElementById('modalBody');
            
            let historyHTML = '<div class="activity-log">';
            if (history && history.length > 0) {
                history.forEach(item => {
                    historyHTML += `
                        <div class="activity-item">
                            <div class="activity-header">
                                <span class="activity-user">${escapeHtml(item.user_name || 'System')}</span>
                                <span class="activity-time">${formatDateTime(item.created_at)}</span>
                            </div>
                            <div class="activity-action">${escapeHtml(item.action)}</div>
                            ${item.note ? `<div class="activity-note">${escapeHtml(item.note)}</div>` : ''}
                        </div>
                    `;
                });
            } else {
                historyHTML += '<p style="text-align: center; color: #6b7280;"><?php echo t('pkv.no_history', 'No activity history'); ?></p>';
            }
            historyHTML += '</div>';
            
            modalBody.innerHTML = `
                <div class="detail-section">
                    <div class="detail-header">
                        <div>
                            <h3 class="detail-title">${escapeHtml(enquiry.first_name)} ${escapeHtml(enquiry.last_name)}</h3>
                            <span class="state-badge ${getStateClass(enquiry.state)}">${getStateName(enquiry.state)}</span>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            ${canManageAll ? `
                                <button class="btn btn-secondary btn-sm" onclick="openEditClientModal(${enquiry.id})">
                                    <i class="fas fa-edit"></i> <?php echo t('common.edit', 'Edit'); ?>
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="openChangeBrokerModal(${enquiry.id})">
                                    <i class="fas fa-user-tie"></i> <?php echo t('pkv.change_broker', 'Change Broker'); ?>
                                </button>
                            ` : ''}
                            <button class="btn btn-primary btn-sm" onclick="changeState(${enquiry.id})">
                                <i class="fas fa-exchange-alt"></i> <?php echo t('pkv.change_state', 'Change State'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4 class="detail-subtitle"><?php echo t('pkv.contact_info', 'Contact Information'); ?></h4>
                    <div class="form-row">
                        ${enquiry.email ? `
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.email', 'Email'); ?></label>
                            <div class="form-value"><a href="mailto:${escapeHtml(enquiry.email)}">${escapeHtml(enquiry.email)}</a></div>
                        </div>
                        ` : ''}
                        
                        ${enquiry.phone ? `
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.phone', 'Phone'); ?></label>
                            <div class="form-value"><a href="tel:${escapeHtml(enquiry.phone)}">${escapeHtml(enquiry.phone)}</a></div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.dob', 'Date of Birth'); ?></label>
                            <div class="form-value">${enquiry.date_of_birth ? formatDate(enquiry.date_of_birth) : '-'}</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><?php echo t('pkv.nationality', 'Nationality'); ?></label>
                            <div class="form-value">${escapeHtml(enquiry.nationality || '-')}</div>
                        </div>
                    </div>
                    
                    ${!isExternalBroker ? `
                    <div class="form-group">
                        <label class="form-label"><?php echo t('pkv.assigned_broker', 'Assigned Broker'); ?></label>
                        <div class="form-value">${enquiry.broker_name || '<em><?php echo t('pkv.not_assigned', 'Not assigned'); ?></em>'}</div>
                    </div>
                    ` : ''}
                </div>
                
                ${enquiry.additional_info ? `
                <div class="detail-section">
                    <h4 class="detail-subtitle"><?php echo t('pkv.additional_info', 'Additional Information'); ?></h4>
                    <div class="form-value">${escapeHtml(enquiry.additional_info).replace(/\n/g, '<br>')}</div>
                </div>
                ` : ''}
                
                ${enquiry.notes ? `
                <div class="detail-section">
                    <h4 class="detail-subtitle"><?php echo t('pkv.internal_notes', 'Internal Notes'); ?></h4>
                    <div class="form-value" style="max-height: 200px; overflow-y: auto;">${escapeHtml(enquiry.notes).replace(/\n/g, '<br>')}</div>
                </div>
                ` : ''}
                
                <div class="detail-section">
                    <h4 class="detail-subtitle"><i class="fas fa-history"></i> <?php echo t('pkv.activity_history', 'Activity History'); ?></h4>
                    ${historyHTML}
                </div>
            `;
            
            modal.classList.add('active');
        }
        
        // Close enquiry modal
        function closeEnquiryModal() {
            document.getElementById('enquiryDetailsModal').classList.remove('active');
            currentEnquiry = null;
        }
        
        // Change state
        function changeState(id) {
            const enquiry = enquiriesData.find(e => e.id === id);
            if (!enquiry) {
                // If not in current page data, fetch it
                fetch(`/management/ajax/pkv_get_enquiry_details.php?id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            openStateChangeModal(data.enquiry);
                        }
                    });
                return;
            }
            
            openStateChangeModal(enquiry);
        }
        
        // Open state change modal
        function openStateChangeModal(enquiry) {
            currentEnquiry = enquiry;
            document.getElementById('stateChangeEnquiryId').value = enquiry.id;
            document.getElementById('stateChangeEnquiryData').value = JSON.stringify(enquiry);
            document.getElementById('currentState').value = getStateName(enquiry.state);
            document.getElementById('newState').value = '';
            document.getElementById('stateChangeNote').value = '';
            
            const sendEmailCheck = document.getElementById('sendEmailCheck');
            if (sendEmailCheck) {
                sendEmailCheck.checked = false;
                document.getElementById('emailSection').style.display = 'none';
            }
            
            // Setup broker email option
            if (enquiry.broker_email && !isExternalBroker) {
                document.getElementById('brokerRecipientBox').style.display = 'flex';
                document.getElementById('brokerEmail').textContent = enquiry.broker_email;
                document.getElementById('emailToBroker').value = enquiry.broker_email;
            } else {
                document.getElementById('brokerRecipientBox').style.display = 'none';
            }
            
            // Reset email recipients
            selectedEmails.clear();
            document.querySelectorAll('.email-recipient, #emailToBroker').forEach(cb => cb.checked = false);
            document.getElementById('selectedEmails').innerHTML = '';
            
            updateTemplateOptions();
            
            closeEnquiryModal();
            document.getElementById('stateChangeModal').classList.add('active');
        }
        
        // Close state change modal
        function closeStateChangeModal() {
            document.getElementById('stateChangeModal').classList.remove('active');
            currentEnquiry = null;
        }
        
        // Update template options based on selected state
        function updateTemplateOptions() {
            const newState = document.getElementById('newState').value;
            
            // Update note templates
            const noteTemplateSelect = document.getElementById('noteTemplate');
            noteTemplateSelect.innerHTML = '<option value=""><?php echo t('pkv.select_template', 'Select template...'); ?></option>';
            
            noteTemplates.forEach(template => {
                const applicableStates = template.applicable_states ? template.applicable_states.split(',') : [];
                if (applicableStates.length === 0 || applicableStates.includes(newState)) {
                    const option = document.createElement('option');
                    option.value = template.id;
                    option.textContent = template.title;
                    option.dataset.content = template.content;
                    noteTemplateSelect.appendChild(option);
                }
            });
            
            // Update email templates
            const emailTemplateSelect = document.getElementById('emailTemplate');
            if (emailTemplateSelect) {
                emailTemplateSelect.innerHTML = '<option value=""><?php echo t('pkv.select_template', 'Select template...'); ?></option>';
                
                emailTemplates.forEach(template => {
                    const applicableStates = template.applicable_states ? template.applicable_states.split(',') : [];
                    if (applicableStates.length === 0 || applicableStates.includes(newState)) {
                        const option = document.createElement('option');
                        option.value = template.id;
                        option.textContent = template.title;
                        option.dataset.subject = template.subject;
                        option.dataset.body = template.body;
                        emailTemplateSelect.appendChild(option);
                    }
                });
            }
        }
        
        // Apply note template WITH PLACEHOLDERS
        function applyNoteTemplate() {
            const select = document.getElementById('noteTemplate');
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption.dataset.content && currentEnquiry) {
                const content = replacePlaceholders(selectedOption.dataset.content, currentEnquiry);
                document.getElementById('stateChangeNote').value = content;
            }
        }
        
        // Apply email template WITH PLACEHOLDERS
        function applyEmailTemplate() {
            const select = document.getElementById('emailTemplate');
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption.dataset.subject && currentEnquiry) {
                const subject = replacePlaceholders(selectedOption.dataset.subject, currentEnquiry);
                const body = replacePlaceholders(selectedOption.dataset.body, currentEnquiry);
                document.getElementById('emailSubject').value = subject;
                document.getElementById('emailBody').value = body;
            }
        }
        
        // Update selected emails
        function updateSelectedEmails() {
            selectedEmails.clear();
            
            document.querySelectorAll('.email-recipient:checked, #emailToBroker:checked').forEach(cb => {
                selectedEmails.add(cb.value);
            });
            
            renderSelectedEmails();
        }
        
        // Add manual email
        function addManualEmail() {
            const input = document.getElementById('manualEmail');
            const email = input.value.trim();
            
            if (email && isValidEmail(email)) {
                selectedEmails.add(email);
                input.value = '';
                renderSelectedEmails();
            } else {
                showError('Please enter a valid email address');
            }
        }
        
        // Render selected emails
        function renderSelectedEmails() {
            const container = document.getElementById('selectedEmails');
            if (selectedEmails.size === 0) {
                container.innerHTML = '';
                return;
            }
            
            let html = '';
            selectedEmails.forEach(email => {
                html += `
                    <div class="email-tag">
                        ${escapeHtml(email)}
                        <button type="button" onclick="removeEmail('${escapeHtml(email)}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Remove email
        function removeEmail(email) {
            selectedEmails.delete(email);
            
            // Uncheck corresponding checkbox
            document.querySelectorAll('.email-recipient, #emailToBroker').forEach(cb => {
                if (cb.value === email) cb.checked = false;
            });
            
            renderSelectedEmails();
        }
        
        // Validate email
        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
        
        // Submit state change
        function submitStateChange(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            
            // If external broker, automatically notify admins
            if (isExternalBroker) {
                formData.set('notify_admins', '1');
            }
            
            // Add selected email recipients
            if (!isExternalBroker && selectedEmails.size > 0) {
                formData.set('email_recipients', Array.from(selectedEmails).join(','));
            }
            
            fetch('/management/ajax/pkv_update_state.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('pkv.state_updated', 'State updated successfully'); ?>');
                    closeStateChangeModal();
                    loadEnquiries();
                    if (isAdmin) loadActivityLog();
                } else {
                    showError(data.message || '<?php echo t('pkv.state_update_failed', 'Failed to update state'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        // Open edit client modal
        function openEditClientModal(id) {
            fetch(`/management/ajax/pkv_get_enquiry_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const enquiry = data.enquiry;
                        document.getElementById('editEnquiryId').value = enquiry.id;
                        document.getElementById('editFirstName').value = enquiry.first_name;
                        document.getElementById('editLastName').value = enquiry.last_name;
                        document.getElementById('editEmail').value = enquiry.email || '';
                        document.getElementById('editPhone').value = enquiry.phone || '';
                        document.getElementById('editDOB').value = enquiry.date_of_birth || '';
                        document.getElementById('editNationality').value = enquiry.nationality || '';
                        document.getElementById('editAdditionalInfo').value = enquiry.additional_info || '';
                        
                        closeEnquiryModal();
                        document.getElementById('editClientModal').classList.add('active');
                    }
                });
        }
        
        // Close edit client modal
        function closeEditClientModal() {
            document.getElementById('editClientModal').classList.remove('active');
        }
        
        // Submit edit client
        function submitEditClient(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            
            fetch('/management/ajax/pkv_edit_client.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('pkv.client_updated', 'Client details updated successfully'); ?>');
                    closeEditClientModal();
                    loadEnquiries();
                    if (isAdmin) loadActivityLog();
                } else {
                    showError(data.message || '<?php echo t('pkv.client_update_failed', 'Failed to update client details'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        // Open change broker modal
        function openChangeBrokerModal(id) {
            fetch(`/management/ajax/pkv_get_enquiry_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const enquiry = data.enquiry;
                        document.getElementById('brokerEnquiryId').value = enquiry.id;
                        document.getElementById('currentBroker').value = enquiry.broker_name || '<?php echo t('pkv.not_assigned', 'Not assigned'); ?>';
                        document.getElementById('newBroker').value = '';
                        document.getElementById('brokerChangeNote').value = '';
                        
                        closeEnquiryModal();
                        document.getElementById('changeBrokerModal').classList.add('active');
                    }
                });
        }
        
        // Close change broker modal
        function closeChangeBrokerModal() {
            document.getElementById('changeBrokerModal').classList.remove('active');
        }
        
        // Submit change broker
        function submitChangeBroker(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            
            fetch('/management/ajax/pkv_change_broker.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('pkv.broker_changed', 'Broker changed successfully'); ?>');
                    closeChangeBrokerModal();
                    loadEnquiries();
                    if (isAdmin) loadActivityLog();
                } else {
                    showError(data.message || '<?php echo t('pkv.broker_change_failed', 'Failed to change broker'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        // Load activity log (admin only)
        function loadActivityLog() {
            const container = document.getElementById('activityLogContainer');
            if (!container) return;
            
            fetch('/management/ajax/pkv_get_activity_log.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.logs) {
                        let logsHTML = '<div class="activity-log">';
                        
                        if (data.logs.length > 0) {
                            data.logs.forEach(log => {
                                logsHTML += `
                                    <div class="activity-item">
                                        <div class="activity-header">
                                            <span class="activity-user">
                                                ${escapeHtml(log.user_name || 'System')}
                                                ${log.action.includes('login') ? '<i class="fas fa-sign-in-alt" style="margin-left: 8px; color: #10b981;"></i>' : ''}
                                            </span>
                                            <span class="activity-time">${formatDateTime(log.created_at)}</span>
                                        </div>
                                        <div class="activity-action">
                                            ${log.entity_type === 'pkv_enquiry' && log.entity_id ? `<a href="#" onclick="viewEnquiry(${log.entity_id}); return false;" style="color: #667eea;"><?php echo t('pkv.enquiry', 'Enquiry'); ?> #${log.entity_id}</a>: ` : ''}
                                            ${escapeHtml(log.description)}
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            logsHTML += '<p style="text-align: center; color: #6b7280; padding: 40px;"><?php echo t('pkv.no_activity', 'No recent activity'); ?></p>';
                        }
                        
                        logsHTML += '</div>';
                        container.innerHTML = logsHTML;
                    } else {
                        container.innerHTML = '<p style="text-align: center; color: #6b7280;"><?php echo t('pkv.log_load_failed', 'Failed to load activity log'); ?></p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<p style="text-align: center; color: #6b7280;"><?php echo t('common.error', 'An error occurred'); ?></p>';
                });
        }
        
        // Utility functions
        function getStateClass(state) {
            const classMap = {
                'new': 'state-new',
                'contacted': 'state-contacted',
                'awaiting_client_response': 'state-awaiting-client',
                'awaiting_insurer_response': 'state-awaiting-insurer',
                'documents_pending': 'state-documents-pending',
                'quote_provided': 'state-quote-provided',
                'negotiating': 'state-negotiating',
                'contract_preparation': 'state-contract-prep',
                'contract_sent': 'state-contract-sent',
                'contract_signed': 'state-signed',
                'on_hold': 'state-on-hold',
                'closed_success': 'state-closed-success',
                'closed_failed': 'state-closed-failed'
            };
            return classMap[state] || '';
        }
        
        function getStateName(state) {
            return stateNames[state] || state;
        }
        
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB');
        }
        
        function formatDateTime(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('en-GB');
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showSuccess(message) {
            Swal.fire({
                icon: 'success',
                title: '<?php echo t('common.success', 'Success'); ?>!',
                text: message,
                timer: 3000,
                showConfirmButton: false
            });
        }
        
        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: '<?php echo t('common.error', 'Error'); ?>',
                text: message
            });
        }
    </script>
</body>
</html>
