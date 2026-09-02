<?php
require_once 'config.php';
require_once __DIR__ . '/scraper/lib/scraper_db.php';
requireLogin();

if (!hasPermission('scraper.use') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$projects = scraper_get_projects();
$canManage = hasPermission('scraper.manage') || isAdmin();
$activeType = $_GET['project'] ?? ($projects[0]['type'] ?? 'news_collation');
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Scraper — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .scraper-tabs { display:flex; gap:8px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; }
        .scraper-tab { padding:10px 18px; cursor:pointer; border:1px solid transparent; border-bottom:none;
                       border-radius:8px 8px 0 0; color:#374151; text-decoration:none; font-weight:600; font-size:14px; }
        .scraper-tab.active { background:#fff; border-color:#e5e7eb; color:#111827; }
        .scraper-panel { background:#fff; border:1px solid #e5e7eb; border-radius:0 8px 8px 8px; padding:24px; }
        .scraper-placeholder { color:#6b7280; font-size:14px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper" style="padding:24px;">
            <h1 style="font-size:22px;margin-bottom:6px;"><i class="fas fa-newspaper"></i> News Scraper</h1>
            <p class="scraper-placeholder" style="margin-bottom:20px;">
                Collate sources and generate original, SEO-optimised articles per publication and section.
            </p>

            <?php if (empty($projects)): ?>
                <div class="scraper-panel">
                    <p class="scraper-placeholder">No scraper projects found. Run <code>scraper/schema.sql</code> on the database.</p>
                </div>
            <?php else: ?>
                <div class="scraper-tabs">
                    <?php foreach ($projects as $p): ?>
                        <a class="scraper-tab <?php echo $p['type'] === $activeType ? 'active' : ''; ?>"
                           href="module-scraper.php?project=<?php echo urlencode($p['type']); ?>">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="scraper-panel">
                    <?php
                    $active = null;
                    foreach ($projects as $p) { if ($p['type'] === $activeType) { $active = $p; break; } }
                    if (!$active) { $active = $projects[0]; }
                    ?>
                    <h2 style="font-size:17px;margin-bottom:10px;"><?php echo htmlspecialchars($active['name']); ?></h2>
                    <?php if ($active['type'] === 'news_collation'): ?>
                        <p class="scraper-placeholder">
                            Source, section, schedule and prompt configuration arrives in Phase 2.
                            <?php if ($canManage): ?>You have manage access.<?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="scraper-placeholder">Cafe/Restaurant email collection — configuration coming soon.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
