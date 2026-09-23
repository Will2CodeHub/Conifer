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
    $art7 = dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='published' AND submission_date >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
    // Each tile: [icon, value, label, sub]
    $tiles = [
        ['fa-users',        dash_scalar($m, "SELECT COUNT(*) FROM ten_users"),                                        'Total users',            $usersActive !== null ? ($usersActive . ' active') : ''],
        ['fa-newspaper',    dash_scalar($a, "SELECT COUNT(*) FROM publications WHERE pub_live=1"),                     'Live publications',      ''],
        ['fa-file-lines',   dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='published'"),                 'Articles published',     $art7 !== null ? ('+' . number_format($art7) . ' this week') : ''],
        ['fa-pen-clip',     dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='under review'"),              'Awaiting review',        ''],
        ['fa-file-pen',     dash_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='draft'"),                     'Drafts',                 ''],
        ['fa-bolt',         dash_scalar($a, "SELECT COUNT(*) FROM articles_breaking_news WHERE state='published'"),   'Breaking news',          ''],
        ['fa-robot',        dash_scalar($m, "SELECT COUNT(*) FROM ten_scraper_pub_sections WHERE is_active=1"),       'Active scraper sections',''],
        ['fa-address-book', dash_scalar($m, "SELECT COUNT(*) FROM ten_ec_contacts"),                                 'Email contacts',         ''],
        ['fa-paper-plane',  dash_scalar($m, "SELECT COUNT(*) FROM ten_ec_recipients WHERE status='sent' AND sent_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)"), 'Emails sent (30d)', ''],
        ['fa-ban',          dash_scalar($m, "SELECT COUNT(*) FROM ten_ec_suppression"),                              'Suppressed emails',      ''],
    ];
    // hide tiles whose data source isn't present (null)
    $tiles = array_values(array_filter($tiles, fn($t) => $t[1] !== null));

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
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .welcome-card h1 {
            font-size: 24px;
            margin: 0 0 4px;
            line-height: 1.2;
            color: #111827;
            font-weight: 700;
        }
        .welcome-card p {
            font-size: 14px;
            margin: 0;
            color: #6b7280;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .stat-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .stat-head {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 12px;
        }
        .stat-head i {
            font-size: 14px;
        }
        .stat-value {
            font-size: 30px;
            font-weight: 700;
            color: #111827;
            line-height: 1.1;
        }
        .stat-sub {
            font-size: 12.5px;
            color: #6b7280;
            margin-top: 6px;
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
            border-radius: 10px;
            padding: 24px;
            border: 1px solid #e5e7eb;
        }
        .card h2 {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h2 i {
            color: #9ca3af;
            font-size: 15px;
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
                    <div class="stat-head">
                        <i class="fas <?php echo $t[0]; ?>"></i>
                        <span><?php echo htmlspecialchars($t[2]); ?></span>
                    </div>
                    <div class="stat-value"><?php echo number_format((int)$t[1]); ?></div>
                    <?php if (!empty($t[3])): ?><div class="stat-sub"><?php echo htmlspecialchars($t[3]); ?></div><?php endif; ?>
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
