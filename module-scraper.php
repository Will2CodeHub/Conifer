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
    /* ============================================================
       TEN Scraper — editorial cockpit design system.
       Ink-forward, near-monochrome UI with one warm amber accent and
       a system serif for editorial headings. Scoped to .sc-app so the
       app chrome (sidebar/header/nav) is never touched.
       ============================================================ */
    .sc-app {
        --sc-surface:#ffffff;
        --sc-surface-2:#f8fafb;
        --sc-surface-3:#f2f4f7;
        --sc-border:#e6e8ec;
        --sc-border-strong:#d3d8e0;
        --sc-ink:#15181e;          /* primary text / headings */
        --sc-primary:#3c4f6d;      /* selected pills / primary buttons — muted slate-blue */
        --sc-primary-hover:#31415a;
        --sc-primary-ring:rgba(60,79,109,.26);
        --sc-text:#2b313b;
        --sc-muted:#6b7280;
        --sc-faint:#98a0ac;
        --sc-accent:#b45309;       /* amber-800 — editorial accent, used sparingly */
        --sc-accent-strong:#92400e;
        --sc-accent-tint:#fff7ed;
        --sc-accent-soft:#fbe3c6;
        --sc-ring:rgba(180,83,9,.30);
        --sc-published:#0f7a3d; --sc-published-bg:#e7f6ec;
        --sc-draft:#3f43c7;      --sc-draft-bg:#e9eafc;
        --sc-ignored:#b42318;    --sc-ignored-bg:#fdeceb;
        --sc-collated:#475467;   --sc-collated-bg:#eef1f5;
        --sc-radius:12px; --sc-radius-sm:9px; --sc-radius-xs:7px;
        --sc-shadow:0 1px 2px rgba(16,24,40,.05), 0 1px 3px rgba(16,24,40,.05);
        --sc-shadow-md:0 4px 14px rgba(16,24,40,.08);
        --sc-shadow-lg:0 18px 44px rgba(16,24,40,.18);
        --sc-serif:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,"Times New Roman",serif;
        color:var(--sc-text);
        -webkit-font-smoothing:antialiased;
    }
    .sc-app *,.sc-app *::before,.sc-app *::after { box-sizing:border-box; }

    /* headings & intro (below the tool title) */
    .sc-app h1 { letter-spacing:-.01em; }
    .sc-panel-title { font-family:var(--sc-serif); font-weight:600; font-size:22px; letter-spacing:-.01em;
        color:var(--sc-ink); margin:0 0 2px; }
    .scraper-placeholder { color:var(--sc-muted); font-size:13.5px; line-height:1.55; }

    /* project tabs — segmented pill control */
    .scraper-tabs { display:inline-flex; gap:4px; padding:4px; margin-bottom:18px;
        background:var(--sc-surface-3); border-radius:11px; border:1px solid var(--sc-border); }
    .scraper-tab { padding:7px 16px; cursor:pointer; border:none; border-radius:8px; color:var(--sc-muted);
        text-decoration:none; font-weight:600; font-size:13px; transition:background .15s,color .15s; }
    .scraper-tab:hover { color:var(--sc-ink); }
    .scraper-tab.active { background:var(--sc-surface); color:var(--sc-ink); box-shadow:var(--sc-shadow); }

    /* main panel */
    .scraper-panel { background:var(--sc-surface); border:1px solid var(--sc-border);
        border-radius:var(--sc-radius); padding:24px 26px; box-shadow:var(--sc-shadow); }

    /* view tabs (Curate / History / Configure) — underline tabs */
    .sc-subtabs { display:flex; gap:2px; border-bottom:1px solid var(--sc-border); margin-bottom:18px; flex-wrap:wrap; }
    .sc-subtab { position:relative; padding:10px 15px; border:none; background:transparent; cursor:pointer;
        font-weight:600; font-size:13px; color:var(--sc-muted); margin-bottom:-1px; border-radius:8px 8px 0 0;
        transition:color .15s,background .15s; }
    .sc-subtab:hover { color:var(--sc-ink); background:var(--sc-surface-2); }
    .sc-subtab.active { color:var(--sc-ink); }
    .sc-subtab.active::after { content:""; position:absolute; left:12px; right:12px; bottom:0; height:2px;
        background:var(--sc-accent); border-radius:2px 2px 0 0; }
    .sc-subtab i { opacity:.85; }

    /* publication tabs — count-badged pills (draggable) */
    #scCuratePubTabs { display:flex; gap:9px; flex-wrap:wrap; border:none; margin:14px 0 22px; }
    .sc-pub { position:relative; display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:8px;
        border:1px solid var(--sc-border-strong); background:var(--sc-surface); color:var(--sc-text);
        font-weight:600; font-size:12.5px; letter-spacing:.02em; cursor:pointer;
        transition:border-color .15s,background .15s,color .15s,box-shadow .15s; }
    /* children ignore pointer events so drag-over/drop always target the pill itself */
    .sc-pub > * { pointer-events:none; }
    .sc-pub:hover { border-color:var(--sc-primary); color:var(--sc-ink); }
    .sc-pub.active { background:var(--sc-primary); border-color:var(--sc-primary); color:#fff; box-shadow:var(--sc-shadow-md); }
    /* drop indicator: a blue bar on the left edge of the pill you'd drop before */
    .sc-pub.sc-drop-before::before { content:""; position:absolute; left:-5px; top:2px; bottom:2px; width:3px;
        border-radius:2px; background:var(--sc-primary); }
    .sc-pub .sc-count { font-variant-numeric:tabular-nums; font-weight:700; font-size:11px;
        padding:1px 7px; border-radius:6px; background:var(--sc-surface-3); color:var(--sc-muted); }
    .sc-pub.active .sc-count { background:rgba(255,255,255,.2); color:#fff; }
    .sc-pub-grip { color:var(--sc-faint); font-size:10px; cursor:grab; display:inline-flex; margin:0 -2px 0 -1px; }
    .sc-pub:hover .sc-pub-grip { color:var(--sc-muted); }
    .sc-pub.active .sc-pub-grip { color:rgba(255,255,255,.6); }
    .sc-pub-dot { display:inline-flex; align-items:center; justify-content:center; width:19px; height:19px;
        border-radius:6px; font-size:9.5px; font-weight:800; color:#fff; letter-spacing:.01em; flex-shrink:0;
        box-shadow:inset 0 0 0 1px rgba(255,255,255,.18); }
    .sc-pub-key { font-weight:700; }
    /* interactive hover tooltip (publication name + front-page link) */
    .sc-pub-pop { position:absolute; z-index:6500; background:var(--sc-ink); color:#fff; border-radius:10px;
        padding:10px 13px; box-shadow:var(--sc-shadow-lg); font-size:12.5px; max-width:280px; line-height:1.4;
        opacity:0; transform:translateY(-4px); transition:opacity .12s,transform .12s; pointer-events:none; }
    .sc-pub-pop.show { opacity:1; transform:translateY(0); pointer-events:auto; }
    .sc-pub-pop .sc-pop-name { font-weight:700; margin-bottom:5px; }
    .sc-pub-pop .sc-pop-key { color:rgba(255,255,255,.55); font-weight:600; }
    .sc-pub-pop a { color:#fff; font-weight:600; text-decoration:none; font-size:12px; display:inline-flex; align-items:center; gap:6px; }
    .sc-pub-pop a:hover { text-decoration:underline; }
    .sc-pub-pop::before { content:""; position:absolute; top:-5px; left:22px; width:10px; height:10px;
        background:var(--sc-ink); transform:rotate(45deg); border-radius:2px; }

    /* section chips */
    #scCurateSectionBar { display:flex; gap:9px; flex-wrap:wrap; align-items:center; margin:0 0 22px; }
    .sc-secbar-label { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
        color:var(--sc-faint); margin-right:2px; }
    .sc-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:8px;
        border:1px solid var(--sc-border-strong); background:var(--sc-surface); color:var(--sc-text);
        font-size:12.5px; font-weight:600; cursor:pointer; transition:all .15s; }
    .sc-chip:hover { border-color:var(--sc-primary); color:var(--sc-ink); }
    .sc-chip.active { background:var(--sc-primary); border-color:var(--sc-primary); color:#fff; }
    .sc-chip .sc-count { font-variant-numeric:tabular-nums; font-size:11px; opacity:.65; font-weight:700; }
    .sc-chip.active .sc-count { opacity:.85; }

    /* mode tabs (Curated pick / All today / Published today) — segmented toggle */
    #scCurateModeTabs { display:inline-flex; gap:3px; padding:4px; border:1px solid var(--sc-border);
        background:var(--sc-surface-3); border-radius:11px; margin:0 0 20px; }
    #scCurateModeTabs .sc-subtab { margin-bottom:0; border-radius:8px; padding:7px 15px; color:var(--sc-muted); }
    #scCurateModeTabs .sc-subtab:hover { background:transparent; color:var(--sc-ink); }
    #scCurateModeTabs .sc-subtab.active { background:var(--sc-surface); color:var(--sc-ink); box-shadow:var(--sc-shadow); }
    #scCurateModeTabs .sc-subtab.active::after { display:none; }

    /* buttons */
    .sc-btn { display:inline-flex; align-items:center; gap:7px; background:var(--sc-primary); color:#fff; border:1px solid var(--sc-primary);
        padding:9px 15px; border-radius:var(--sc-radius-xs); cursor:pointer; font-size:13px; font-weight:600;
        line-height:1; transition:background .15s,border-color .15s,box-shadow .15s,opacity .15s; }
    .sc-btn:hover { background:var(--sc-primary-hover); border-color:var(--sc-primary-hover); }
    .sc-btn:active { filter:brightness(.96); }
    .sc-btn:focus-visible { outline:none; box-shadow:0 0 0 3px var(--sc-primary-ring); }
    .sc-btn:disabled { opacity:.55; cursor:default; }
    .sc-btn.secondary { background:var(--sc-surface); color:var(--sc-text); border-color:var(--sc-border-strong); }
    .sc-btn.secondary:hover { background:var(--sc-surface-2); border-color:var(--sc-primary); color:var(--sc-ink); }
    .sc-btn.accent { background:var(--sc-accent); border-color:var(--sc-accent); }
    .sc-btn.danger { background:var(--sc-ignored); border-color:var(--sc-ignored); }
    .sc-btn.small { padding:5px 11px; font-size:12px; }

    /* toolbar / action bar */
    .sc-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
    #scCurateActionBar { align-items:center; gap:14px; padding:11px 14px; margin-bottom:14px;
        background:var(--sc-surface-2); border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); }
    .sc-selectall { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--sc-text); cursor:pointer; }
    .sc-status { font-size:12.5px; color:var(--sc-muted); }
    .sc-status.ok { color:var(--sc-published); font-weight:600; }
    .sc-status.warn { color:var(--sc-accent-strong); font-weight:600; }

    /* story rows */
    #scCurRows { display:flex; flex-direction:column; gap:8px; }
    .sc-item { display:flex; gap:12px; align-items:flex-start; padding:13px 15px; background:var(--sc-surface);
        border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); cursor:pointer;
        transition:border-color .15s,box-shadow .15s,background .15s; }
    .sc-item:hover { border-color:var(--sc-border-strong); box-shadow:var(--sc-shadow); }
    .sc-item.is-promoted { background:var(--sc-surface-2); cursor:default; }
    .sc-item.is-promoted:hover { box-shadow:none; border-color:var(--sc-border); }
    .sc-ck { margin-top:2px; width:17px; height:17px; flex-shrink:0; accent-color:var(--sc-ink); cursor:pointer; }
    .sc-ck:disabled { cursor:default; }
    .sc-item-main { flex:1; min-width:0; }
    .sc-item-title { font-weight:600; font-size:14px; color:var(--sc-ink); line-height:1.4; }
    .sc-item-reason { font-size:12px; color:var(--sc-accent-strong); margin-top:4px; }
    .sc-item-reason i { color:var(--sc-accent); }
    .sc-item-sub { font-size:12.5px; color:var(--sc-muted); margin-top:4px; line-height:1.5; }
    .sc-item-meta { font-size:11.5px; color:var(--sc-faint); margin-top:6px; display:flex; flex-wrap:wrap; gap:4px 8px; align-items:center; }
    .sc-item-meta a { color:var(--sc-faint); text-decoration:none; }
    .sc-item-meta a:hover { color:var(--sc-muted); text-decoration:underline; }

    /* status + action links */
    .sc-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:999px;
        font-size:11px; font-weight:700; letter-spacing:.01em; white-space:nowrap; }
    .sc-badge.published { background:var(--sc-published-bg); color:var(--sc-published); }
    .sc-badge.draft { background:var(--sc-draft-bg); color:var(--sc-draft); }
    .sc-badge.ignored { background:var(--sc-ignored-bg); color:var(--sc-ignored); }
    .sc-badge.collated { background:var(--sc-collated-bg); color:var(--sc-collated); }
    .sc-badge.headline { background:var(--sc-ignored-bg); color:var(--sc-ignored); }
    .sc-badge.on { background:var(--sc-published-bg); color:var(--sc-published); }
    .sc-badge.off { background:var(--sc-collated-bg); color:var(--sc-collated); }
    .sc-link { font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .sc-link.live { color:var(--sc-published); }
    .sc-link.edit { color:var(--sc-draft); }
    .sc-link.view { color:var(--sc-ink); }
    .sc-link.accent { color:var(--sc-accent); }
    .sc-link:hover { text-decoration:underline; }

    /* published-today list */
    .sc-pub-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
        margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--sc-border); }
    .sc-pub-head .sc-pub-count { font-size:13px; color:var(--sc-muted); }
    .sc-pub-head .sc-pub-count strong { color:var(--sc-ink); font-variant-numeric:tabular-nums; }
    .sc-pt-row { display:flex; gap:12px; align-items:flex-start; padding:13px 15px; background:var(--sc-surface);
        border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); }
    .sc-pt-row.is-headline { border-color:var(--sc-accent-soft); background:var(--sc-accent-tint); }
    .sc-pt-idx { font-variant-numeric:tabular-nums; font-size:12px; font-weight:700; color:var(--sc-faint); margin-top:2px; min-width:20px; }
    .sc-pt-main { flex:1; min-width:0; }
    .sc-pt-title { font-weight:600; font-size:14px; color:var(--sc-ink); line-height:1.4; }
    .sc-pt-links { margin-top:7px; display:flex; flex-wrap:wrap; gap:6px 14px; align-items:center; }

    /* cards (Configure) */
    .sc-card { border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); margin-bottom:12px;
        overflow:hidden; background:var(--sc-surface); box-shadow:var(--sc-shadow); }
    .sc-card-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:13px 15px; background:var(--sc-surface-2); }
    .sc-card-title { font-weight:600; color:var(--sc-ink); font-size:14px; }
    .sc-card-meta { color:var(--sc-muted); font-size:12px; margin-top:2px; }
    .sc-card-body { padding:14px 15px; display:none; border-top:1px solid var(--sc-border); }
    .sc-card-body.open { display:block; }
    .sc-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 11px;
        border:1px solid var(--sc-border); border-radius:var(--sc-radius-xs); margin-bottom:8px; }

    /* modal */
    .sc-overlay { position:fixed; inset:0; background:rgba(16,20,28,.5); z-index:6000; backdrop-filter:blur(2px); }
    .sc-modal { position:fixed; z-index:6001; top:50%; left:50%; transform:translate(-50%,-50%);
        width:min(640px,94vw); max-height:88vh; overflow:auto; background:var(--sc-surface);
        border-radius:var(--sc-radius); box-shadow:var(--sc-shadow-lg); padding:24px; }
    .sc-modal h3 { margin:0 0 16px; font-size:18px; font-family:var(--sc-serif); color:var(--sc-ink); }
    .sc-field { margin-bottom:13px; }
    .sc-field label { display:block; font-weight:600; font-size:12px; color:var(--sc-text); margin-bottom:5px; }
    .sc-field input, .sc-field select, .sc-field textarea {
        width:100%; padding:9px 11px; border:1px solid var(--sc-border-strong); border-radius:var(--sc-radius-xs);
        font-size:13px; color:var(--sc-text); background:var(--sc-surface); transition:border-color .15s,box-shadow .15s; }
    .sc-field input:focus, .sc-field select:focus, .sc-field textarea:focus {
        outline:none; border-color:var(--sc-accent); box-shadow:0 0 0 3px var(--sc-ring); }
    .sc-field textarea { min-height:160px; font-family:ui-monospace,Menlo,Consolas,monospace; line-height:1.5; }
    .sc-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
    .sc-inline { display:flex; gap:12px; }
    .sc-inline > * { flex:1; }
    .sc-input { padding:8px 11px; border:1px solid var(--sc-border-strong); border-radius:var(--sc-radius-xs);
        font-size:13px; color:var(--sc-text); background:var(--sc-surface); }
    .sc-input:focus { outline:none; border-color:var(--sc-accent); box-shadow:0 0 0 3px var(--sc-ring); }

    /* legacy review rows (kept) */
    .rv-item { display:flex; gap:10px; align-items:flex-start; padding:11px 13px; border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); margin-bottom:8px; }
    .rv-item.sel { border-color:var(--sc-accent); background:var(--sc-accent-tint); }
    .rv-item input[type=checkbox] { margin-top:3px; width:16px; height:16px; accent-color:var(--sc-ink); }
    .rv-title { font-weight:600; color:var(--sc-ink); font-size:14px; }
    .rv-summary { color:var(--sc-text); font-size:13px; margin-top:3px; }
    .rv-orig { color:var(--sc-muted); font-size:12px; margin-top:5px; padding-top:5px; border-top:1px dashed var(--sc-border); display:none; }
    .rv-meta { color:var(--sc-faint); font-size:11px; margin-top:5px; }
    .rv-meta a { color:var(--sc-accent); }
    .rv-toggle { color:var(--sc-accent); cursor:pointer; font-size:11px; }
    .rv-log-line { font-size:13px; padding:3px 0; }
    .rv-ok { color:var(--sc-published); } .rv-err { color:var(--sc-ignored); }
    .rv-banner { background:var(--sc-accent-tint); border:1px solid var(--sc-accent-soft); color:var(--sc-accent-strong); padding:9px 13px; border-radius:var(--sc-radius-xs); font-size:13px; margin-bottom:10px; }

    /* spinner + tables */
    .sc-spinner-wrap { color:var(--sc-muted); font-size:14px; padding:20px 0; }
    .sc-spinner { display:inline-block; width:16px; height:16px; border:2px solid var(--sc-border-strong); border-top-color:var(--sc-accent); border-radius:50%; animation:sc-spin .8s linear infinite; vertical-align:middle; margin-right:6px; }
    @keyframes sc-spin { to { transform:rotate(360deg); } }
    .sc-tablewrap { overflow-x:auto; border:1px solid var(--sc-border); border-radius:var(--sc-radius-sm); }
    .sc-htable { width:100%; border-collapse:collapse; font-size:13px; background:var(--sc-surface); }
    .sc-htable th, .sc-htable td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--sc-border); vertical-align:top; }
    .sc-htable thead th { color:var(--sc-muted); font-weight:600; font-size:11px; letter-spacing:.04em; text-transform:uppercase;
        background:var(--sc-surface-2); position:sticky; top:0; cursor:pointer; user-select:none; white-space:nowrap; }
    .sc-htable tbody tr:last-child td { border-bottom:none; }
    .sc-htable tbody tr:hover { background:var(--sc-surface-2); }
    .sc-htable a { color:var(--sc-ink); }

    @media (prefers-reduced-motion:reduce) { .sc-app *,.sc-app *::before,.sc-app *::after { transition:none !important; animation:none !important; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper sc-app" style="padding:24px;">
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
                    <h2 class="sc-panel-title" style="margin-bottom:16px;"><?php echo htmlspecialchars($active['name']); ?></h2>

                    <?php if ($active['type'] === 'news_collation'): ?>
                        <div class="sc-subtabs">
                            <button class="sc-subtab active" data-view="curate"><i class="fas fa-star"></i> Curate &amp; Promote</button>
                            <button class="sc-subtab" data-view="history"><i class="fas fa-clock-rotate-left"></i> History</button>
                            <?php if ($canManage): ?><button class="sc-subtab" data-view="configure"><i class="fas fa-sliders"></i> Configure</button><?php endif; ?>
                        </div>

                        <div id="scViewCurate">
                            <p class="scraper-placeholder" style="margin:10px 0 4px;">Pick a publication (drag the tabs to reorder — your order is remembered), choose a section, then work either the full day's feed or the AI-curated pick. Select stories and <strong>Publish</strong> live or <strong>Save as draft</strong>. Everything is pre-translated to English by the scraper, so loads are instant.</p>
                            <div id="scCuratePubTabs"></div>
                            <div id="scCurateSectionBar"></div>
                            <div id="scCurateModeTabs" style="display:none;">
                                <button class="sc-subtab active" data-mode="curated"><i class="fas fa-wand-magic-sparkles"></i> Curated pick</button>
                                <button class="sc-subtab" data-mode="all"><i class="fas fa-list"></i> All today</button>
                                <button class="sc-subtab" data-mode="published"><i class="fas fa-newspaper"></i> Published today</button>
                            </div>
                            <div id="scCurateActionBar" style="display:none;">
                                <label class="sc-selectall"><input type="checkbox" id="scCurAll" class="sc-ck"> Select all</label>
                                <button class="sc-btn secondary" id="scCurDraft"><i class="fas fa-file-pen"></i> Save as draft</button>
                                <button class="sc-btn" id="scCurPublish"><i class="fas fa-bolt"></i> Publish live</button>
                                <span id="scCurStatus" class="sc-status"></span>
                            </div>
                            <div id="scCurateBody"><p class="scraper-placeholder">Loading publications…</p></div>
                        </div>

                        <div id="scViewHistory" data-project-id="<?php echo (int)$active['id']; ?>" style="display:none;">
                            <p class="scraper-placeholder" style="margin:0 0 12px;">Articles the scraper has promoted. Filter by state and date range (defaults to the last 30 days). Published articles link to their canonical publication.</p>
                            <div class="sc-toolbar" style="flex-wrap:wrap;gap:10px;align-items:flex-end;">
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">Publication<br><select id="scHPub" class="sc-input"><option value="">All</option></select></label>
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">Section<br><select id="scHSec" class="sc-input"><option value="">All</option></select></label>
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">State<br>
                                    <select id="scHState" class="sc-input">
                                        <option value="">All states</option>
                                        <option value="published" selected>Published</option>
                                        <option value="draft">Draft</option>
                                        <option value="under review">Under Review</option>
                                        <option value="expired">Expired</option>
                                        <option value="deleted">Deleted</option>
                                    </select>
                                </label>
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">From<br><input type="date" id="scHFrom" class="sc-input"></label>
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">To<br><input type="date" id="scHTo" class="sc-input"></label>
                                <label style="font-size:11px;font-weight:600;color:var(--sc-muted);">Search<br><input type="text" id="scHSearch" class="sc-input" placeholder="title or source…" style="min-width:180px;"></label>
                                <button class="sc-btn" id="scHApply"><i class="fas fa-filter"></i> Apply</button>
                            </div>
                            <div id="scHResult"><p class="scraper-placeholder">Loading articles…</p></div>
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

                        <script src="js/scraper_curate.js?v=<?php echo @filemtime(__DIR__ . '/js/scraper_curate.js'); ?>"></script>
                        <script src="js/scraper_history.js?v=<?php echo @filemtime(__DIR__ . '/js/scraper_history.js'); ?>"></script>
                        <?php if ($canManage): ?><script src="js/scraper_config.js?v=<?php echo @filemtime(__DIR__ . '/js/scraper_config.js'); ?>"></script><?php endif; ?>
                        <script>
                        (function () {
                            // Only the top-level view tabs (Curate / History / Configure) — NOT the
                            // publication/mode sub-tabs, which live inside the Curate view.
                            var tabs = Array.prototype.filter.call(
                                document.querySelectorAll('.sc-subtabs > .sc-subtab[data-view]'),
                                function (t) { return t.closest('#scViewCurate') === null; });
                            tabs.forEach(function (t) {
                                t.addEventListener('click', function () {
                                    tabs.forEach(function (x) { x.classList.remove('active'); });
                                    t.classList.add('active');
                                    var v = t.getAttribute('data-view');
                                    var cur = document.getElementById('scViewCurate');
                                    if (cur) cur.style.display = (v === 'curate') ? 'block' : 'none';
                                    var hist = document.getElementById('scViewHistory');
                                    if (hist) hist.style.display = (v === 'history') ? 'block' : 'none';
                                    var cfg = document.getElementById('scViewConfigure');
                                    if (cfg) cfg.style.display = (v === 'configure') ? 'block' : 'none';
                                    if (v === 'curate' && typeof window.scCurateInit === 'function') window.scCurateInit();
                                    if (v === 'history' && typeof window.scHistoryInit === 'function') window.scHistoryInit();
                                });
                            });
                            if (typeof window.scCurateInit === 'function') window.scCurateInit();
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
