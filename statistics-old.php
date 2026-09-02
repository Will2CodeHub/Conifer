<?php
require_once 'config.php';
require_once 'includes/LogAnalyzer.php';
requireLogin();

$conn = getDBConnection();

// Define available sites
$availableSites = [
    'tme' => [
        'name' => 'The Munich Eye',
        'config' => [
            'TEN_BASE_PATH' => '/home/tmeuser/public_html/',
            'TEN_BASE_WEBSITE_PATH' => 'themunicheye.com',
            'TEN_BASE_SITE_NAME' => 'The Munich Eye',
            'TEN_BASE_SITE_NAME_SHORT' => 'Munich Eye',
            'TEN_BASE_SITE_ABBREVIATION' => 'tme',
        ]
    ],
    'ten' => [
        'name' => 'The Eye Newspapers',
        'config' => [
            'TEN_BASE_PATH' => '/home/tenuser/public_html/',
            'TEN_BASE_WEBSITE_PATH' => 'theeyenewspapers.com',
            'TEN_BASE_SITE_NAME' => 'The Eye Newspapers',
            'TEN_BASE_SITE_NAME_SHORT' => 'The Eye Newspapers',
            'TEN_BASE_SITE_ABBREVIATION' => 'ten',
        ]
    ],
    // Add more sites here as needed
];

// Set default selected site from current environment or session
if (!isset($_SESSION['selected_site_key'])) {
    $currentSiteKey = getenv('TEN_BASE_SITE_ABBREVIATION') ?: 'tme';
    $_SESSION['selected_site_key'] = $currentSiteKey;
    $_SESSION['selected_site_config'] = $availableSites[$currentSiteKey]['config'];
}

$currentSiteKey = $_SESSION['selected_site_key'];
$currentSiteName = $_SESSION['selected_site_config']['TEN_BASE_SITE_NAME'];

// Get statistics
$stats = [];

// User statistics
$userStats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN last_login IS NOT NULL THEN 1 ELSE 0 END) as logged_in
    FROM ten_users")->fetch_assoc();

// Activity statistics (last 30 days)
$activityStats = $conn->query("SELECT 
    COUNT(*) as total_activities,
    COUNT(DISTINCT user_id) as active_users,
    COUNT(DISTINCT DATE(created_at)) as active_days
    FROM ten_activity_log 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();

// Most active users
$topUsers = $conn->query("SELECT u.full_name, u.email, COUNT(*) as activity_count
    FROM ten_activity_log al
    JOIN ten_users u ON al.user_id = u.id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.id
    ORDER BY activity_count DESC
    LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Recent activity by day (last 14 days)
$dailyActivity = $conn->query("SELECT 
    DATE(created_at) as date,
    COUNT(*) as count
    FROM ten_activity_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);

// Activity by action type
$actionTypes = $conn->query("SELECT 
    action,
    COUNT(*) as count
    FROM ten_activity_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY action
    ORDER BY count DESC
    LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$conn->close();

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
        /* Traffic Statistics Styles */
        .traffic-section {
            background: #070e1e;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 40px;
            color: white;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .traffic-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .traffic-title {
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .traffic-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .traffic-select {
            padding: 10px 16px;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            color: white;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            backdrop-filter: blur(10px);
            min-width: 180px;
        }
        .traffic-select option {
            background: #667eea;
            color: white;
        }
        .traffic-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .traffic-stat-card {
            background: rgba(255,255,255,0.15);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s;
        }
        .traffic-stat-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,255,255,0.4);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        .traffic-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }
        .traffic-stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .traffic-stat-label {
            font-size: 13px;
            opacity: 0.9;
            font-weight: 500;
        }
        .traffic-charts {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 24px;
        }
        .traffic-chart-card {
            background: rgba(255,255,255,0.15);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }
        .traffic-chart-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .traffic-update-info {
            text-align: center;
            font-size: 12px;
            opacity: 0.8;
            margin-top: 16px;
        }
        .traffic-loader {
            text-align: center;
            padding: 40px;
            font-size: 18px;
        }
        .traffic-loader i {
            font-size: 32px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* System Statistics Styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 16px;
        }
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        .chart-card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .chart-card h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
        }
        .table-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .table-card h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            padding: 24px 24px 0;
            margin-bottom: 20px;
        }
        .table-card table {
            width: 100%;
            border-collapse: collapse;
        }
        .table-card th {
            background: #f9fafb;
            padding: 12px 24px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
        }
        .table-card td {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
            .traffic-charts {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header" style="margin-bottom: 32px;">
                <h1><i class="fas fa-chart-line"></i> <?php echo t('statistics.title', 'Statistics & Analytics'); ?></h1>
                <p><?php echo t('statistics.subtitle', 'System usage and activity metrics'); ?></p>
            </div>
            
            <!-- Website Traffic Statistics Section -->
            <div class="traffic-section">
                <div class="traffic-header">
                    <div class="traffic-title">
                        <i class="fas fa-globe"></i>
                        <span>Website Traffic Statistics</span>
                    </div>
                    <div class="traffic-controls">
                        <select id="siteSelector" class="traffic-select">
                            <?php foreach ($availableSites as $key => $site): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" 
                                        <?php echo $key === $currentSiteKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select id="periodSelector" class="traffic-select">
                            <optgroup label="Real-Time">
                                <option value="5m">Last 5 Minutes</option>
                                <option value="30m">Last 30 Minutes</option>
                                <option value="1h">Last Hour</option>
                                <option value="2h">Last 2 Hours</option>
                                <option value="4h">Last 4 Hours</option>
                                <option value="8h">Last 8 Hours</option>
                                <option value="12h">Last 12 Hours</option>
                                <option value="24h" selected>Last 24 Hours</option>
                            </optgroup>
                            <optgroup label="Daily">
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                            </optgroup>
                            <optgroup label="Historical">
                                <option value="7d">Last 7 Days</option>
                                <option value="30d">Last 30 Days</option>
                                <option value="90d">Last 90 Days</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                
                <div id="trafficStatsContainer">
                    <div class="traffic-loader">
                        <i class="fas fa-spinner"></i>
                        <p>Loading traffic statistics...</p>
                    </div>
                </div>
            </div>
            
            <!-- System Statistics Section -->
            <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 24px;">
                <i class="fas fa-server"></i> System Statistics
            </h2>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #dbeafe; color: #1e40af;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $userStats['total']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $userStats['active']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-value"><?php echo $activityStats['total_activities']; ?></div>
                    <div class="stat-label">Activities (30d)</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fce7f3; color: #9f1239;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $activityStats['active_days']; ?></div>
                    <div class="stat-label">Active Days</div>
                </div>
            </div>
            
            <div class="chart-grid">
                <div class="chart-card">
                    <h2><i class="fas fa-calendar-day"></i> Daily Activity (Last 14 Days)</h2>
                    <canvas id="dailyActivityChart" height="80"></canvas>
                </div>
                
                <div class="chart-card">
                    <h2><i class="fas fa-tasks"></i> Top Actions</h2>
                    <canvas id="actionTypesChart" height="80"></canvas>
                </div>
            </div>
            
            <div class="table-card" style="margin-bottom:50px;">
                <h2><i class="fas fa-star"></i> Awstats</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Stats Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><a target="_blank" href="https://themunicheye.com">The Munich Eye</a></td><td><a target="_blank" href="https://themunicheye.com/awstats/awstats.themunicheye.com.html">https://themunicheye.com/awstats/awstats.themunicheye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://thegermanyeye.com">The Germany Eye</a></td><td><a target="_blank" href="https://thegermanyeye.com/awstats/awstats.thegermanyeye.com.html">https://thegermanyeye.com/awstats/awstats.thegermanyeye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://thecanaryeye.com">The Canary Eye</a></td><td><a target="_blank" href="https://thecanaryeye.com/awstats/awstats.thecanaryeye.com.html">https://thecanaryeye.com/awstats/awstats.thecanaryeye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://themadrideye.com">The Madrid Eye</a></td><td><a target="_blank" href="https://themadrideye.com/awstats/awstats.themadrideye.com.html">https://themadrideye.com/awstats/awstats.themadrideye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://thebarcelonaeye.com">The Barcelona Eye</a></td><td><a target="_blank" href="https://thebarcelonaeye.com/awstats/awstats.thebarcelonaeye.com.html">https://thebarcelonaeye.com/awstats/awstats.thebarcelonaeye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://thebrazileye.com">The Brazil Eye</a></td><td><a target="_blank" href="https://thebrazileye.com/awstats/awstats.thebrazileye.com.html">https://thebrazileye.com/awstats/awstats.thebrazileye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://buenosaireseye.com">Buenos Aires Eye</a></td><td><a target="_blank" href="https://buenosaireseye.com/awstats/awstats.buenosaireseye.com.html">https://buenosaireseye.com/awstats/awstats.buenosaireseye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://therussiaeye.com">The Russia Eye</a></td><td><a target="_blank" href="https://therussiaeye.com/awstats/awstats.therussiaeye.com.html">https://therussiaeye.com/awstats/awstats.therussiaeye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://thetokyoeye.com">The Tokyo Eye</a></td><td><a target="_blank" href="https://thetokyoeye.com/awstats/awstats.thetokyoeye.com.html">https://thetokyoeye.com/awstats/awstats.thetokyoeye.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://theeyenewspapers.com">The Eye Newspapers</a></td><td><a target="_blank" href="https://theeyenewspapers.com/awstats/awstats.theeyenewspapers.com.html">https://theeyenewspapers.com/awstats/awstats.theeyenewspapers.com.html</a></td></tr>
                        <tr><td><a target="_blank" href="https://germanprivatehealthinsurance.com">German Private Health Insurance</a></td><td><a target="_blank" href="https://germanprivatehealthinsurance.com/awstats/awstats.germanprivatehealthinsurance.com.html">https://germanprivatehealthinsurance.com/awstats/awstats.germanprivatehealthinsurance.com.html</a></td></tr>
                    </tbody>
                </table>
            </div>
            
            <div class="table-card">
                <h2><i class="fas fa-star"></i> Most Active Users (Last 30 Days)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Activities</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUsers as $user): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td style="color: #6b7280;"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><span style="padding: 4px 10px; background: #dbeafe; color: #1e40af; border-radius: 6px; font-weight: 600; font-size: 12px;"><?php echo $user['activity_count']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topUsers)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: #9ca3af; padding: 40px;">No activity data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        let trafficChartInstance = null;
        let hourlyChartInstance = null;
        
        // Load traffic stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadTrafficStats();
            
            // Add event listeners for controls
            document.getElementById('siteSelector').addEventListener('change', function() {
                setSiteSession(this.value);
            });
            document.getElementById('periodSelector').addEventListener('change', loadTrafficStats);
        });
        
        function setSiteSession(siteKey) {
            // Set the session variable for the selected site
            fetch('ajax/set_site_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    site_key: siteKey
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload stats with new site
                    loadTrafficStats();
                } else {
                    console.error('Error setting site session:', data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        function loadTrafficStats() {
            const period = document.getElementById('periodSelector').value;
            const container = document.getElementById('trafficStatsContainer');
            
            container.innerHTML = '<div class="traffic-loader"><i class="fas fa-spinner"></i><p>Loading traffic statistics...</p></div>';
            
            // All periods use get_live_stats
            let postData = {
                action: 'get_live_stats',
                period: period
            };
            
            fetch('ajax/traffic_stats.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(postData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayTrafficStats(data);
                } else {
                    container.innerHTML = '<div class="traffic-loader"><p style="color: #fee2e2;">Error: ' + (data.error || 'Failed to load statistics') + '</p></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="traffic-loader"><p style="color: #fee2e2;">Error loading statistics</p></div>';
            });
        }
        
        function displayTrafficStats(data) {
            const stats = data.stats;
            const container = document.getElementById('trafficStatsContainer');
            
            // Destroy existing charts
            if (trafficChartInstance) {
                trafficChartInstance.destroy();
                trafficChartInstance = null;
            }
            if (hourlyChartInstance) {
                hourlyChartInstance.destroy();
                hourlyChartInstance = null;
            }
            
            const html = `
                <div class="traffic-stats-grid">
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-eye"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.total_visits || 0)}</div>
                        <div class="traffic-stat-label">Total Visits</div>
                    </div>
                    
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-users"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.unique_visitors || 0)}</div>
                        <div class="traffic-stat-label">Unique Visitors</div>
                    </div>
                    
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-user-check"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.human_visits || 0)}</div>
                        <div class="traffic-stat-label">Human Visits</div>
                    </div>
                    
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.human_unique || 0)}</div>
                        <div class="traffic-stat-label">Human Unique</div>
                    </div>
                    
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-robot"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.bot_visits || 0)}</div>
                        <div class="traffic-stat-label">Bot Visits</div>
                    </div>
                    
                    <div class="traffic-stat-card">
                        <div class="traffic-stat-icon"><i class="fas fa-ban"></i></div>
                        <div class="traffic-stat-value">${formatNumber(stats.spam_visits || 0)}</div>
                        <div class="traffic-stat-label">Spam Blocked</div>
                    </div>
                </div>
                
                ${stats.hourly_distribution ? `
                <div class="traffic-charts">
                    <div class="traffic-chart-card">
                        <div class="traffic-chart-title">
                            <i class="fas fa-chart-area"></i>
                            Hourly Distribution
                        </div>
                        <canvas id="trafficHourlyChart" style="max-height: 250px;"></canvas>
                    </div>
                    
                    <div class="traffic-chart-card">
                        <div class="traffic-chart-title">
                            <i class="fas fa-chart-pie"></i>
                            Traffic Breakdown
                        </div>
                        <canvas id="trafficBreakdownChart" style="max-height: 250px;"></canvas>
                    </div>
                </div>
                ` : ''}
                
                <div class="traffic-update-info">
                    ${data.is_live ? '<i class="fas fa-circle" style="color: #10b981;"></i> Live data from log file' : '<i class="fas fa-database"></i> Historical data from database'} • 
                    Updated: ${new Date().toLocaleTimeString()}
                </div>
            `;
            
            container.innerHTML = html;
            
            // Create charts if hourly data exists
            if (stats.hourly_distribution && Array.isArray(stats.hourly_distribution)) {
                createHourlyChart(stats.hourly_distribution);
                createBreakdownChart(stats);
            }
        }
        
        function createHourlyChart(hourlyData) {
            const ctx = document.getElementById('trafficHourlyChart');
            if (!ctx) return;
            
            const labels = Array.from({length: 24}, (_, i) => i + ':00');
            
            hourlyChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Visits',
                        data: hourlyData,
                        backgroundColor: 'rgba(255, 255, 255, 0.3)',
                        borderColor: 'rgba(255, 255, 255, 0.8)',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: 'rgba(255, 255, 255, 0.8)'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.8)'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    }
                }
            });
        }
        
        function createBreakdownChart(stats) {
            const ctx = document.getElementById('trafficBreakdownChart');
            if (!ctx) return;
            
            trafficChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Human', 'Bots', 'Spam'],
                    datasets: [{
                        data: [
                            stats.human_visits || 0,
                            stats.bot_visits || 0,
                            stats.spam_visits || 0
                        ],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)'
                        ],
                        borderColor: 'rgba(255, 255, 255, 0.8)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255, 255, 255, 0.9)',
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        }
                    }
                }
            });
        }
        
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }
        
        // System Activity Charts
        // Daily Activity Chart
        const dailyActivityCtx = document.getElementById('dailyActivityChart');
        if (dailyActivityCtx) {
            const dailyActivityChart = new Chart(dailyActivityCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($dailyActivity, 'date')); ?>,
                    datasets: [{
                        label: 'Activities',
                        data: <?php echo json_encode(array_column($dailyActivity, 'count')); ?>,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
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
        
        // Action Types Chart
        const actionTypesCtx = document.getElementById('actionTypesChart');
        if (actionTypesCtx) {
            const actionTypesChart = new Chart(actionTypesCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($actionTypes, 'action')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($actionTypes, 'count')); ?>,
                        backgroundColor: [
                            '#667eea',
                            '#764ba2',
                            '#f093fb',
                            '#4facfe',
                            '#00f2fe',
                            '#43e97b',
                            '#fa709a',
                            '#fee140',
                            '#30cfd0',
                            '#a8edea'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>