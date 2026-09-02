<?php
require_once 'config.php';
requireLogin();

$currentUser = getCurrentUser();
$userModules = getUserModules();
$userRoles = getUserRoles();

// Get dashboard statistics
$conn = getDBConnection();

$stats = [
    'total_users' => 0,
    'active_users' => 0,
    'pending_users' => 0,
    'total_modules' => 0
];

if (hasPermission('users.view') || isAdmin()) {
    $statsQuery = "SELECT 
        (SELECT COUNT(*) FROM ten_users) as total_users,
        (SELECT COUNT(*) FROM ten_users WHERE status = 'active') as active_users,
        (SELECT COUNT(*) FROM ten_users WHERE status = 'pending') as pending_users,
        (SELECT COUNT(*) FROM ten_modules WHERE is_enabled = 1) as total_modules";
    $result = $conn->query($statsQuery);
    $stats = $result->fetch_assoc();
}

// Recent activity
$recentActivity = [];
if (hasPermission('system.access')) {
    $activityStmt = $conn->prepare("SELECT al.*, u.full_name, u.username 
                                    FROM ten_activity_log al 
                                    LEFT JOIN ten_users u ON al.user_id = u.id 
                                    ORDER BY al.created_at DESC LIMIT 10");
    $activityStmt->execute();
    $recentActivity = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activityStmt->close();
}

$conn->close();

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('dashboard.title', 'Dashboard'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .welcome-card {
            background: none;
            color: #000;
            padding: 40px 10px;
            border-radius: 16px;
            margin-bottom: 32px;
        }
        .welcome-card h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .welcome-card p {
            font-size: 16px;
            opacity: 0.9;
        }
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
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
            transform: translateY(-4px);
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
        .stat-card:nth-child(1) .stat-icon {
            background: #dbeafe;
            color: #1e40af;
        }
        .stat-card:nth-child(2) .stat-icon {
            background: #d1fae5;
            color: #065f46;
        }
        .stat-card:nth-child(3) .stat-icon {
            background: #fef3c7;
            color: #92400e;
        }
        .stat-card:nth-child(4) .stat-icon {
            background: #fce7f3;
            color: #9f1239;
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
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .card h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h2 i {
            color: #667eea;
        }
        .roles-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .role-badge {
            padding: 6px 12px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .activity-item {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .activity-details {
            flex: 1;
        }
        .activity-details strong {
            color: #111827;
        }
        .activity-time {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .stats-grid {
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
            <div class="welcome-card">
                <h1><?php echo t('dashboard.welcome', 'Welcome back'); ?>, <?php echo htmlspecialchars($currentUser['full_name']); ?>!</h1>
                <p><?php echo t('dashboard.subtitle', 'Here\'s what\'s happening with your management system'); ?></p>
            </div>
            
            <?php if (hasPermission('users.view') || isAdmin()): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label"><?php echo t('dashboard.stat.total_users', 'Total Users'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label"><?php echo t('dashboard.stat.active_users', 'Active Users'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_users']; ?></div>
                    <div class="stat-label"><?php echo t('dashboard.stat.pending_users', 'Pending Approval'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_modules']; ?></div>
                    <div class="stat-label"><?php echo t('dashboard.stat.active_modules', 'Active Modules'); ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="content-grid">
                <div class="card">
                    <h2>
                        <i class="fas fa-user-shield"></i>
                        <?php echo t('dashboard.your_roles', 'Your Roles & Permissions'); ?>
                    </h2>
                    <?php if (!empty($userRoles)): ?>
                        <div class="roles-list">
                            <?php foreach ($userRoles as $role): ?>
                                <span class="role-badge">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color: #6b7280;"><?php echo t('dashboard.no_roles', 'No roles assigned yet'); ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if (hasPermission('system.access')): ?>
                <div class="card">
                    <h2>
                        <i class="fas fa-clock-rotate-left"></i>
                        <?php echo t('dashboard.recent_activity', 'Recent Activity'); ?>
                    </h2>
                    <?php if (!empty($recentActivity)): ?>
                        <?php foreach (array_slice($recentActivity, 0, 5) as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-circle-notch" style="color: #667eea;"></i>
                                </div>
                                <div class="activity-details">
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?></strong>
                                        <?php echo htmlspecialchars($activity['action']); ?>
                                        <?php if ($activity['entity_type']): ?>
                                            <span style="color: #6b7280;">
                                                (<?php echo htmlspecialchars($activity['entity_type']); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #6b7280; text-align: center; padding: 20px;">
                            <?php echo t('dashboard.no_activity', 'No recent activity'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
