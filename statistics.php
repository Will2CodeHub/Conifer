<?php
require_once 'config.php';
requireLogin();

// Site configuration. KEYS ARE THE CANONICAL PUBLICATION ACRONYMS (same as
// admin_ten.publications / articles.publications / the scraper), so the site_key
// stored by cron_collect_stats matches what this page queries. Keep this list
// and ajax/traffic_stats.php's $sites identical.
$sites = [
    'ten'   => ['name' => 'The Eye Newspapers', 'log_path' => '/home/tenuser/private/unique_visitors_count.txt'],
    'tme'   => ['name' => 'The Munich Eye',     'log_path' => '/home/tmeuser/private/unique_visitors_count.txt'],
    'tge'   => ['name' => 'The Germany Eye',    'log_path' => '/home/tgeuser/private/unique_visitors_count.txt'],
    'bae'   => ['name' => 'Buenos Aires Eye',   'log_path' => '/home/baeuser/private/unique_visitors_count.txt'],
    'tbare' => ['name' => 'The Barcelona Eye',  'log_path' => '/home/tbareuse/private/unique_visitors_count.txt'],
    'tbrae' => ['name' => 'The Brazil Eye',     'log_path' => '/home/tbeuser/private/unique_visitors_count.txt'],
    'tce'   => ['name' => 'The Canary Eye',     'log_path' => '/home/tceuser/private/unique_visitors_count.txt'],
    'tmae'  => ['name' => 'The Madrid Eye',     'log_path' => '/home/tmaeuser/private/unique_visitors_count.txt'],
    'truse' => ['name' => 'The Russia Eye',     'log_path' => '/home/treuser/private/unique_visitors_count.txt'],
    'tte'   => ['name' => 'The Tokyo Eye',      'log_path' => '/home/tteuser/private/unique_visitors_count.txt'],
    'tpe'   => ['name' => 'The Paris Eye',      'log_path' => '/home/tpeuser/private/unique_visitors_count.txt'],
    'tbere' => ['name' => 'The Berlin Eye',     'log_path' => '/home/tberuser/private/unique_visitors_count.txt'],
];

$currentPage = 'statistics';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('statistics.title', 'Statistics'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .traffic-controls {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .control-group {
            flex: 1;
            min-width: 200px;
        }
        .control-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .control-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #111827;
        }
        .control-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .summary-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .summary-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .summary-value {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .summary-label {
            font-size: 13px;
            color: #9ca3af;
        }
        .summary-projection {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #6b7280;
        }
        .summary-projection strong {
            color: #111827;
        }
        .info-box {
            background: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
            font-size: 13px;
            color: #374151;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .info-box i {
            color: #667eea;
            margin-top: 2px;
        }
        .info-item {
            margin-bottom: 4px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .chart-card h2 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-card canvas {
            max-height: 300px !important;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .table-controls {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        .table-controls input {
            flex: 1;
            min-width: 250px;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .table-controls select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .data-table thead {
            background: #f9fafb;
        }
        .data-table th {
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
        }
        .data-table th:hover {
            background: #f3f4f6;
        }
        .data-table th i {
            margin-left: 4px;
            font-size: 10px;
        }
        .data-table td {
            padding: 12px 16px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        .data-table tbody tr:hover {
            background: #f9fafb;
        }
        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            color: #374151;
        }
        .pagination button:hover:not(:disabled) {
            background: #f3f4f6;
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
            margin: 0 12px;
        }
        .awstats-link {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .awstats-link div {
            flex: 1;
        }
        .awstats-link h3 {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
        }
        .awstats-link p {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 12px;
        }
        .awstats-link a {
            display: inline-block;
            padding: 8px 16px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .awstats-link a:hover {
            background: #5568d3;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .loading-spinner {
            background: white;
            padding: 32px;
            border-radius: 12px;
            text-align: center;
        }
        .spinner {
            border: 4px solid #f3f4f6;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header" style="margin-bottom: 24px;">
                <h1><i class="fas fa-chart-line"></i> <?php echo t('statistics.title', 'Website Traffic Statistics'); ?></h1>
                <p><?php echo t('statistics.subtitle', 'Detailed analytics and visitor metrics'); ?></p>
            </div>
            
            <!-- Site and Time Period Selection -->
            <div class="traffic-controls">
                <div class="control-group">
                    <label><i class="fas fa-globe"></i> Select Website</label>
                    <select id="siteSelector" onchange="loadTrafficStats()">
                        <?php foreach ($sites as $key => $site): ?>
                            <option value="<?php echo $key; ?>" <?php echo $key === 'ten' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="control-group">
                    <label><i class="fas fa-clock"></i> Time Period</label>
                    <select id="periodSelector" onchange="loadTrafficStats()">
                        <option value="last_hour">Last Hour</option>
                        <option value="last_2_hours">Last 2 Hours</option>
                        <option value="last_4_hours">Last 4 Hours</option>
                        <option value="last_12_hours">Last 12 Hours</option>
                        <option value="last_24_hours">Last 24 Hours</option>
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="last_7_days" selected>Last 7 Days</option>
                        <option value="last_30_days">Last 30 Days</option>
                        <option value="this_month">This Month</option>
                        <option value="last_month">Last Month</option>
                    </select>
                </div>
            </div>
            
            <!-- System Info Box -->
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <div class="info-item"><strong>Bot Lists Last Updated:</strong> <span id="botListDate">Loading...</span></div>
                    <div class="info-item"><strong>Database Last Updated:</strong> <span id="dbLastUpdate">Loading...</span></div>
                    <div class="info-item"><strong>Data Source:</strong> <span id="dataSource">-</span></div>
                </div>
            </div>
            
            <!-- Monthly Summary Cards -->
            <div id="monthlySummary" class="summary-grid" style="display: none;">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <!-- Current Period Statistics -->
            <div id="currentStats" style="display: none;">
                <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 20px;">
                    <i class="fas fa-chart-bar"></i> <span id="periodTitle">Statistics</span>
                </h2>
                
                <div class="stats-grid" id="statsGrid">
                    <!-- Will be populated by JavaScript -->
                </div>
                
                <!-- Hourly Distribution Chart -->
                <div class="chart-card">
                    <h2><i class="fas fa-clock"></i> Hourly Traffic Distribution</h2>
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
                
                <!-- Traffic Breakdown -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <div class="chart-card">
                        <h2><i class="fas fa-file-alt"></i> Top Performing Pages</h2>
                        <div class="chart-container">
                            <canvas id="topPagesChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h2><i class="fas fa-robot"></i> Traffic Type</h2>
                        <div class="chart-container">
                            <canvas id="trafficTypeChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Page Views Table -->
                <div class="chart-card">
                    <h2><i class="fas fa-file-alt"></i> Page Views</h2>
                    
                    <div class="table-controls">
                        <input type="text" id="pageSearch" placeholder="Search pages...">
                        <label style="font-size: 14px; color: #6b7280;">Show:
                            <select id="pageLimit" onchange="updatePageViewsTable()">
                                <option value="10" selected>10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="500">500</option>
                            </select>
                        </label>
                    </div>
                    
                    <table class="data-table" id="pageViewsTable">
                        <thead>
                            <tr>
                                <th onclick="sortPageViews('rank')">Rank <i class="fas fa-sort"></i></th>
                                <th onclick="sortPageViews('page')">Page URL <i class="fas fa-sort"></i></th>
                                <th onclick="sortPageViews('views')">Views <i class="fas fa-sort"></i></th>
                                <th onclick="sortPageViews('percentage')">% of Total <i class="fas fa-sort"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    No data available
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="pagination" id="pagePagination">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- AWStats Link -->
            <div class="awstats-link">
                <div style="flex: 1;">
                    <h3>AWStats Advanced Analytics</h3>
                    <p>For detailed log analysis and additional metrics, visit AWStats</p>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <a href="https://themunicheye.com/awstats/awstats.themunicheye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Munich Eye
                        </a>
                        <a href="https://thegermanyeye.com/awstats/awstats.thegermanyeye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Germany Eye
                        </a>
                        <a href="https://thebarcelonaeye.com/awstats/awstats.thebarcelonaeye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Barcelona Eye
                        </a>
                        <a href="https://themadrideye.com/awstats/awstats.themadrideye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Madrid Eye
                        </a>
                        <a href="https://therussiaeye.com/awstats/awstats.therussiaeye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Russia Eye
                        </a>
                        <a href="https://thebrazileye.com/awstats/awstats.thebrazileye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Brazil Eye
                        </a>
                        <a href="https://thetokyoeye.com/awstats/awstats.thetokyoeye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Tokyo Eye
                        </a>
                        <a href="https://buenosaireseve.com/awstats/awstats.buenosaireseve.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Buenos Aires Eye
                        </a>
                        <a href="https://thecanaryeye.com/awstats/awstats.thecanaryeye.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Canary Eye
                        </a>
                        <a href="https://theeyenewspapers.com/awstats/awstats.theeyenewspapers.com.html" target="_blank">
                            <i class="fas fa-external-link-alt"></i> The Eye Newspapers
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <div style="font-weight: 600; color: #111827;">Loading statistics...</div>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentData = null;
        let pageViewsData = [];
        let currentPage = 1;
        let currentSort = { column: 'views', direction: 'desc' };
        let hourlyChart = null;
        let topPagesChart = null;
        let trafficTypeChart = null;
        
        // Site configuration from PHP
        const sites = <?php echo json_encode($sites); ?>;
        
        // Load statistics on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSystemInfo();
            loadTrafficStats();
            
            // Search functionality
            document.getElementById('pageSearch').addEventListener('input', function() {
                currentPage = 1;
                updatePageViewsTable();
            });
        });
        
        function loadSystemInfo() {
            fetch('ajax/traffic_stats.php?action=system_info')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Display bot list files
                        let botListHtml = '';
                        if (data.bot_list_files && data.bot_list_files.length > 0) {
                            botListHtml = data.bot_list_files.map(file => 
                                `${file.filename} (${file.date})`
                            ).join(', ');
                        } else {
                            botListHtml = 'Not available';
                        }
                        
                        document.getElementById('botListDate').innerHTML = botListHtml;
                        document.getElementById('dbLastUpdate').textContent = data.db_last_update || 'No data yet';
                    }
                })
                .catch(error => console.error('Error loading system info:', error));
        }
        
        function loadTrafficStats() {
            const siteKey = document.getElementById('siteSelector').value;
            const period = document.getElementById('periodSelector').value;
            
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('currentStats').style.display = 'none';
            document.getElementById('monthlySummary').style.display = 'none';
            
            fetch(`ajax/traffic_stats.php?action=get_stats&site=${siteKey}&period=${period}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').style.display = 'none';
                    
                    if (data.success) {
                        currentData = data;
                        displayStats(data);
                        loadMonthlySummary(siteKey);
                    } else {
                        Swal.fire('Error', data.message || 'Failed to load statistics', 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('loadingOverlay').style.display = 'none';
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to load statistics', 'error');
                });
        }
        
        function loadMonthlySummary(siteKey) {
            fetch(`ajax/traffic_stats.php?action=monthly_summary&site=${siteKey}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Monthly Summary Data:', data);
                    if (data.success) {
                        displayMonthlySummary(data);
                    } else {
                        console.error('Monthly summary error:', data.message);
                    }
                })
                .catch(error => console.error('Error loading monthly summary:', error));
        }
        
        function displayMonthlySummary(data) {
            const container = document.getElementById('monthlySummary');
            const currentMonth = data.current_month;
            const lastMonth = data.last_month;
            
            let html = '';
            
            // Last Month Card
            html += `
                <div class="summary-card">
                    <div class="summary-title">Last Month (${lastMonth.month_name})</div>
                    <div class="summary-value">${formatNumber(lastMonth.total_visits || 0)}</div>
                    <div class="summary-label">Total Visits</div>
                    <div style="margin-top: 12px; font-size: 13px; color: #6b7280;">
                        <div>👥 ${formatNumber(lastMonth.unique_visitors || 0)} Unique Visitors</div>
                        <div>✅ ${formatNumber(lastMonth.human_visits || 0)} Human Visits</div>
                    </div>
                </div>
            `;
            
            // Current Month Card
            html += `
                <div class="summary-card">
                    <div class="summary-title">Current Month (${currentMonth.month_name})</div>
                    <div class="summary-value">${formatNumber(currentMonth.total_visits || 0)}</div>
                    <div class="summary-label">Visits So Far (${currentMonth.days_elapsed || 0} days with data)</div>
            `;
            
            if (currentMonth.projection_available && currentMonth.projected_total > 0) {
                const projectedTotal = Math.round(currentMonth.projected_total);
                const change = lastMonth.total_visits > 0 
                    ? ((projectedTotal - lastMonth.total_visits) / lastMonth.total_visits * 100).toFixed(1)
                    : 0;
                const changeClass = change >= 0 ? 'color: #059669;' : 'color: #dc2626;';
                const changeIcon = change >= 0 ? '↑' : '↓';
                
                html += `
                    <div class="summary-projection">
                        <strong>Projected for Month:</strong> ${formatNumber(projectedTotal)}
                        <div style="margin-top: 4px; ${changeClass}">
                            ${changeIcon} ${Math.abs(change)}% vs last month
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div class="summary-projection" style="color: #9ca3af; font-style: italic;">
                        ${currentMonth.days_elapsed > 0 ? 'Calculating projection...' : 'No data available yet'}
                    </div>
                `;
            }
            
            html += `</div>`;
            
            container.innerHTML = html;
            container.style.display = 'grid';
        }
        
        function displayStats(data) {
            const stats = data.stats;
            const siteName = sites[data.site_key].name;
            const periodText = getPeriodText(data.period);
            
            document.getElementById('periodTitle').textContent = `${siteName} - ${periodText}`;
            document.getElementById('dataSource').textContent = data.data_source;
            
            // Stats Grid
            const statsGrid = document.getElementById('statsGrid');
            statsGrid.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background: #dbeafe; color: #1e40af;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value">${formatNumber(stats.human_visits)}</div>
                    <div class="stat-label">Total Human Visitors</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value">${formatNumber(stats.human_unique)}</div>
                    <div class="stat-label">Human Unique Visitors</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-value">${formatNumber(stats.bot_visits + stats.spam_visits)}</div>
                    <div class="stat-label">Spam/Bots Blocked</div>
                </div>
            `;
            
            // Update charts
            updateHourlyChart(stats.hourly_distribution);
            updateTrafficTypeChart(stats);
            updateTopPagesChart(stats.page_views);
            
            // Update page views table
            pageViewsData = Object.entries(stats.page_views || {}).map(([page, views]) => ({
                page,
                views,
                percentage: stats.total_visits > 0 ? (views / stats.total_visits * 100).toFixed(2) : 0
            }));
            currentPage = 1;
            updatePageViewsTable();
            
            document.getElementById('currentStats').style.display = 'block';
        }
        
        function updateHourlyChart(hourlyData) {
            const ctx = document.getElementById('hourlyChart').getContext('2d');
            
            if (hourlyChart) {
                hourlyChart.destroy();
            }
            
            hourlyChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: Array.from({length: 24}, (_, i) => i + ':00'),
                    datasets: [{
                        label: 'Visits',
                        data: hourlyData,
                        backgroundColor: '#667eea',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
        
        function updateTrafficTypeChart(stats) {
            const ctx = document.getElementById('trafficTypeChart').getContext('2d');
            
            if (trafficTypeChart) {
                trafficTypeChart.destroy();
            }
            
            trafficTypeChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Human', 'Bots', 'Spam'],
                    datasets: [{
                        data: [stats.human_visits, stats.bot_visits, stats.spam_visits],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 10,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }
        
        function updateTopPagesChart(pageViews) {
            const ctx = document.getElementById('topPagesChart').getContext('2d');
            
            if (topPagesChart) {
                topPagesChart.destroy();
            }
            
            const topPages = Object.entries(pageViews || {})
                .sort((a, b) => b[1] - a[1])
                .slice(0, 10);
            
            topPagesChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: topPages.map(p => truncate(p[0], 35)),
                    datasets: [{
                        label: 'Views',
                        data: topPages.map(p => p[1]),
                        backgroundColor: '#667eea',
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { 
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
        
        function updatePageViewsTable() {
            const searchTerm = document.getElementById('pageSearch').value.toLowerCase();
            const limit = parseInt(document.getElementById('pageLimit').value);
            
            // Filter data
            let filteredData = pageViewsData.filter(item => 
                item.page.toLowerCase().includes(searchTerm)
            );
            
            // Sort data
            filteredData.sort((a, b) => {
                let aVal = a[currentSort.column];
                let bVal = b[currentSort.column];
                
                if (currentSort.column === 'rank') {
                    return currentSort.direction === 'asc' ? 
                        pageViewsData.indexOf(a) - pageViewsData.indexOf(b) :
                        pageViewsData.indexOf(b) - pageViewsData.indexOf(a);
                }
                
                if (typeof aVal === 'string') {
                    return currentSort.direction === 'asc' ? 
                        aVal.localeCompare(bVal) : 
                        bVal.localeCompare(aVal);
                } else {
                    return currentSort.direction === 'asc' ? 
                        aVal - bVal : 
                        bVal - aVal;
                }
            });
            
            // Pagination
            const totalPages = Math.ceil(filteredData.length / limit);
            const startIndex = (currentPage - 1) * limit;
            const endIndex = startIndex + limit;
            const pageData = filteredData.slice(startIndex, endIndex);
            
            // Update table
            const tbody = document.querySelector('#pageViewsTable tbody');
            if (pageData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 40px; color: #9ca3af;">No pages found</td></tr>';
            } else {
                tbody.innerHTML = pageData.map((item, index) => `
                    <tr>
                        <td style="font-weight: 600;">${startIndex + index + 1}</td>
                        <td><code style="font-size: 13px; color: #667eea;">${escapeHtml(item.page)}</code></td>
                        <td style="font-weight: 600;">${formatNumber(item.views)}</td>
                        <td>${item.percentage}%</td>
                    </tr>
                `).join('');
            }
            
            // Update pagination
            updatePagination(totalPages, filteredData.length);
        }
        
        function updatePagination(totalPages, totalItems) {
            const container = document.getElementById('pagePagination');
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            let html = '<button onclick="changePage(-1)" ' + (currentPage === 1 ? 'disabled' : '') + '>Previous</button>';
            
            html += '<span class="pagination-info">Page ' + currentPage + ' of ' + totalPages + ' (' + totalItems + ' items)</span>';
            
            html += '<button onclick="changePage(1)" ' + (currentPage === totalPages ? 'disabled' : '') + '>Next</button>';
            
            container.innerHTML = html;
        }
        
        function changePage(direction) {
            currentPage += direction;
            updatePageViewsTable();
        }
        
        function sortPageViews(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = column === 'views' || column === 'percentage' ? 'desc' : 'asc';
            }
            currentPage = 1;
            updatePageViewsTable();
        }
        
        function getPeriodText(period) {
            const map = {
                'last_hour': 'Last Hour',
                'last_2_hours': 'Last 2 Hours',
                'last_4_hours': 'Last 4 Hours',
                'last_12_hours': 'Last 12 Hours',
                'last_24_hours': 'Last 24 Hours',
                'today': 'Today',
                'yesterday': 'Yesterday',
                'last_7_days': 'Last 7 Days',
                'last_30_days': 'Last 30 Days',
                'this_month': 'This Month',
                'last_month': 'Last Month'
            };
            return map[period] || period;
        }
        
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }
        
        function truncate(str, len) {
            return str.length > len ? str.substring(0, len) + '...' : str;
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>
