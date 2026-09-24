<?php
require_once 'config.php';
require_once __DIR__ . '/lib/notes_core.php';
requireLogin();

// Notes are available to every logged-in user (same as the post-it widget).
$currentUser = getCurrentUser();
$currentPage = 'notes';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Notes — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="js/vendor/sweetalert2.all.min.js"></script>
    <style>
    /* Notes overview — scoped to .nov so app chrome is untouched. */
    .nov { --nov-border:#e6e8ec; --nov-ink:#15181e; --nov-muted:#6b7280; --nov-surface:#fff; --nov-surface-2:#f8fafb; }
    .nov h1 { font-size:22px; margin-bottom:6px; }
    .nov .nov-sub { color:var(--nov-muted); margin-bottom:18px; }
    .nov-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .nov-toolbar .nov-search { position:relative; flex:1 1 260px; min-width:200px; }
    .nov-toolbar .nov-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9aa0aa; }
    .nov-toolbar input[type=search] { width:100%; padding:10px 12px 10px 34px; border:1px solid var(--nov-border); border-radius:9px; font-size:14px; background:var(--nov-surface); }
    .nov-toolbar select { padding:10px 12px; border:1px solid var(--nov-border); border-radius:9px; font-size:14px; background:var(--nov-surface); }
    .nov-card { background:var(--nov-surface); border:1px solid var(--nov-border); border-radius:12px; overflow:hidden; box-shadow:0 1px 2px rgba(16,24,40,.05); }
    .nov-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    .nov-table th { text-align:left; padding:11px 14px; background:var(--nov-surface-2); color:#475467; font-weight:600; border-bottom:1px solid var(--nov-border); white-space:nowrap; }
    .nov-table td { padding:11px 14px; border-bottom:1px solid #f0f2f5; vertical-align:top; }
    .nov-table tbody tr { cursor:pointer; }
    .nov-table tbody tr:hover { background:var(--nov-surface-2); }
    .nov-swatch { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:8px; vertical-align:middle; }
    .nov-title { font-weight:600; color:var(--nov-ink); }
    .nov-snippet { color:var(--nov-muted); font-weight:400; }
    .nov-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:600; }
    .nov-st-active,.nov-st-in_progress { background:#e7f6ec; color:#0f7a3d; }
    .nov-st-on_hold { background:#fff4e5; color:#b45309; }
    .nov-st-completed { background:#eef1f5; color:#475467; }
    .nov-st-archived { background:#f2f4f7; color:#98a0ac; }
    .nov-people { display:flex; flex-wrap:wrap; gap:4px; }
    .nov-chip { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:11.5px; }
    .nov-muted { color:var(--nov-muted); }
    .nov-overdue { color:#dc2626; font-weight:600; }
    .nov-link { color:#3c4f6d; text-decoration:none; }
    .nov-link:hover { text-decoration:underline; }
    .nov-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border-top:1px solid var(--nov-border); flex-wrap:wrap; }
    .nov-pager { display:flex; gap:6px; align-items:center; }
    .nov-btn { padding:7px 12px; border:1px solid var(--nov-border); background:var(--nov-surface); border-radius:8px; font-size:13px; cursor:pointer; }
    .nov-btn[disabled] { opacity:.45; cursor:default; }
    .nov-empty { padding:36px 14px; text-align:center; color:var(--nov-muted); }
    .nov-sort { cursor:pointer; user-select:none; }
    .nov-sort:hover { color:var(--nov-ink); }
    .nov-sort .nov-arrow { color:#3c4f6d; font-size:10px; margin-left:5px; }
    /* Row actions */
    .nov-col-actions { width:44px; }
    .nov-actions { position:relative; text-align:right; }
    .nov-kebab { border:none; background:none; color:#98a0ac; cursor:pointer; padding:4px 8px; border-radius:6px; font-size:15px; }
    .nov-kebab:hover { background:#eef2f7; color:#475467; }
    .nov-menu { position:absolute; right:8px; top:34px; z-index:20; background:#fff; border:1px solid var(--nov-border); border-radius:10px;
        box-shadow:0 8px 24px rgba(16,24,40,.14); min-width:170px; padding:5px; display:none; }
    .nov-menu.open { display:block; }
    .nov-menu button { display:flex; align-items:center; gap:9px; width:100%; text-align:left; border:none; background:none; cursor:pointer;
        padding:8px 10px; border-radius:7px; font-size:13px; color:#344054; }
    .nov-menu button:hover { background:#f2f4f7; }
    .nov-menu button.nov-danger { color:#b42318; }
    .nov-menu button.nov-danger:hover { background:#fdeceb; }
    .nov-menu i { width:15px; text-align:center; color:#98a0ac; }
    .nov-menu button.nov-danger i { color:#b42318; }
    @media (max-width:900px){ .nov-col-project,.nov-col-created { display:none; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper nov" style="padding:24px;">
            <h1><i class="fas fa-note-sticky" style="color:#eab308;"></i> All Notes</h1>
            <p class="nov-sub">Every note you own or that's shared with you, across all pages and projects. Search, filter and open any note.</p>

            <div class="nov-toolbar">
                <div class="nov-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="search" id="novSearch" placeholder="Search titles, text and pages…" autocomplete="off">
                </div>
                <select id="novStatus" title="Status">
                    <option value="all">All (except deleted)</option>
                    <option value="open">Open</option>
                    <option value="completed">Completed</option>
                    <option value="archived">Archived</option>
                </select>
                <select id="novProject" title="Project"><option value="0">All projects</option></select>
            </div>

            <div class="nov-card">
                <table class="nov-table">
                    <thead>
                        <tr>
                            <th class="nov-sort" data-sort="title">Note</th>
                            <th class="nov-sort" data-sort="page">Page</th>
                            <th class="nov-sort nov-col-project" data-sort="project">Project</th>
                            <th class="nov-sort" data-sort="author">Author</th>
                            <th>Assigned to</th>
                            <th class="nov-sort" data-sort="deadline">Deadline</th>
                            <th class="nov-sort" data-sort="reminder">Next reminder</th>
                            <th class="nov-sort" data-sort="status">Status</th>
                            <th class="nov-sort nov-col-created" data-sort="created">Created</th>
                            <th class="nov-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody id="novRows">
                        <tr><td colspan="10" class="nov-empty">Loading notes…</td></tr>
                    </tbody>
                </table>
                <div class="nov-foot">
                    <div class="nov-muted" id="novCount">—</div>
                    <div class="nov-pager">
                        <button class="nov-btn" id="novPrev" disabled>← Prev</button>
                        <span class="nov-muted" id="novPage">Page 1</span>
                        <button class="nov-btn" id="novNext" disabled>Next →</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="js/notes_overview.js?v=<?php echo @filemtime(__DIR__ . '/js/notes_overview.js'); ?>" defer></script>
</body>
</html>
