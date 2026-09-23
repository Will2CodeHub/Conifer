<?php
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/lib/stats_sites.php';
require_once __DIR__ . '/lib/app_settings.php';

// Canonical publication list (single source of truth in lib/stats_sites.php).
$sites   = ten_stats_sites();
$periods = ten_stats_periods();

// Admin-configurable defaults (Settings → General). The page then remembers each
// admin's last choice on their own device (localStorage), which takes precedence.
$defaultPublication = ten_get_setting('stats_default_publication', 'tme');
if (!isset($sites[$defaultPublication])) $defaultPublication = 'tme';
// Default to a DB-backed period so the page loads instantly. Live/intraday
// periods (today, last N hours) scan the raw log on the fly and are slow, so
// they're never the default — the admin can still pick them.
$defaultPeriod = ten_get_setting('stats_default_period', 'last_7_days');
if (!isset($periods[$defaultPeriod])) $defaultPeriod = 'last_7_days';

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
        .refresh-btn {
            padding: 10px 18px;
            border: 1px solid #667eea;
            background: #667eea;
            color: #fff;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background .2s ease;
            white-space: nowrap;
        }
        .refresh-btn:hover { background: #5568d3; }
        .refresh-btn.spinning i { animation: spin 0.8s linear infinite; }
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
        .summary-metrics {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .summary-metric {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .summary-metric .metric-num {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            line-height: 1.1;
        }
        .summary-metric .metric-lbl {
            font-size: 12px;
            color: #6b7280;
        }
        .summary-metric .metric-lbl i {
            color: #9ca3af;
            margin-right: 4px;
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
            display: block;
            margin-bottom: 6px;
        }
        .proj-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 4px;
        }
        .proj-row i { color: #9ca3af; margin-right: 4px; }
        .proj-row b { color: #111827; }
        .proj-delta { margin-top: 8px; font-weight: 600; font-size: 12.5px; }
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
                            <option value="<?php echo $key; ?>" <?php echo $key === $defaultPublication ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="control-group">
                    <label><i class="fas fa-clock"></i> Time Period</label>
                    <select id="periodSelector" onchange="loadTrafficStats()">
                        <?php foreach ($periods as $pkey => $plabel): ?>
                            <option value="<?php echo $pkey; ?>" <?php echo $pkey === $defaultPeriod ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($plabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="control-group" style="flex: 0 0 auto; min-width: 0;">
                    <button type="button" id="refreshBtn" class="refresh-btn" onclick="loadTrafficStats()" title="Refresh using the current filters">
                        <i class="fas fa-rotate-right"></i> Refresh
                    </button>
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
                
                <!-- Daily Traffic Chart (last 31 days) -->
                <div class="chart-card">
                    <h2><i class="fas fa-calendar-days"></i> Traffic &mdash; Last 31 Days</h2>
                    <div class="chart-container">
                        <canvas id="dailyChart"></canvas>
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

                <!-- Traffic Sources -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <div class="chart-card">
                        <h2><i class="fas fa-diagram-project"></i> Where Traffic Comes From</h2>
                        <div class="chart-container">
                            <canvas id="sourcesChart"></canvas>
                        </div>
                        <p style="font-size: 12px; color: #9ca3af; margin-top: 12px; line-height: 1.5;">
                            Channels of <em>referred</em> human visits for this period. Visits with no referrer
                            (typed the address, bookmarks, some apps/emails that strip it) are not counted here,
                            so this shows where <strong>referred</strong> traffic originates — the best signal for a spike.
                        </p>
                    </div>
                    <div class="chart-card">
                        <h2><i class="fas fa-arrow-right-to-bracket"></i> Top Referrers</h2>
                        <div class="table-controls">
                            <input type="text" id="refSearch" placeholder="Search referrers...">
                            <label style="font-size: 14px; color: #6b7280;">Show:
                                <select id="refLimit" onchange="updateReferrersTable()">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                </select>
                            </label>
                        </div>
                        <table class="data-table" id="referrersTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Referrer</th>
                                    <th>Channel</th>
                                    <th>Visits</th>
                                    <th>% ref.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: #9ca3af;">
                                        No referrer data
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="pagination" id="refPagination"></div>
                    </div>
                </div>

                <!-- Traffic Quality & Top Source IPs -->
                <div class="chart-card">
                    <h2><i class="fas fa-fingerprint"></i> Traffic Quality &amp; Top Source IPs</h2>
                    <div id="qualityBanner"></div>
                    <div id="qualityMetrics" class="stats-grid" style="margin-bottom: 16px;"></div>
                    <div id="reclassNote"></div>
                    <p style="font-size: 13px; color: #6b7280; margin: 4px 0 16px;">
                        Crawlers using ordinary browser user-agents slip past the bot filter and count as
                        &ldquo;human.&rdquo; The clues: <strong>pages per visitor near 1.0</strong> (each request is a fresh
                        &ldquo;visitor&rdquo; that never returns) and a handful of IPs producing a large share of hits.
                        A real audience spreads across many IPs and views several pages each.
                    </p>
                    <table class="data-table" id="topIpsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Source IP</th>
                                <th>Human hits</th>
                                <th>% of human visits</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    No source-IP data for this period yet. It is collected from the nightly run onward
                                    (the raw logs rotate every ~3 days, so it can&rsquo;t be backfilled).
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
        let dailyChart = null;
        let topPagesChart = null;
        let trafficTypeChart = null;
        let sourcesChart = null;
        let referrersData = [];
        let refCurrentPage = 1;
        
        // Site configuration from PHP
        const sites = <?php echo json_encode($sites); ?>;
        
        // Load statistics on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSystemInfo();

            // Restore this admin's last chosen filters (remembered per device).
            // Falls back to the server-configured defaults (Settings → General),
            // which are already pre-selected in the dropdowns.
            try {
                const savedSite = localStorage.getItem('ten_stats_site');
                const savedPeriod = localStorage.getItem('ten_stats_period');
                const siteSel = document.getElementById('siteSelector');
                const perSel = document.getElementById('periodSelector');
                if (savedSite && [...siteSel.options].some(o => o.value === savedSite)) siteSel.value = savedSite;
                if (savedPeriod && [...perSel.options].some(o => o.value === savedPeriod)) perSel.value = savedPeriod;
            } catch (e) {}

            loadTrafficStats();

            // Search functionality
            document.getElementById('pageSearch').addEventListener('input', function() {
                currentPage = 1;
                updatePageViewsTable();
            });
            document.getElementById('refSearch').addEventListener('input', function() {
                refCurrentPage = 1;
                updateReferrersTable();
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

            // Remember this admin's current filters for next time (per device).
            try {
                localStorage.setItem('ten_stats_site', siteKey);
                localStorage.setItem('ten_stats_period', period);
            } catch (e) {}

            const refreshBtn = document.getElementById('refreshBtn');
            if (refreshBtn) refreshBtn.classList.add('spinning');
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('currentStats').style.display = 'none';
            document.getElementById('monthlySummary').style.display = 'none';

            fetch(`ajax/traffic_stats.php?action=get_stats&site=${siteKey}&period=${period}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingOverlay').style.display = 'none';
                    if (refreshBtn) refreshBtn.classList.remove('spinning');

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
                    if (refreshBtn) refreshBtn.classList.remove('spinning');
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
        
        // One breakdown row (Visits / Unique visitors / Page views)
        function projRow(icon, label, value) {
            return `<div class="proj-row"><span><i class="fas ${icon}"></i> ${label}</span><b>${formatNumber(Math.round(value || 0))}</b></div>`;
        }

        // Both month boxes share this layout so they show identical content.
        // `foot` is the breakdown block: projected figures for the current month,
        // final figures for last month.
        // Both month boxes share this layout. Figures are REAL visitors — bots and
        // spam are excluded. `foot` is the breakdown block.
        function monthCard(heading, m, subLabel, foot) {
            const bots = m.bots_filtered || 0;
            return `
                <div class="summary-card">
                    <div class="summary-title">${heading}</div>
                    <div class="summary-value">${formatNumber(m.human_visits || 0)}</div>
                    <div class="summary-label">${subLabel}</div>
                    <div class="summary-metrics">
                        <div class="summary-metric">
                            <span class="metric-num">${formatNumber(m.unique_visitors || 0)}</span>
                            <span class="metric-lbl"><i class="fas fa-user"></i> Unique Visitors</span>
                        </div>
                        <div class="summary-metric">
                            <span class="metric-num">${formatNumber(m.page_views || 0)}</span>
                            <span class="metric-lbl"><i class="fas fa-file-lines"></i> Page Views</span>
                        </div>
                    </div>
                    ${bots ? `<div style="font-size:12px;color:#9ca3af;margin-top:10px;"><i class="fas fa-robot"></i> ${formatNumber(bots)} bot/spam hits excluded</div>` : ''}
                    ${foot}
                </div>`;
        }

        function displayMonthlySummary(data) {
            const container = document.getElementById('monthlySummary');
            const currentMonth = data.current_month;
            const lastMonth = data.last_month;

            // Last month is complete → its "full-month total" IS its actuals (real visitors).
            const lastFoot = `
                <div class="summary-projection">
                    <strong>Full month total (${lastMonth.month_name})</strong>
                    ${projRow('fa-eye', 'Visits', lastMonth.human_visits)}
                    ${projRow('fa-user', 'Unique visitors', lastMonth.unique_visitors)}
                    ${projRow('fa-file-lines', 'Page views', lastMonth.page_views)}
                </div>`;

            // Current month → projected to month end (real visitors), once enough days exist.
            let curFoot;
            if (currentMonth.projection_available && currentMonth.projected_human_visits > 0) {
                const projVisits = Math.round(currentMonth.projected_human_visits);
                const change = lastMonth.human_visits > 0
                    ? ((projVisits - lastMonth.human_visits) / lastMonth.human_visits * 100).toFixed(1)
                    : 0;
                const changeClass = change >= 0 ? 'color:#059669;' : 'color:#dc2626;';
                const changeIcon = change >= 0 ? '↑' : '↓';
                curFoot = `
                    <div class="summary-projection">
                        <strong>Projected for the full month (${currentMonth.month_name})</strong>
                        ${projRow('fa-eye', 'Visits', currentMonth.projected_human_visits)}
                        ${projRow('fa-user', 'Unique visitors', currentMonth.projected_unique)}
                        ${projRow('fa-file-lines', 'Page views', currentMonth.projected_page_views)}
                        ${lastMonth.human_visits > 0 ? `<div class="proj-delta" style="${changeClass}">${changeIcon} ${Math.abs(change)}% projected visits vs last month</div>` : ''}
                    </div>`;
            } else {
                curFoot = `
                    <div class="summary-projection" style="color:#9ca3af;font-style:italic;">
                        Not enough data yet to project — ${currentMonth.days_elapsed || 0} of ${currentMonth.days_in_month || 30} days collected this month. A projection needs at least 3 days.
                    </div>`;
            }

            container.innerHTML =
                monthCard(`Last Month (${lastMonth.month_name})`, lastMonth, 'Real visitors', lastFoot) +
                monthCard(`This Month (${currentMonth.month_name})`, currentMonth, `Real visitors so far (${currentMonth.days_elapsed || 0} days measured)`, curFoot);
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
            updateDailyChart(stats.daily_series);
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

            // Traffic sources (referrers): group into channels + list raw referrers.
            const selfHost = (sites[data.site_key] && sites[data.site_key].domain
                ? sites[data.site_key].domain : '').toLowerCase().replace(/^www\./, '');
            const refEntries = Object.entries(stats.referrers || {});
            const refTotal = refEntries.reduce((s, [, v]) => s + v, 0);
            referrersData = refEntries.map(([referrer, visits]) => ({
                referrer,
                channel: classifyReferrer(referrer, selfHost),
                visits,
                percentage: refTotal > 0 ? (visits / refTotal * 100).toFixed(1) : 0
            }));
            refCurrentPage = 1;
            updateSourcesChart(referrersData);
            updateReferrersTable();

            // Traffic quality + top source IPs (crawler-in-disguise detection).
            updateTrafficQuality(stats);

            document.getElementById('currentStats').style.display = 'block';
        }
        
        function updateDailyChart(dailySeries) {
            const ctx = document.getElementById('dailyChart').getContext('2d');
            const series = Array.isArray(dailySeries) ? dailySeries : [];

            if (dailyChart) {
                dailyChart.destroy();
            }

            dailyChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: series.map(d => d.label),
                    datasets: [{
                        label: 'Visits',
                        data: series.map(d => d.visits),
                        backgroundColor: '#667eea',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                // Show the full date (the axis labels are abbreviated "j M").
                                title: (items) => {
                                    const i = items[0].dataIndex;
                                    return series[i] ? series[i].date : '';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        },
                        x: {
                            ticks: {
                                autoSkip: true,
                                maxTicksLimit: 16,
                                maxRotation: 90,
                                minRotation: 45
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
        
        // Extract a clean hostname from a raw referrer string.
        function refHostname(ref) {
            let host = '';
            try {
                host = new URL(ref).hostname;
            } catch (e) {
                host = String(ref || '').replace(/^https?:\/\//i, '').split(/[\/?#]/)[0];
            }
            return host.toLowerCase().replace(/^www\./, '');
        }

        // Bucket a referrer into a human-readable channel. selfHost = this site's
        // own domain, so on-site click-throughs are labelled "Internal" and don't
        // drown out the external sources we actually care about.
        function classifyReferrer(ref, selfHost) {
            const h = refHostname(ref);
            if (!h) return 'Direct / unknown';
            if (selfHost && (h === selfHost || h.endsWith('.' + selfHost))) return 'Internal';
            const has = s => h.indexOf(s) !== -1;

            if (has('news.google')) return 'Google News';
            if (has('google.')) return 'Google Search';
            if (has('bing.')) return 'Bing';
            if (has('duckduckgo')) return 'DuckDuckGo';
            if (has('yahoo.')) return 'Yahoo';
            if (has('ecosia') || has('yandex') || has('baidu') || has('qwant') || has('startpage')) return 'Other search';

            if (h === 't.co' || has('twitter.') || h === 'x.com' || h.endsWith('.x.com')) return 'X / Twitter';
            if (has('facebook.') || has('fb.me') || h === 'lm.facebook.com') return 'Facebook';
            if (has('instagram')) return 'Instagram';
            if (has('reddit') || h === 'redd.it') return 'Reddit';
            if (has('linkedin') || h === 'lnkd.in') return 'LinkedIn';
            if (has('youtube') || h === 'youtu.be') return 'YouTube';
            if (has('pinterest') || h === 'pin.it') return 'Pinterest';
            if (has('t.me') || has('telegram')) return 'Telegram';
            if (has('whatsapp') || h === 'wa.me') return 'WhatsApp';
            if (has('tiktok')) return 'TikTok';

            if (has('flipboard') || has('smartnews') || has('msn.com') || has('news.') ||
                has('drudge') || has('feedly') || has('upday')) return 'News aggregator';

            return 'Other referrers';
        }

        // Fixed colours per channel so a source keeps its colour across periods.
        const CHANNEL_COLORS = {
            'Google Search': '#4285f4', 'Google News': '#1a73e8', 'Bing': '#008373',
            'DuckDuckGo': '#de5833', 'Yahoo': '#6001d2', 'Other search': '#5f6368',
            'Facebook': '#1877f2', 'X / Twitter': '#111827', 'Instagram': '#e1306c',
            'Reddit': '#ff4500', 'LinkedIn': '#0a66c2', 'YouTube': '#ff0000',
            'Pinterest': '#bd081c', 'Telegram': '#229ed9', 'WhatsApp': '#25d366',
            'TikTok': '#000000', 'News aggregator': '#f59e0b', 'Internal': '#9ca3af',
            'Direct / unknown': '#d1d5db', 'Other referrers': '#a78bfa'
        };

        function updateSourcesChart(rows) {
            const ctx = document.getElementById('sourcesChart').getContext('2d');
            if (sourcesChart) sourcesChart.destroy();

            // Sum visits per channel.
            const byChannel = {};
            rows.forEach(r => { byChannel[r.channel] = (byChannel[r.channel] || 0) + r.visits; });
            let entries = Object.entries(byChannel).sort((a, b) => b[1] - a[1]);

            if (entries.length === 0) {
                sourcesChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: { labels: ['No referrer data'], datasets: [{ data: [1], backgroundColor: ['#e5e7eb'] }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
                return;
            }

            // Collapse a long tail into "Other" so the chart stays readable.
            const MAX_SLICES = 9;
            if (entries.length > MAX_SLICES) {
                const head = entries.slice(0, MAX_SLICES - 1);
                const tail = entries.slice(MAX_SLICES - 1).reduce((s, e) => s + e[1], 0);
                entries = head.concat([['Other', tail]]);
            }

            sourcesChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: entries.map(e => e[0]),
                    datasets: [{
                        data: entries.map(e => e[1]),
                        backgroundColor: entries.map(e => CHANNEL_COLORS[e[0]] || '#a78bfa')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { boxWidth: 12, padding: 8, font: { size: 11 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: c => {
                                    const total = c.dataset.data.reduce((s, v) => s + v, 0);
                                    const pct = total > 0 ? (c.parsed / total * 100).toFixed(1) : 0;
                                    return `${c.label}: ${formatNumber(c.parsed)} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateReferrersTable() {
            const searchTerm = document.getElementById('refSearch').value.toLowerCase();
            const limit = parseInt(document.getElementById('refLimit').value);

            let filtered = referrersData.filter(item =>
                item.referrer.toLowerCase().includes(searchTerm) ||
                item.channel.toLowerCase().includes(searchTerm)
            );
            filtered.sort((a, b) => b.visits - a.visits);

            const totalPages = Math.ceil(filtered.length / limit);
            const startIndex = (refCurrentPage - 1) * limit;
            const pageData = filtered.slice(startIndex, startIndex + limit);

            const tbody = document.querySelector('#referrersTable tbody');
            if (pageData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #9ca3af;">No referrers found</td></tr>';
            } else {
                tbody.innerHTML = pageData.map((item, index) => {
                    const color = CHANNEL_COLORS[item.channel] || '#a78bfa';
                    return `
                        <tr>
                            <td style="font-weight: 600;">${startIndex + index + 1}</td>
                            <td><code style="font-size: 13px; color: #667eea; word-break: break-all;">${escapeHtml(refHostname(item.referrer) || item.referrer)}</code></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;color:#fff;background:${color};">${escapeHtml(item.channel)}</span></td>
                            <td style="font-weight: 600;">${formatNumber(item.visits)}</td>
                            <td>${item.percentage}%</td>
                        </tr>`;
                }).join('');
            }

            const container = document.getElementById('refPagination');
            if (totalPages <= 1) {
                container.innerHTML = '';
            } else {
                container.innerHTML =
                    '<button onclick="changeRefPage(-1)" ' + (refCurrentPage === 1 ? 'disabled' : '') + '>Previous</button>' +
                    '<span class="pagination-info">Page ' + refCurrentPage + ' of ' + totalPages + ' (' + filtered.length + ' items)</span>' +
                    '<button onclick="changeRefPage(1)" ' + (refCurrentPage === totalPages ? 'disabled' : '') + '>Next</button>';
            }
        }

        function changeRefPage(direction) {
            refCurrentPage += direction;
            updateReferrersTable();
        }

        // Traffic-quality read-out: pages-per-visitor + source-IP concentration.
        // These expose browser-UA crawlers that the user-agent bot filter counts
        // as human. Pages-per-visitor works on ANY period (uses existing columns);
        // the IP table needs the nightly collector's top_ips data (forward-only).
        function updateTrafficQuality(stats) {
            const humanVisits = stats.human_visits || 0;
            const humanUnique = stats.human_unique || 0;
            const ppv = humanUnique > 0 ? humanVisits / humanUnique : 0;

            const ipEntries = Object.entries(stats.top_ips || {}).sort((a, b) => b[1] - a[1]);
            const hasIps = ipEntries.length > 0;
            const top10 = ipEntries.slice(0, 10).reduce((s, e) => s + e[1], 0);
            const concentration = humanVisits > 0 ? (top10 / humanVisits * 100) : 0;

            const ppvColor = ppv >= 1.8 ? '#059669' : (ppv >= 1.3 ? '#d97706' : '#dc2626');
            const ppvLabel = ppv >= 1.8 ? 'Healthy — multi-page reading'
                           : (ppv >= 1.3 ? 'Mixed' : 'Suspicious — ~1 request per visitor');
            const concColor = concentration >= 35 ? '#dc2626' : (concentration >= 20 ? '#d97706' : '#059669');

            let cards = `
                <div class="stat-card">
                    <div class="stat-value" style="color:${ppvColor};">${ppv ? ppv.toFixed(2) : '—'}</div>
                    <div class="stat-label">Pages per visitor</div>
                    <div style="font-size:12px;color:#9ca3af;margin-top:6px;">${ppvLabel}</div>
                </div>`;
            if (hasIps) {
                cards += `
                <div class="stat-card">
                    <div class="stat-value" style="color:${concColor};">${concentration.toFixed(1)}%</div>
                    <div class="stat-label">Top 10 IPs' share of human visits</div>
                    <div style="font-size:12px;color:#9ca3af;margin-top:6px;">Higher = more concentrated (crawler-like)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${formatNumber(ipEntries[0][1])}</div>
                    <div class="stat-label">Busiest single IP</div>
                    <div style="font-size:12px;color:#9ca3af;margin-top:6px;word-break:break-all;">${escapeHtml(ipEntries[0][0])}</div>
                </div>`;
            }
            document.getElementById('qualityMetrics').innerHTML = cards;

            const banner = document.getElementById('qualityBanner');
            const suspicious = ppv > 0 && ppv < 1.25 && (!hasIps || concentration >= 25);
            if (suspicious) {
                banner.innerHTML = `<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;">
                    <i class="fas fa-triangle-exclamation"></i> <strong>Automated-traffic signature.</strong>
                    Pages per visitor is ${ppv.toFixed(2)}${hasIps ? `, and the top 10 IPs are ${concentration.toFixed(1)}% of human visits` : ''} — this looks like crawler traffic counted as human, not a real audience jump.
                </div>`;
            } else if (ppv > 0) {
                banner.innerHTML = `<div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;">
                    <i class="fas fa-circle-check"></i> Pages per visitor is ${ppv.toFixed(2)} — consistent with real browsing.
                </div>`;
            } else {
                banner.innerHTML = '';
            }

            // Reclassification note: how many hits were moved out of "human".
            const reclassified = stats.bot_reclassified || 0;
            const flagged = Object.entries(stats.reclassified_ips || {}).sort((a, b) => b[1] - a[1]);
            const note = document.getElementById('reclassNote');
            if (reclassified > 0) {
                const chips = flagged.slice(0, 8).map(e => {
                    const label = e[0] === 'unknown' ? 'no-IP monitor' : escapeHtml(e[0]);
                    return `<code style="font-size:12px;color:#991b1b;background:#fef2f2;padding:2px 6px;border-radius:6px;">${label}: ${formatNumber(e[1])}</code>`;
                }).join(' ');
                note.innerHTML = `<div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;line-height:1.6;">
                    <i class="fas fa-filter"></i> <strong>${formatNumber(reclassified)} hits reclassified as automated</strong> and excluded from the human figures above
                    (dominant single-IP crawlers and the no-IP 5-minute monitor).${flagged.length ? '<br>Sources: ' + chips : ''}
                </div>`;
            } else {
                note.innerHTML = '';
            }

            const tbody = document.querySelector('#topIpsTable tbody');
            if (!hasIps) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:40px;color:#9ca3af;">No source-IP data for this period yet. It is collected from the nightly run onward (the raw logs rotate every ~3 days, so it can’t be backfilled).</td></tr>';
                return;
            }
            tbody.innerHTML = ipEntries.slice(0, 25).map((e, i) => {
                const pct = humanVisits > 0 ? (e[1] / humanVisits * 100) : 0;
                const flagged = pct >= 1;
                return `<tr ${flagged ? 'style="background:#fef2f2;"' : ''}>
                    <td style="font-weight:600;">${i + 1}</td>
                    <td><code style="font-size:13px;color:#667eea;">${escapeHtml(e[0])}</code></td>
                    <td style="font-weight:600;">${formatNumber(e[1])}</td>
                    <td>${pct.toFixed(2)}%${flagged ? ' <i class="fas fa-flag" style="color:#dc2626;font-size:11px;"></i>' : ''}</td>
                </tr>`;
            }).join('');
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
