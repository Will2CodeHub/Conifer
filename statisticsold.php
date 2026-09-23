<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

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
        // Daily Activity Chart
        const dailyActivityCtx = document.getElementById('dailyActivityChart').getContext('2d');
        const dailyActivityChart = new Chart(dailyActivityCtx, {
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
                maintainAspectRatio: false,
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
        
        // Action Types Chart
        const actionTypesCtx = document.getElementById('actionTypesChart').getContext('2d');
        const actionTypesChart = new Chart(actionTypesCtx, {
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
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
