<?php
require_once 'config.php';
require_once 'api/venues_db_helper.php';
requireLogin();

// Check admin permissions
$isAdmin = isAdmin();
if (!$isAdmin) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$currentUser = getCurrentUser();

// Connect to TEN_Venues database (not TEN_Management)
// TEN Management and TEN Venues are separate databases
$venuesConn = getVenuesDBConnection();
if (!$venuesConn) {
    die("Connection to TEN_Venues database failed. Please check database credentials in api/venues_db_helper.php");
}

// Initialize default values
$totalCompanies = 0;
$activeSubscriptions = 0;
$trialSubscriptions = 0;
$totalMonthlyRevenue = 0;

// Get stats for all companies in Venue Management tool (from TEN_Venues database)
$statsQuery = "SELECT COUNT(DISTINCT c.id) as total_companies FROM companies c WHERE c.type = 'Company' AND c.deleted_at IS NULL";
$statsResult = $venuesConn->query($statsQuery);
if ($statsResult) {
    $row = $statsResult->fetch_assoc();
    $totalCompanies = $row['total_companies'] ?? 0;
} else {
    error_log("Error in totalCompanies query: " . $venuesConn->error);
}

$activeSubsQuery = "SELECT COUNT(*) as count FROM company_subscriptions WHERE is_active = 1 AND subscription_status = 'active'";
$activeSubsResult = $venuesConn->query($activeSubsQuery);
if ($activeSubsResult) {
    $row = $activeSubsResult->fetch_assoc();
    $activeSubscriptions = $row['count'] ?? 0;
} else {
    error_log("Error in activeSubscriptions query: " . $venuesConn->error);
}

$trialSubsQuery = "SELECT COUNT(*) as count FROM company_subscriptions WHERE is_active = 1 AND subscription_status = 'trial'";
$trialSubsResult = $venuesConn->query($trialSubsQuery);
if ($trialSubsResult) {
    $row = $trialSubsResult->fetch_assoc();
    $trialSubscriptions = $row['count'] ?? 0;
} else {
    error_log("Error in trialSubscriptions query: " . $venuesConn->error);
}

$totalRevenueQuery = "SELECT SUM(current_monthly_total_eur) as total FROM company_subscriptions WHERE is_active = 1 AND subscription_status IN ('active', 'trial')";
$totalRevenueResult = $venuesConn->query($totalRevenueQuery);
if ($totalRevenueResult) {
    $row = $totalRevenueResult->fetch_assoc();
    $totalMonthlyRevenue = $row['total'] ?? 0;
} else {
    error_log("Error in totalRevenue query: " . $venuesConn->error);
}

// Get pricing package for settings
$pricingQuery = "SELECT * FROM pricing_packages WHERE is_active = 1 LIMIT 1";
$pricingResult = $venuesConn->query($pricingQuery);
if ($pricingResult && $pricingResult->num_rows > 0) {
    $pricingPackage = $pricingResult->fetch_assoc();
} else {
    error_log("Error in pricingPackage query: " . $venuesConn->error);
    // Set default values if no pricing package found
    $pricingPackage = [
        'base_monthly_fee_eur' => '19.99',
        'cost_per_extra_brand_page_eur' => '8.00',
        'cost_per_extra_venue_page_eur' => '5.00',
        'cost_per_subcompany_eur' => '10.00',
        'free_brand_pages' => 1,
        'free_venue_pages' => 2
    ];
}

$venuesConn->close();

$currentPage = 'venues-tool';
$pageTitle = t('venues_tool.title', 'Venue Management Tool Administration');
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
        
        .venues-tool-container {
            max-width: 100%;
            padding: 20px;
        }
        
        @media (min-width: 768px) {
            .venues-tool-container {
                padding: 30px;
            }
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 0px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #2563eb;
        }
        
        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #eff6ff;
            color: #2563eb;
        }
        
        .stat-title {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        .stat-change {
            font-size: 13px;
            color: #10b981;
            margin-top: 4px;
        }
        
        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 24px;
            overflow-x: auto;
        }
        
        .tab-btn {
            padding: 12px 20px;
            background: transparent;
            border: none;
            color: #6b7280;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
            font-size: 15px;
        }
        
        .tab-btn:hover {
            color: #2563eb;
        }
        
        .tab-btn.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e5e7eb;
            padding: 8px 16px;
            width: 100%;
            max-width: 400px;
        }
        
        .search-box input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 14px;
            padding: 4px;
        }
        
        .search-box i {
            color: #9ca3af;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 2px solid #e5e7eb;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        /* Table Styles */
        .data-table {
            width: 100%;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .company-name {
            font-weight: 600;
            color: #111827;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-trial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-suspended {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-inactive {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .status-urgent {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-high {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .status-normal {
            background: #e0e7ff;
            color: #3730a3;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            padding: 8px 12px;
            background: #f3f4f6;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            color: #6b7280;
        }
        
        .btn-icon:hover {
            background: #e5e7eb;
            color: #111827;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #9ca3af;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            color: #111827;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e5e7eb;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 2px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* Settings Card */
        .settings-card {
            background: white;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .settings-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .settings-card h3 i {
            color: #2563eb;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .settings-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .info-box {
            background: #f9fafb;
            padding: 16px;
            border-left: 4px solid #2563eb;
            margin-bottom: 20px;
        }
        
        .info-box p {
            margin: 0;
            color: #4b5563;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
    
    <div class="venues-tool-container">
        <div class="page-header">
            <h1><?php echo t('venues_tool.title', 'Venue Management Tool Administration'); ?></h1>
            <p><?php echo t('venues_tool.subtitle', 'Manage companies, subscriptions, pricing, and settings for the Venue Management platform'); ?></p>
        </div>
        
        <?php if ($totalCompanies == 0): ?>
        <div style="background: #fef3c7; border: 2px solid #f59e0b; padding: 16px; margin-bottom: 24px; border-radius: 8px;">
            <p style="margin: 0; color: #92400e; font-weight: 600;">
                <i class="fas fa-exclamation-triangle"></i> 
                No companies found in TEN_Venues database. This could mean:
            </p>
            <ul style="margin: 8px 0 0 24px; color: #92400e;">
                <li>The TEN_Venues database is empty</li>
                <li>Database connection credentials are incorrect</li>
                <li>You need to import sample data or create companies</li>
            </ul>
            <p style="margin: 8px 0 0 0; color: #92400e;">
                <strong>Debug:</strong> Check /management/api/venues_db_helper.php for connection settings
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-title"><?php echo t('venues_tool.total_companies', 'Total Companies'); ?></div>
                </div>
                <div class="stat-value" id="totalCompanies"><?php echo number_format($totalCompanies); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-title"><?php echo t('venues_tool.active_subscriptions', 'Active Subscriptions'); ?></div>
                </div>
                <div class="stat-value" id="activeSubscriptions"><?php echo number_format($activeSubscriptions); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon">
                        <i class="fas fa-flask"></i>
                    </div>
                    <div class="stat-title"><?php echo t('venues_tool.trial_accounts', 'Trial Accounts'); ?></div>
                </div>
                <div class="stat-value" id="trialAccounts"><?php echo number_format($trialSubscriptions); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-icon">
                        <i class="fas fa-euro-sign"></i>
                    </div>
                    <div class="stat-title"><?php echo t('venues_tool.monthly_revenue', 'Monthly Revenue'); ?></div>
                </div>
                <div class="stat-value" id="monthlyRevenue">€<?php echo number_format($totalMonthlyRevenue, 2); ?></div>
            </div>
        </div>
        
        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="switchTab('companies')"><?php echo t('venues_tool.tab_companies', 'Companies'); ?></button>
            <button class="tab-btn" onclick="switchTab('subscriptions')"><?php echo t('venues_tool.tab_subscriptions', 'Subscriptions'); ?></button>
            <button class="tab-btn" onclick="switchTab('pricing')"><?php echo t('venues_tool.tab_pricing', 'Pricing & Limits'); ?></button>
            <button class="tab-btn" onclick="switchTab('support')"><?php echo t('venues_tool.tab_support', 'Support Tickets'); ?></button>
            <button class="tab-btn" onclick="switchTab('analytics')"><?php echo t('venues_tool.tab_analytics', 'Analytics'); ?></button>
        </div>
        
        <!-- Companies Tab -->
        <div id="companiesTab" class="tab-content active">
            <div class="action-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="companySearch" placeholder="<?php echo t('venues_tool.search_companies', 'Search companies...'); ?>" onkeyup="filterCompanies()">
                </div>
                <div>
                    <button class="btn btn-primary" onclick="openCreateCompanyModal()">
                        <i class="fas fa-plus"></i>
                        <?php echo t('venues_tool.create_company', 'Create Company'); ?>
                    </button>
                </div>
            </div>
            
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('venues_tool.company_name', 'Company Name'); ?></th>
                            <th><?php echo t('venues_tool.email', 'Email'); ?></th>
                            <th><?php echo t('venues_tool.subscription', 'Subscription'); ?></th>
                            <th><?php echo t('venues_tool.entities', 'Entities'); ?></th>
                            <th><?php echo t('venues_tool.monthly_fee', 'Monthly Fee'); ?></th>
                            <th><?php echo t('venues_tool.actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="companiesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Subscriptions Tab -->
        <div id="subscriptionsTab" class="tab-content">
            <div class="action-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="subscriptionSearch" placeholder="<?php echo t('venues_tool.search_subscriptions', 'Search subscriptions...'); ?>" onkeyup="filterSubscriptions()">
                </div>
            </div>
            
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('venues_tool.company_name', 'Company Name'); ?></th>
                            <th><?php echo t('venues_tool.package', 'Package'); ?></th>
                            <th><?php echo t('venues_tool.status', 'Status'); ?></th>
                            <th><?php echo t('venues_tool.last_updated', 'Last Updated'); ?></th>
                            <th><?php echo t('venues_tool.next_billing', 'Next Billing'); ?></th>
                            <th><?php echo t('venues_tool.total', 'Total'); ?></th>
                            <th><?php echo t('venues_tool.actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="subscriptionsTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Pricing & Limits Tab -->
        <div id="pricingTab" class="tab-content">
            <div class="settings-card">
                <h3><i class="fas fa-dollar-sign"></i> <?php echo t('venues_tool.pricing_structure', 'Pricing Structure'); ?></h3>
                
                <form id="pricingForm" onsubmit="updatePricing(event)">
                    <div class="settings-grid">
                        <div class="form-group">
                            <label><?php echo t('venues_tool.base_monthly_fee', 'Base Monthly Fee (€)'); ?></label>
                            <input type="number" step="0.01" name="base_monthly_fee" value="<?php echo $pricingPackage['base_monthly_fee_eur'] ?? '19.99'; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo t('venues_tool.cost_per_brand', 'Cost per Extra Brand Page (€)'); ?></label>
                            <input type="number" step="0.01" name="cost_per_brand" value="<?php echo $pricingPackage['cost_per_extra_brand_page_eur'] ?? '8.00'; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo t('venues_tool.cost_per_venue', 'Cost per Extra Venue Page (€)'); ?></label>
                            <input type="number" step="0.01" name="cost_per_venue" value="<?php echo $pricingPackage['cost_per_extra_venue_page_eur'] ?? '5.00'; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo t('venues_tool.cost_per_subcompany', 'Cost per Subcompany (€)'); ?></label>
                            <input type="number" step="0.01" name="cost_per_subcompany" value="<?php echo $pricingPackage['cost_per_subcompany_eur'] ?? '10.00'; ?>" required>
                        </div>
                    </div>
                    
                    <h3 style="margin-top: 32px;"><i class="fas fa-gift"></i> <?php echo t('venues_tool.free_allowances', 'Free Allowances'); ?></h3>
                    
                    <div class="settings-grid">
                        <div class="form-group">
                            <label><?php echo t('venues_tool.free_brand_pages', 'Free Brand Pages'); ?></label>
                            <input type="number" name="free_brand_pages" value="<?php echo $pricingPackage['free_brand_pages'] ?? '1'; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo t('venues_tool.free_venue_pages', 'Free Venue Pages'); ?></label>
                            <input type="number" name="free_venue_pages" value="<?php echo $pricingPackage['free_venue_pages'] ?? '2'; ?>" required>
                        </div>
                    </div>
                    
                    <div class="modal-footer" style="border-top: 2px solid #e5e7eb; margin-top: 24px; padding-top: 24px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo t('common.save_changes', 'Save Changes'); ?>
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="settings-card">
                <h3><i class="fas fa-flask"></i> <?php echo t('venues_tool.trial_settings', 'Trial Period Settings'); ?></h3>
                
                <form id="trialForm" onsubmit="updateTrialSettings(event)">
                    <div class="info-box">
                        <p><?php echo t('venues_tool.trial_info', 'Configure the free trial period duration and features available during trials.'); ?></p>
                    </div>
                    
                    <div class="settings-grid">
                        <div class="form-group">
                            <label><?php echo t('venues_tool.trial_duration', 'Trial Duration (days)'); ?></label>
                            <input type="number" name="trial_duration" value="14" required>
                        </div>
                        
                        <div class="form-group">
                            <label><?php echo t('venues_tool.trial_features', 'Trial Features Access'); ?></label>
                            <select name="trial_features">
                                <option value="full"><?php echo t('venues_tool.full_access', 'Full Access'); ?></option>
                                <option value="limited"><?php echo t('venues_tool.limited_access', 'Limited Access'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer" style="border-top: 2px solid #e5e7eb; margin-top: 24px; padding-top: 24px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo t('common.save_changes', 'Save Changes'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Support Tickets Tab -->
        <div id="supportTab" class="tab-content">
            <div class="action-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="ticketSearch" placeholder="<?php echo t('venues_tool.search_tickets', 'Search tickets...'); ?>" onkeyup="filterTickets()">
                </div>
                <div style="display: flex; gap: 12px;">
                    <select id="statusFilter" onchange="filterTicketsByStatus()" style="padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                        <option value="all"><?php echo t('venues_tool.all_statuses', 'All Statuses'); ?></option>
                        <option value="open"><?php echo t('venues_tool.status_open', 'Open'); ?></option>
                        <option value="in_progress"><?php echo t('venues_tool.status_in_progress', 'In Progress'); ?></option>
                        <option value="resolved"><?php echo t('venues_tool.status_resolved', 'Resolved'); ?></option>
                        <option value="closed"><?php echo t('venues_tool.status_closed', 'Closed'); ?></option>
                    </select>
                </div>
            </div>
            
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('venues_tool.ticket_id', 'Ticket #'); ?></th>
                            <th><?php echo t('venues_tool.company', 'Company'); ?></th>
                            <th><?php echo t('venues_tool.subject', 'Subject'); ?></th>
                            <th><?php echo t('venues_tool.status', 'Status'); ?></th>
                            <th><?php echo t('venues_tool.priority', 'Priority'); ?></th>
                            <th><?php echo t('venues_tool.created', 'Created'); ?></th>
                            <th><?php echo t('venues_tool.actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ticketsTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Analytics Tab -->
        <div id="analyticsTab" class="tab-content">
            <div class="settings-card">
                <h3><i class="fas fa-chart-line"></i> <?php echo t('venues_tool.subscription_analytics', 'Subscription Analytics'); ?></h3>
                <div id="analyticsContent">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Company Modal -->
    <div id="createCompanyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('venues_tool.create_new_company', 'Create New Company Account'); ?></h2>
                <button class="modal-close" onclick="closeCreateCompanyModal()">&times;</button>
            </div>
            <form id="createCompanyForm" onsubmit="submitCreateCompany(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.company_name', 'Company Name'); ?> *</label>
                        <input type="text" name="company_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.admin_email', 'Admin Email'); ?> *</label>
                        <input type="email" name="admin_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.admin_name', 'Admin Full Name'); ?> *</label>
                        <input type="text" name="admin_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.password', 'Password'); ?> *</label>
                        <input type="password" name="password" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.subscription_type', 'Subscription Type'); ?></label>
                        <select name="subscription_type">
                            <option value="trial"><?php echo t('venues_tool.trial_period', 'Trial Period'); ?></option>
                            <option value="active"><?php echo t('venues_tool.active_subscription', 'Active Subscription'); ?></option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.send_welcome_email', 'Send Welcome Email'); ?></label>
                        <select name="send_email">
                            <option value="1"><?php echo t('common.yes', 'Yes'); ?></option>
                            <option value="0"><?php echo t('common.no', 'No'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateCompanyModal()"><?php echo t('common.cancel', 'Cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo t('common.create', 'Create'); ?></button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Company Details Modal -->
    <div id="viewCompanyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('venues_tool.company_details', 'Company Details'); ?></h2>
                <button class="modal-close" onclick="closeViewCompanyModal()">&times;</button>
            </div>
            <div class="modal-body" id="companyDetailsContent">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewCompanyModal()"><?php echo t('common.close', 'Close'); ?></button>
            </div>
        </div>
    </div>
    
    <!-- View Support Ticket Modal -->
    <div id="viewTicketModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2><i class="fas fa-ticket-alt"></i> <?php echo t('venues_tool.ticket_details', 'Ticket Details'); ?></h2>
                <button class="modal-close" onclick="closeTicketModal()">&times;</button>
            </div>
            <div class="modal-body" id="ticketDetailsContent">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTicketModal()"><?php echo t('common.close', 'Close'); ?></button>
            </div>
        </div>
    </div>
    
    <!-- Send Email Modal -->
    <div id="sendEmailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-envelope"></i> <?php echo t('venues_tool.send_email_to', 'Send Email'); ?></h2>
                <button class="modal-close" onclick="closeSendEmailModal()">&times;</button>
            </div>
            <form id="sendEmailForm" onsubmit="submitSendEmail(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.company', 'Company'); ?></label>
                        <p id="sendEmailCompanyName" style="font-weight: 600; margin: 8px 0;"></p>
                        <input type="hidden" name="company_id" id="sendEmailCompanyId">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.recipient', 'Recipient'); ?></label>
                        <select name="user_id" id="emailRecipient" required>
                            <!-- Populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.subject', 'Subject'); ?></label>
                        <input type="text" name="subject" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('venues_tool.message', 'Message'); ?></label>
                        <textarea name="message" rows="8" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeSendEmailModal()"><?php echo t('common.cancel', 'Cancel'); ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> <?php echo t('venues_tool.send', 'Send'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.target.classList.add('active');
            
            // Load tab data
            if (tabName === 'companies') {
                loadCompanies();
            } else if (tabName === 'subscriptions') {
                loadSubscriptions();
            } else if (tabName === 'support') {
                loadTickets();
            } else if (tabName === 'analytics') {
                loadAnalytics();
            }
        }
        
        // Load companies
        function loadCompanies() {
            fetch('/management/api/venues_tool_get_companies.php')
                .then(response => {
                    console.log('Companies API Response Status:', response.status);
                    if (!response.ok) {
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Companies API Data:', data);
                    if (data.success) {
                        renderCompanies(data.companies);
                    } else {
                        console.error('API returned success=false:', data.message);
                        showError(data.message || 'Failed to load companies');
                    }
                })
                .catch(error => {
                    console.error('Error loading companies:', error);
                    showError('Failed to load companies. Check console for details.');
                });
        }
        
        // Render companies table
        function renderCompanies(companies) {
            const tbody = document.getElementById('companiesTableBody');
            let html = '';
            
            companies.forEach(company => {
                const statusClass = company.subscription_status === 'active' ? 'status-active' : 
                                  company.subscription_status === 'trial' ? 'status-trial' : 
                                  company.subscription_status === 'suspended' ? 'status-suspended' : 'status-inactive';
                
                html += `
                    <tr>
                        <td class="company-name">${escapeHtml(company.name)}</td>
                        <td>${escapeHtml(company.admin_email || '-')}</td>
                        <td><span class="status-badge ${statusClass}">${company.subscription_status}</span></td>
                        <td>
                            <small>
                                B: ${company.brand_count} | 
                                V: ${company.venue_count} | 
                                S: ${company.subcompany_count} | 
                                St: ${company.staff_count} | 
                                E: ${company.event_count} | 
                                A: ${company.article_count}
                            </small>
                        </td>
                        <td>€${parseFloat(company.monthly_fee).toFixed(2)}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon" onclick="viewCompany(${company.id})" title="<?php echo t('common.view', 'View'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon" onclick="resetPassword(${company.id})" title="<?php echo t('venues_tool.reset_password', 'Reset Password'); ?>">
                                    <i class="fas fa-key"></i>
                                </button>
                                <button class="btn-icon" onclick="sendEmail(${company.id})" title="<?php echo t('venues_tool.send_email', 'Send Email'); ?>">
                                    <i class="fas fa-envelope"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html || '<tr><td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;"><?php echo t('venues_tool.no_companies', 'No companies found'); ?></td></tr>';
        }
        
        // Filter companies
        function filterCompanies() {
            const search = document.getElementById('companySearch').value.toLowerCase();
            const rows = document.querySelectorAll('#companiesTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        }
        
        // Load subscriptions
        function loadSubscriptions() {
            fetch('/management/api/venues_tool_get_subscriptions.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderSubscriptions(data.subscriptions);
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Render subscriptions table
        function renderSubscriptions(subscriptions) {
            const tbody = document.getElementById('subscriptionsTableBody');
            let html = '';
            
            subscriptions.forEach(sub => {
                const statusClass = sub.subscription_status === 'active' ? 'status-active' : 
                                  sub.subscription_status === 'trial' ? 'status-trial' : 
                                  sub.subscription_status === 'suspended' ? 'status-suspended' : 'status-inactive';
                
                html += `
                    <tr>
                        <td class="company-name">${escapeHtml(sub.company_name)}</td>
                        <td>${escapeHtml(sub.package_name || 'Standard')}</td>
                        <td><span class="status-badge ${statusClass}">${sub.subscription_status}</span></td>
                        <td>${formatDate(sub.last_updated)}</td>
                        <td>${sub.next_billing_date ? formatDate(sub.next_billing_date) : '-'}</td>
                        <td>€${parseFloat(sub.current_monthly_total_eur).toFixed(2)}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon" onclick="viewSubscription(${sub.company_id})" title="<?php echo t('common.view', 'View'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon" onclick="modifySubscription(${sub.company_id})" title="<?php echo t('venues_tool.modify', 'Modify'); ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html || '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;"><?php echo t('venues_tool.no_subscriptions', 'No subscriptions found'); ?></td></tr>';
        }
        
        // Filter subscriptions
        function filterSubscriptions() {
            const search = document.getElementById('subscriptionSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#subscriptionsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        }
        
        // Load analytics
        function loadAnalytics() {
            fetch('/management/api/venues_tool_get_analytics.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderAnalytics(data.analytics);
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Render analytics
        function renderAnalytics(analytics) {
            const content = document.getElementById('analyticsContent');
            let html = `
                <div class="settings-grid">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.total_revenue', 'Total Monthly Revenue'); ?></label>
                        <h2 style="margin: 8px 0; color: #2563eb;">€${parseFloat(analytics.total_revenue).toFixed(2)}</h2>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.avg_revenue_per_company', 'Average Revenue per Company'); ?></label>
                        <h2 style="margin: 8px 0; color: #2563eb;">€${parseFloat(analytics.avg_revenue).toFixed(2)}</h2>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.total_entities', 'Total Entities'); ?></label>
                        <h2 style="margin: 8px 0; color: #2563eb;">${analytics.total_entities}</h2>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.conversion_rate', 'Trial to Active Conversion Rate'); ?></label>
                        <h2 style="margin: 8px 0; color: #2563eb;">${parseFloat(analytics.conversion_rate).toFixed(1)}%</h2>
                    </div>
                </div>
            `;
            content.innerHTML = html;
        }
        
        // Modal functions
        function openCreateCompanyModal() {
            document.getElementById('createCompanyModal').classList.add('active');
        }
        
        function closeCreateCompanyModal() {
            document.getElementById('createCompanyModal').classList.remove('active');
            document.getElementById('createCompanyForm').reset();
        }
        
        function submitCreateCompany(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('/management/api/venues_tool_create_company.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('venues_tool.company_created', 'Company created successfully'); ?>');
                    closeCreateCompanyModal();
                    loadCompanies();
                } else {
                    showError(data.message || '<?php echo t('venues_tool.company_creation_failed', 'Failed to create company'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        function viewCompany(companyId) {
            fetch(`/management/api/venues_tool_get_company_details.php?id=${companyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderCompanyDetails(data.company);
                        document.getElementById('viewCompanyModal').classList.add('active');
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function renderCompanyDetails(company) {
            const content = document.getElementById('companyDetailsContent');
            const statusClass = company.subscription_status === 'active' ? 'status-active' : 
                              company.subscription_status === 'trial' ? 'status-trial' : 
                              company.subscription_status === 'suspended' ? 'status-suspended' : 'status-inactive';
            
            let html = `
                <div style="margin-bottom: 24px;">
                    <h3 style="margin: 0 0 8px 0;">${escapeHtml(company.name)}</h3>
                    <span class="status-badge ${statusClass}">${company.subscription_status}</span>
                </div>
                
                <div class="settings-grid">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.admin_email', 'Admin Email'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${escapeHtml(company.admin_email)}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.created_date', 'Created Date'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${formatDate(company.created_at)}</p>
                    </div>
                </div>
                
                <h4 style="margin: 24px 0 16px 0; border-top: 2px solid #e5e7eb; padding-top: 24px;"><?php echo t('venues_tool.entity_breakdown', 'Entity Breakdown'); ?></h4>
                <div class="settings-grid">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.brands', 'Brands'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.brand_count}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.venues', 'Venues'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.venue_count}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.subcompanies', 'Subcompanies'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.subcompany_count}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.staff', 'Staff Members'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.staff_count}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.events', 'Events'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.event_count}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.articles', 'Articles'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${company.article_count}</p>
                    </div>
                </div>
                
                <h4 style="margin: 24px 0 16px 0; border-top: 2px solid #e5e7eb; padding-top: 24px;"><?php echo t('venues_tool.billing_info', 'Billing Information'); ?></h4>
                <div class="form-group">
                    <label><?php echo t('venues_tool.monthly_fee', 'Monthly Fee'); ?></label>
                    <h2 style="margin: 8px 0; color: #2563eb;">€${parseFloat(company.monthly_fee).toFixed(2)}</h2>
                </div>
            `;
            
            content.innerHTML = html;
        }
        
        function closeViewCompanyModal() {
            document.getElementById('viewCompanyModal').classList.remove('active');
        }
        
        function resetPassword(companyId) {
            Swal.fire({
                title: '<?php echo t('venues_tool.reset_password_confirm', 'Reset Password?'); ?>',
                text: '<?php echo t('venues_tool.reset_password_text', 'A new password will be generated and sent to the company admin email'); ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<?php echo t('common.yes', 'Yes'); ?>',
                cancelButtonText: '<?php echo t('common.cancel', 'Cancel'); ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/management/api/venues_tool_reset_password.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({company_id: companyId})
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message || '<?php echo t('venues_tool.password_reset_sent', 'Password reset email sent'); ?>');
                        } else {
                            showError(data.message || '<?php echo t('venues_tool.password_reset_failed', 'Failed to reset password'); ?>');
                        }
                    });
                }
            });
        }
        
        function sendEmail(companyId) {
            fetch(`/management/api/venues_tool_get_company_users.php?company_id=${companyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        openSendEmailModal(companyId, data.users, data.company_name);
                    } else {
                        showError(data.message || 'Failed to load company users');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Failed to load company users');
                });
        }
        
        function openSendEmailModal(companyId, users, companyName) {
            const modal = document.getElementById('sendEmailModal');
            document.getElementById('sendEmailCompanyId').value = companyId;
            document.getElementById('sendEmailCompanyName').textContent = companyName;
            
            const userSelect = document.getElementById('emailRecipient');
            userSelect.innerHTML = users.map(user => 
                `<option value="${user.id}">${user.username} (${user.email})${user.position ? ' - ' + user.position : ''}</option>`
            ).join('');
            
            modal.classList.add('active');
        }
        
        function closeSendEmailModal() {
            document.getElementById('sendEmailModal').classList.remove('active');
            document.getElementById('sendEmailForm').reset();
        }
        
        function submitSendEmail(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('/management/api/venues_tool_send_user_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || 'Email sent successfully');
                    closeSendEmailModal();
                } else {
                    showError(data.message || 'Failed to send email');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('Failed to send email');
            });
        }
        
        function updatePricing(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('/management/api/venues_tool_update_pricing.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('venues_tool.pricing_updated', 'Pricing updated successfully'); ?>');
                } else {
                    showError(data.message || '<?php echo t('venues_tool.pricing_update_failed', 'Failed to update pricing'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        function updateTrialSettings(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('/management/api/venues_tool_update_trial_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('venues_tool.trial_settings_updated', 'Trial settings updated successfully'); ?>');
                } else {
                    showError(data.message || '<?php echo t('venues_tool.trial_settings_failed', 'Failed to update trial settings'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        function sendMessage(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('/management/api/venues_tool_send_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess(data.message || '<?php echo t('venues_tool.message_sent', 'Message sent successfully'); ?>');
                    event.target.reset();
                } else {
                    showError(data.message || '<?php echo t('venues_tool.message_failed', 'Failed to send message'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        // Utility functions
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB');
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
        
        
        // Support Tickets Functions
        function loadTickets() {
            fetch('/management/api/venues_tool_get_tickets.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Tickets API Data:', data);
                    if (data.success) {
                        renderTickets(data.tickets);
                    } else {
                        console.error('Failed to load tickets:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading tickets:', error);
                });
        }
        
        function renderTickets(tickets) {
            const tbody = document.getElementById('ticketsTableBody');
            let html = '';
            
            tickets.forEach(ticket => {
                const statusClass = ticket.status === 'open' ? 'status-active' : 
                                  ticket.status === 'in_progress' ? 'status-trial' : 
                                  ticket.status === 'resolved' ? 'status-suspended' : 'status-inactive';
                
                const priorityClass = ticket.priority === 'urgent' ? 'status-urgent' : 
                                    ticket.priority === 'high' ? 'status-high' : 'status-normal';
                
                html += `
                    <tr>
                        <td><strong>#${ticket.id}</strong></td>
                        <td class="company-name">${escapeHtml(ticket.company_name)}</td>
                        <td>${escapeHtml(ticket.subject)}</td>
                        <td><span class="status-badge ${statusClass}">${ticket.status}</span></td>
                        <td><span class="status-badge ${priorityClass}">${ticket.priority}</span></td>
                        <td>${formatDate(ticket.created_at)}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon" onclick="viewTicket(${ticket.id})" title="<?php echo t('common.view', 'View'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon" onclick="updateTicketStatus(${ticket.id}, 'resolved')" title="<?php echo t('venues_tool.mark_resolved', 'Mark Resolved'); ?>">
                                    <i class="fas fa-check"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html || '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;"><?php echo t('venues_tool.no_tickets', 'No support tickets found'); ?></td></tr>';
        }
        
        function filterTickets() {
            const search = document.getElementById('ticketSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#ticketsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        }
        
        function filterTicketsByStatus() {
            const status = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('#ticketsTableBody tr');
            
            rows.forEach(row => {
                if (status === 'all') {
                    row.style.display = '';
                } else {
                    const statusBadge = row.querySelector('.status-badge');
                    if (statusBadge && statusBadge.textContent.toLowerCase() === status) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        }
        
        function viewTicket(ticketId) {
            fetch(`/management/api/venues_tool_get_ticket_details.php?id=${ticketId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayTicketDetails(data.ticket, data.replies);
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function displayTicketDetails(ticket, replies) {
            const content = document.getElementById('ticketDetailsContent');
            
            let repliesHtml = '';
            if (replies && replies.length > 0) {
                repliesHtml = '<div style="margin-top: 24px; border-top: 2px solid #e5e7eb; padding-top: 24px;"><h4 style="margin-bottom: 16px;">Replies</h4>';
                replies.forEach(reply => {
                    const isAdmin = reply.is_admin == 1;
                    repliesHtml += `
                        <div style="background: ${isAdmin ? '#eff6ff' : '#f9fafb'}; padding: 16px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid ${isAdmin ? '#2563eb' : '#9ca3af'};">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <strong style="color: #374151;">${isAdmin ? 'Admin Response' : ticket.user_name}</strong>
                                <span style="color: #6b7280; font-size: 13px;">${formatDate(reply.created_at)}</span>
                            </div>
                            <p style="margin: 0; color: #4b5563; white-space: pre-wrap;">${escapeHtml(reply.message)}</p>
                        </div>
                    `;
                });
                repliesHtml += '</div>';
            }
            
            const html = `
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">
                    <div class="form-group">
                        <label><?php echo t('venues_tool.ticket_id', 'Ticket ID'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">#${ticket.id}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.status', 'Status'); ?></label>
                        <p style="margin: 8px 0;"><span class="status-badge">${ticket.status}</span></p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.company', 'Company'); ?></label>
                        <p style="margin: 8px 0; font-weight: 600;">${escapeHtml(ticket.company_name)}</p>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('venues_tool.created', 'Created'); ?></label>
                        <p style="margin: 8px 0;">${formatDate(ticket.created_at)}</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><?php echo t('venues_tool.subject', 'Subject'); ?></label>
                    <p style="margin: 8px 0; font-weight: 600;">${escapeHtml(ticket.subject)}</p>
                </div>
                
                <div class="form-group">
                    <label><?php echo t('venues_tool.message', 'Message'); ?></label>
                    <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-top: 8px;">
                        <p style="margin: 0; white-space: pre-wrap; color: #374151;">${escapeHtml(ticket.message)}</p>
                    </div>
                </div>
                
                ${repliesHtml}
                
                <div class="form-group" style="margin-top: 24px;">
                    <label><?php echo t('venues_tool.add_reply', 'Add Reply'); ?></label>
                    <textarea id="replyMessage" rows="4" style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 14px;"></textarea>
                    <button onclick="sendReply(${ticket.id})" class="btn btn-primary" style="margin-top: 12px;">
                        <i class="fas fa-reply"></i> <?php echo t('venues_tool.send_reply', 'Send Reply'); ?>
                    </button>
                </div>
            `;
            
            content.innerHTML = html;
            document.getElementById('viewTicketModal').classList.add('active');
        }
        
        function closeTicketModal() {
            document.getElementById('viewTicketModal').classList.remove('active');
        }
        
        function sendReply(ticketId) {
            const message = document.getElementById('replyMessage').value;
            if (!message.trim()) {
                showError('<?php echo t('venues_tool.reply_required', 'Please enter a reply message'); ?>');
                return;
            }
            
            fetch('/management/api/venues_tool_reply_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    ticket_id: ticketId,
                    message: message
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('<?php echo t('venues_tool.reply_sent', 'Reply sent successfully'); ?>');
                    closeTicketModal();
                    loadTickets();
                } else {
                    showError(data.message || '<?php echo t('venues_tool.reply_failed', 'Failed to send reply'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        function updateTicketStatus(ticketId, status) {
            fetch('/management/api/venues_tool_update_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    ticket_id: ticketId,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('<?php echo t('venues_tool.ticket_updated', 'Ticket updated successfully'); ?>');
                    loadTickets();
                } else {
                    showError(data.message || '<?php echo t('venues_tool.update_failed', 'Failed to update ticket'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('<?php echo t('common.error', 'An error occurred'); ?>');
            });
        }
        
        // Load companies on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
        });
    </script>
</body>
</html>
