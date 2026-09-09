<?php
require_once 'config.php';
requireLogin();

// Only users with dashboard access see it; everyone else (editorial and
// vertical-specialist roles) is sent to their first permitted tool.
if (!isAdmin() && !hasPermission('dashboard.view')) {
    header('Location: ' . getLandingUrl());
    exit();
}

require_once __DIR__ . '/config_ten_admin.php';

$currentUser = getCurrentUser();
$userModules = getUserModules();
$userRoles = getUserRoles();

// Platform stats are for ADMIN AND ABOVE only.
$position = $_SESSION['ten_position'] ?? '';
$canSeeStats = isAdmin() || in_array($position, ['Super User', 'Super Admin', 'Administrator', 'Admin'], true);

/** Scalar query -> int, tolerant of a missing table/column (returns null). */
function dash_scalar($conn, string $sql): ?int {
    if (!$conn) return null;
    try { $r = @$conn->query($sql); if (!$r) return null; $row = $r->fetch_row(); return $row ? (int)$row[0] : 0; }
    catch (Throwable $e) { return null; }
}

$tiles = [];
$recentActivity = [];
if ($canSeeStats) {
    $m = getDBConnection();               // TEN_Management
    $a = null; try { $a = getDBConnection_TENAdmin(); } catch (Throwable $e) { $a = null; } // admin_ten (articles/publications)

    $usersActive = dash_scalar($m, "SELECT COUNT(*) FROM ten_users WHERE status='active'");
    $tiles = [
        ['fa-users',       '#1e40af', '#dbeafe', dash_scalar($m, "SELECT COUNT(*) FROM ten_users"),               'Total users',            $usersActive !== null ? ($usersActive . ' active') : ''],
        ['fa-newspaper',   '#3730a3', '#e0e7ff', dash_scalar($a, "SELECT COUNT(*) FROM publications WHERE pub_live=1"), 'Live publications',  ''],
        ['fa-file-lines',  '#065f46', '#d1fae5', dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='published'"), 'Articles published',
            (function($a){ $n = dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='published' AND submission_date >= DATE_SUB(NOW(),INTERVAL 7 DAY)"); return $n!==null ? ('+' . $n . ' this week') : ''; })($a)],
        ['fa-pen-clip',    '#92400e', '#fef3c7', dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='under review'"), 'Awaiting review', ''],
        ['fa-robot',       '#9f1239', '#fce7f3', dash_scalar($m, "SELECT COUNT(*) FROM ten_scraper_pub_sections WHERE is_active=1"), 'Active scraper sections', ''],
        ['fa-address-book','#0e7490', '#cffafe', dash_scalar($m, "SELECT COUNT(*) FROM ten_ec_contacts"),          'Email contacts',         ''],
        ['fa-paper-plane', '#4338ca', '#e0e7ff', dash_scalar($m, "SELECT COUNT(*) FROM ten_ec_recipients WHERE status='sent' AND sent_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)"), 'Emails sent (30d)', ''],
    ];
    // hide tiles whose data source isn't present (null)
    $tiles = array_values(array_filter($tiles, fn($t) => $t[3] !== null));

    $activityStmt = $m->prepare("SELECT al.*, u.full_name FROM ten_activity_log al LEFT JOIN ten_users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 10");
    if ($activityStmt) { $activityStmt->execute(); $recentActivity = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC); $activityStmt->close(); }
    $m->close();
}

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
            background: linear-gradient(135deg, #4f46e5 0%, #3c4f6d 100%);
            color: #fff;
            padding: 34px 32px;
            border-radius: 16px;
            margin-bottom: 28px;
            box-shadow: 0 8px 24px rgba(79,70,229,.18);
        }
        .welcome-card h1 {
            font-size: 28px;
            margin: 0 0 8px;
            line-height: 1.2;
        }
        .welcome-card p {
            font-size: 15px;
            margin: 0;
            opacity: 0.92;
        }
        .stat-sub {
            font-size: 12.5px;
            color: #6b7280;
            margin-top: 6px;
            font-weight: 500;
        }
        .section-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            margin: 0 0 14px;
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
        .content-grid .card:only-child { grid-column: 1 / -1; }
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
            
            <?php if ($canSeeStats && $tiles): ?>
            <p class="section-label">Platform overview</p>
            <div class="stats-grid">
                <?php foreach ($tiles as $t): ?>
                <div class="stat-card">
                    <div class="stat-icon" style="background:<?php echo $t[2]; ?>;color:<?php echo $t[1]; ?>;">
                        <i class="fas <?php echo $t[0]; ?>"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format((int)$t[3]); ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars($t[4]); ?></div>
                    <?php if (!empty($t[5])): ?><div class="stat-sub"><?php echo htmlspecialchars($t[5]); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
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
                
                <?php if ($canSeeStats): ?>
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
