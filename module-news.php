<?php
require_once 'config.php';
requireLogin();

// Match the sidebar gate (ten_modules.required_permission = 'news.view') so the
// page can't be reached by direct URL by users who don't hold the permission
// (e.g. journalists).
if (!hasPermission('news.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$currentUser = getCurrentUser();
$canEdit = isAdmin();
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEN News Sites — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <link rel="stylesheet" href="css/news_sites_design.css?v=<?php echo @filemtime(__DIR__ . '/css/news_sites_design.css'); ?>">
    <style>
        .ns-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .ns-btn { background:#2563eb; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        .ns-btn.secondary { background:#475569; }
        .ns-btn.small { padding:4px 10px; font-size:12px; }
        .ns-card { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:12px; overflow:hidden; background:#fff; }
        .ns-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 14px; background:#f9fafb; }
        .ns-title { font-weight:600; color:#111827; font-size:14px; }
        .ns-meta { color:#6b7280; font-size:12px; margin-top:2px; }
        .ns-body { padding:12px 14px; display:none; border-top:1px solid #eef2f7; }
        .ns-body.open { display:block; }
        .ns-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
        .ns-badge.on { background:#dcfce7; color:#166534; }
        .ns-badge.off { background:#f1f5f9; color:#475569; }
        table.ns-feeds { width:100%; border-collapse:collapse; font-size:12px; }
        table.ns-feeds th, table.ns-feeds td { text-align:left; padding:6px 8px; border-bottom:1px solid #eef2f7; }
        .ns-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:6000; }
        .ns-modal { position:fixed; z-index:6001; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(560px,94vw); background:#fff; border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,.3); padding:22px; }
        .ns-modal h3 { margin:0 0 16px; font-size:17px; }
        .ns-field { margin-bottom:12px; }
        .ns-field label { display:block; font-weight:600; font-size:12px; color:#374151; margin-bottom:5px; }
        .ns-field input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; box-sizing:border-box; }
        .ns-field input:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
        .ns-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
        .ns-placeholder { color:#6b7280; font-size:14px; }

        /* ---- Edit/Add publication modal: sectioned, visual layout ---- */
        #nsModal { width:min(600px,94vw); max-height:90vh; overflow:auto; padding:24px; }
        .ns-modal-head { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
        .ns-modal-icon { width:44px; height:44px; border-radius:11px; flex:0 0 auto;
                         background:linear-gradient(135deg,#2563eb,#1e40af); color:#fff;
                         display:flex; align-items:center; justify-content:center; font-size:19px; }
        .ns-modal-head h3 { margin:0; font-size:18px; line-height:1.2; }
        .ns-modal-sub { margin:3px 0 0; font-size:12px; color:#6b7280; }
        .ns-section { border-top:1px solid #eef2f7; padding-top:16px; margin-top:16px; }
        .ns-section:first-of-type { border-top:none; padding-top:0; margin-top:0; }
        .ns-section-title { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
                            color:#94a3b8; margin-bottom:12px; }
        .ns-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media (max-width:520px){ .ns-grid { grid-template-columns:1fr; } }
        .ns-hint { font-size:12px; color:#6b7280; margin-top:5px; }

        /* toggle rows */
        .ns-toggle-row { display:flex; align-items:center; justify-content:space-between; gap:14px;
                         padding:13px 15px; border:1px solid #e5e7eb; border-radius:10px;
                         background:#f9fafb; margin-bottom:10px; transition:border-color .15s, background .15s; }
        .ns-toggle-row:last-child { margin-bottom:0; }
        .ns-toggle-row.is-on { background:#fff; border-color:#cbd5e1; }
        .ns-toggle-text { min-width:0; }
        .ns-toggle-label { display:block; font-weight:600; font-size:13px; color:#111827; }
        .ns-toggle-label i { margin-right:7px; color:#94a3b8; }
        .ns-toggle-row.is-on .ns-toggle-label i.fa-bolt { color:#f59e0b; }
        .ns-toggle-desc { display:block; font-size:12px; color:#6b7280; margin-top:3px; line-height:1.45; }

        /* switch */
        .ns-switch { position:relative; display:inline-block; width:46px; height:26px; flex:0 0 auto; }
        .ns-switch input { opacity:0; width:0; height:0; }
        .ns-slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:999px; transition:.2s; }
        .ns-slider::before { content:""; position:absolute; height:20px; width:20px; left:3px; top:3px;
                             background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.25); }
        .ns-switch input:checked + .ns-slider { background:#16a34a; }          /* live = green */
        .ns-switch input:checked + .ns-slider::before { transform:translateX(20px); }
        .ns-switch.brk input:checked + .ns-slider { background:#f59e0b; }      /* breaking = amber */
        .ns-switch input:focus-visible + .ns-slider { outline:2px solid #2563eb; outline-offset:2px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper" style="padding:24px;">
            <h1 style="font-size:22px;margin-bottom:6px;"><i class="fas fa-newspaper"></i> TEN News Sites</h1>
            <p class="ns-placeholder" style="margin-bottom:20px;">
                Your publications — set the acronym, site name and URL, and see the scraper feed URLs configured to feed each one.
            </p>

            <div id="nsRoot" data-can-edit="<?php echo $canEdit ? '1' : '0'; ?>">
                <div class="ns-toolbar">
                    <?php if ($canEdit): ?><button class="ns-btn" id="nsAdd"><i class="fas fa-plus"></i> Add publication</button><?php endif; ?>
                    <a class="ns-btn secondary" href="module-scraper.php" style="text-decoration:none;"><i class="fas fa-sliders"></i> Configure scraping feeds</a>
                </div>
                <div id="nsList"><p class="ns-placeholder">Loading…</p></div>
            </div>

            <div id="nsOverlay" class="ns-overlay" style="display:none;"></div>
            <div id="nsModal" class="ns-modal" style="display:none;"><div id="nsModalBody"></div></div>

            <!-- Design editor mounts here (large modal built by news_sites_design.js) -->
            <div id="dlDesignRoot"></div>
        </div>
    </div>
    <script src="js/news_sites.js?v=<?php echo @filemtime(__DIR__ . '/js/news_sites.js'); ?>"></script>
    <script src="js/news_sites_design.js?v=<?php echo @filemtime(__DIR__ . '/js/news_sites_design.js'); ?>"></script>
</body>
</html>
