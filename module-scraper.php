<?php
require_once 'config.php';
require_once __DIR__ . '/scraper/lib/scraper_db.php';
requireLogin();

if (!hasPermission('scraper.use') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$currentUser = getCurrentUser();
$projects = scraper_get_projects();
$canManage = hasPermission('scraper.manage') || isAdmin();
$activeType = $_GET['project'] ?? ($projects[0]['type'] ?? 'news_collation');

$active = null;
foreach ($projects as $p) { if ($p['type'] === $activeType) { $active = $p; break; } }
if (!$active && !empty($projects)) { $active = $projects[0]; }
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Scraper — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="js/vendor/sweetalert2.all.min.js"></script>
    <style>
        .scraper-tabs { display:flex; gap:8px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; }
        .scraper-tab { padding:10px 18px; cursor:pointer; border:1px solid transparent; border-bottom:none;
                       border-radius:8px 8px 0 0; color:#374151; text-decoration:none; font-weight:600; font-size:14px; }
        .scraper-tab.active { background:#fff; border-color:#e5e7eb; color:#111827; }
        .scraper-panel { background:#fff; border:1px solid #e5e7eb; border-radius:0 8px 8px 8px; padding:24px; }
        .scraper-placeholder { color:#6b7280; font-size:14px; }
        .sc-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .sc-btn { background:#2563eb; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        .sc-btn.secondary { background:#475569; }
        .sc-btn.danger { background:#dc2626; }
        .sc-btn.small { padding:4px 10px; font-size:12px; }
        .sc-card { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:12px; overflow:hidden; }
        .sc-card-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 14px; background:#f9fafb; }
        .sc-card-title { font-weight:600; color:#111827; font-size:14px; }
        .sc-card-meta { color:#6b7280; font-size:12px; margin-top:2px; }
        .sc-card-body { padding:12px 14px; display:none; border-top:1px solid #eef2f7; }
        .sc-card-body.open { display:block; }
        .sc-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border:1px solid #eef2f7; border-radius:6px; margin-bottom:8px; }
        .sc-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
        .sc-badge.on { background:#dcfce7; color:#166534; }
        .sc-badge.off { background:#f1f5f9; color:#475569; }
        .sc-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:6000; }
        .sc-modal { position:fixed; z-index:6001; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(640px,94vw); max-height:88vh; overflow:auto; background:#fff; border-radius:12px;
                    box-shadow:0 20px 50px rgba(0,0,0,.3); padding:22px; }
        .sc-modal h3 { margin:0 0 16px; font-size:17px; }
        .sc-field { margin-bottom:12px; }
        .sc-field label { display:block; font-weight:600; font-size:12px; color:#374151; margin-bottom:5px; }
        .sc-field input, .sc-field select, .sc-field textarea {
            width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; box-sizing:border-box; }
        .sc-field textarea { min-height:160px; font-family:ui-monospace,Menlo,Consolas,monospace; }
        .sc-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
        .sc-inline { display:flex; gap:12px; }
        .sc-inline > * { flex:1; }
        .sc-subtabs { display:flex; gap:6px; margin-bottom:16px; }
        .sc-subtab { padding:8px 14px; border:1px solid #e5e7eb; background:#f9fafb; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px; color:#374151; }
        .sc-subtab.active { background:#2563eb; color:#fff; border-color:#2563eb; }
        .rv-item { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border:1px solid #eef2f7; border-radius:8px; margin-bottom:8px; }
        .rv-item.sel { border-color:#2563eb; background:#eff6ff; }
        .rv-item input[type=checkbox] { margin-top:3px; width:16px; height:16px; }
        .rv-title { font-weight:600; color:#111827; font-size:14px; }
        .rv-summary { color:#374151; font-size:13px; margin-top:3px; }
        .rv-orig { color:#6b7280; font-size:12px; margin-top:5px; padding-top:5px; border-top:1px dashed #e5e7eb; display:none; }
        .rv-meta { color:#94a3b8; font-size:11px; margin-top:5px; }
        .rv-meta a { color:#2563eb; }
        .rv-toggle { color:#2563eb; cursor:pointer; font-size:11px; }
        .rv-log-line { font-size:13px; padding:3px 0; }
        .rv-ok { color:#166534; } .rv-err { color:#b91c1c; }
        .rv-banner { background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:10px; }
        .sc-spinner-wrap { color:#6b7280; font-size:14px; padding:20px 0; }
        .sc-spinner { display:inline-block; width:16px; height:16px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:sc-spin .8s linear infinite; vertical-align:middle; margin-right:6px; }
        @keyframes sc-spin { to { transform:rotate(360deg); } }
        .sc-htable { width:100%; border-collapse:collapse; font-size:13px; }
        .sc-htable th, .sc-htable td { text-align:left; padding:8px 10px; border-bottom:1px solid #eef2f7; vertical-align:top; }
        .sc-htable th { color:#6b7280; font-weight:600; font-size:12px; }
        .sc-htable a { color:#2563eb; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper" style="padding:24px;">
            <h1 style="font-size:22px;margin-bottom:6px;"><i class="fas fa-database"></i> Data Scraper</h1>
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
                    <h2 style="font-size:17px;margin-bottom:14px;"><?php echo htmlspecialchars($active['name']); ?></h2>

                    <?php if ($active['type'] === 'news_collation'): ?>
                        <div class="sc-subtabs">
                            <button class="sc-subtab active" data-view="review"><i class="fas fa-list-check"></i> Review &amp; Promote</button>
                            <button class="sc-subtab" data-view="history"><i class="fas fa-clock-rotate-left"></i> History</button>
                            <?php if ($canManage): ?><button class="sc-subtab" data-view="configure"><i class="fas fa-sliders"></i> Configure</button><?php endif; ?>
                        </div>

                        <div id="scViewReview" data-project-id="<?php echo (int)$active['id']; ?>">
                            <div class="sc-toolbar" style="align-items:center;">
                                <label style="font-size:13px;font-weight:600;color:#374151;">Section:
                                    <select id="scReviewSection" style="margin-left:6px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;"><option value="">Loading…</option></select>
                                </label>
                                <span id="scReviewCounter" class="scraper-placeholder"></span>
                                <button class="sc-btn" id="scPromote" disabled><i class="fas fa-wand-magic-sparkles"></i> Promote selected</button>
                            </div>
                            <div id="scPromoteLog"></div>
                            <div id="scReviewList"><p class="scraper-placeholder">Choose a section to review its collated articles.</p></div>
                        </div>

                        <div id="scViewHistory" data-project-id="<?php echo (int)$active['id']; ?>" style="display:none;">
                            <div class="sc-toolbar" style="align-items:center;">
                                <label style="font-size:13px;font-weight:600;color:#374151;">Section:
                                    <select id="scHistorySection" style="margin-left:6px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;"><option value="">Loading…</option></select>
                                </label>
                                <span class="scraper-placeholder">Last 30 days — what was collated, from where, and what was promoted.</span>
                            </div>
                            <div id="scHistoryList"><p class="scraper-placeholder">Choose a section to see its recent history.</p></div>
                        </div>

                        <?php if ($canManage): ?>
                        <div id="scViewConfigure" style="display:none;">
                            <div id="scraperManage" data-project-id="<?php echo (int)$active['id']; ?>"
                                 data-provider="<?php echo htmlspecialchars($active['default_ai_provider']); ?>"
                                 data-model="<?php echo htmlspecialchars($active['default_ai_model']); ?>">
                                <div class="sc-toolbar">
                                    <button class="sc-btn" id="scAddSection"><i class="fas fa-plus"></i> Add publication / section</button>
                                    <button class="sc-btn secondary" id="scEditPrompt"><i class="fas fa-gear"></i> Project settings</button>
                                    <button class="sc-btn secondary" id="scManageVpn"><i class="fas fa-shield-halved"></i> VPN profiles</button>
                                </div>
                                <div id="scSections"><p class="scraper-placeholder">Loading…</p></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div id="scOverlay" class="sc-overlay" style="display:none;"></div>
                        <div id="scModal" class="sc-modal" style="display:none;"><div id="scModalBody"></div></div>

                        <script src="js/scraper_review.js"></script>
                        <?php if ($canManage): ?><script src="js/scraper_config.js"></script><?php endif; ?>
                        <script>
                        (function () {
                            var tabs = document.querySelectorAll('.sc-subtab');
                            tabs.forEach(function (t) {
                                t.addEventListener('click', function () {
                                    tabs.forEach(function (x) { x.classList.remove('active'); });
                                    t.classList.add('active');
                                    var v = t.getAttribute('data-view');
                                    document.getElementById('scViewReview').style.display = (v === 'review') ? 'block' : 'none';
                                    var hist = document.getElementById('scViewHistory');
                                    if (hist) hist.style.display = (v === 'history') ? 'block' : 'none';
                                    var cfg = document.getElementById('scViewConfigure');
                                    if (cfg) cfg.style.display = (v === 'configure') ? 'block' : 'none';
                                });
                            });
                        })();
                        </script>
                    <?php else: ?>
                        <p class="scraper-placeholder">Cafe/Restaurant email collection — configuration coming soon.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
