<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

if (!hasPermission('campaigns.manage') && !isAdmin()) {
    header('Location: ' . getLandingUrl());
    exit();
}
$canSettings = isAdmin() || hasPermission('system.settings');
$currentPage = 'email_campaigns';
require_once 'lib/ec_core.php';
ec_ensure_schema(ec_db()); // idempotent: adds newer contact fields / categories / archived flag
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Campaigns - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .content-area{line-height:1.5}
        .ec-head{margin:6px 0 28px}.ec-head h1{font-size:28px;font-weight:700;color:#111827;margin:0 0 10px;line-height:1.25}.ec-head p{color:#6b7280;font-size:14px;margin:0;line-height:1.6}
        .ec-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;margin:0 0 26px;padding-bottom:2px}
        .ec-tab{padding:10px 16px;font-size:14px;font-weight:600;color:#6b7280;cursor:pointer;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0}
        .ec-tab.active{color:#4f46e5;background:#fff;border-color:#e5e7eb}
        .ec-pane{display:none}.ec-pane.active{display:block}
        .ec-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px;line-height:1.5}
        .ec-card > p{margin:0 0 16px;line-height:1.6}
        .ec-btn{background:#4f46e5;color:#fff;border:none;padding:9px 15px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
        .ec-btn:hover{background:#4338ca}.ec-btn.light{background:#fff;color:#374151;border:1px solid #d1d5db}.ec-btn.danger{background:#fff;color:#dc2626;border:1px solid #fecaca}
        .ec-btn.sm{padding:5px 10px;font-size:12.5px}
        .ec-in,.ec-sel,.ec-ta{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
        .ec-ta{min-height:120px;resize:vertical}
        .ec-row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .ec-fg{margin-bottom:18px}.ec-fg label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:7px;line-height:1.4}
        .ec-fg .ec-hint{font-weight:400}
        table.ec-tbl{width:100%;border-collapse:collapse}
        table.ec-tbl th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;padding:8px 10px;border-bottom:1px solid #e5e7eb;background:#fafafa}
        table.ec-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13.5px}
        .ec-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
        .ec-badge{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:999px}
        .b-draft{background:#f3f4f6;color:#6b7280}.b-sending{background:#dbeafe;color:#1e40af}.b-completed{background:#d1fae5;color:#065f46}.b-paused{background:#fef3c7;color:#92400e}.b-cancelled{background:#fee2e2;color:#991b1b}.b-scheduled{background:#e0e7ff;color:#3730a3}
        .ec-statrow{display:flex;flex-wrap:wrap;gap:10px;margin:6px 0 14px}
        .ec-stat{min-width:84px;text-align:center;padding:12px 14px;border-radius:10px;background:#f9fafb;border:1px solid #eef0f3}
        .ec-stat b{display:block;font-size:20px;line-height:1.2;color:#111827;margin-bottom:2px}.ec-stat span{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
        .ec-prog{height:10px;background:#eef2ff;border-radius:999px;overflow:hidden;margin:12px 0 6px}.ec-prog i{display:block;height:100%;background:#4f46e5;transition:width .4s}
        .ec-dashblock{border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:16px;background:#fff}
        .ec-dashblock h4{margin:0 0 2px;font-size:16px;color:#111827}
        .ec-card h3{margin:0 0 14px;font-size:16px;color:#111827}.ec-card h4{margin:16px 0 8px;font-size:14px;color:#374151}
        .ec-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:14px;padding:14px;background:#f9fafb;border:1px solid #eef0f3;border-radius:10px}
        .ec-filters .ec-fg{margin:0}.ec-filters label{font-size:11.5px}
        .ec-chk{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;white-space:nowrap;padding-bottom:8px}
        .ec-guide{max-width:820px;line-height:1.7;color:#374151}.ec-guide h3{margin:22px 0 8px;color:#111827;font-size:17px}.ec-guide ol,.ec-guide ul{padding-left:22px}.ec-guide li{margin:6px 0}.ec-guide code{background:#f3f4f6;padding:1px 6px;border-radius:5px;font-size:13px}
        .ec-step{display:flex;gap:14px;margin:14px 0}.ec-stepn{flex:0 0 30px;height:30px;border-radius:50%;background:#4f46e5;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:14px}.ec-step div b{color:#111827}
        .ec-hint{font-size:12px;color:#9ca3af}
        /* Hover/tap glossary term with a plain-English tooltip */
        .ec-guide .ec-term{position:relative;border-bottom:1px dotted #6366f1;color:#4338ca;font-weight:600;cursor:help;outline:none}
        .ec-guide .ec-term .ec-q{font-size:9px;vertical-align:super;color:#6366f1;margin-left:1px;font-weight:700}
        .ec-guide .ec-term:focus-visible{outline:2px solid #a5b4fc;border-radius:3px}
        .ec-guide .ec-term::after{content:attr(data-tip);position:absolute;left:0;bottom:calc(100% + 9px);width:min(300px,78vw);background:#111827;color:#f9fafb;font-weight:400;font-size:12.5px;line-height:1.5;padding:9px 11px;border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.25);opacity:0;visibility:hidden;transform:translateY(4px);transition:opacity .15s,transform .15s;z-index:60;white-space:normal;pointer-events:none}
        .ec-guide .ec-term::before{content:"";position:absolute;left:16px;bottom:calc(100% + 3px);border:6px solid transparent;border-top-color:#111827;opacity:0;visibility:hidden;transition:opacity .15s;z-index:61}
        .ec-guide .ec-term:hover::after,.ec-guide .ec-term:focus::after,.ec-guide .ec-term:hover::before,.ec-guide .ec-term:focus::before{opacity:1;visibility:visible;transform:translateY(0)}
        .ec-guide .ec-lead{font-size:14.5px;color:#374151;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:14px 16px;margin:0 0 14px}
        .ec-guide .ec-callout{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin:14px 0;font-size:13.5px}
        .ec-guide .ec-callout.info{background:#eff6ff;border-color:#bfdbfe}
        .ec-guide details.ec-adv{margin:14px 0;border:1px solid #e5e7eb;border-radius:10px;padding:2px 14px;background:#fafafa}
        .ec-guide details.ec-adv>summary{cursor:pointer;font-weight:700;color:#111827;padding:11px 0;list-style:none}
        .ec-guide details.ec-adv>summary::-webkit-details-marker{display:none}
        .ec-guide details.ec-adv>summary::before{content:"\25B8";display:inline-block;margin-right:8px;color:#6366f1;transition:transform .15s}
        .ec-guide details.ec-adv[open]>summary::before{transform:rotate(90deg)}
        .ec-guide .ec-tip-legend{font-size:12.5px;color:#6b7280;margin:2px 0 18px}
        .ec-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:flex-start;justify-content:center;z-index:2000;overflow-y:auto;padding:34px 14px}
        .ec-overlay.open{display:flex}.ec-modal{background:#fff;border-radius:12px;width:100%;max-width:720px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
        .ec-modal h2{font-size:17px;margin:0}.ec-mh{padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
        .ec-mb{padding:22px 22px 8px;line-height:1.5}
        .ec-mb h4{margin:22px 0 12px;font-size:14px;color:#374151;border-top:1px solid #f1f5f9;padding-top:18px}
        .ec-mb > p{margin:0 0 16px;line-height:1.6}.ec-mf{padding:14px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px}
        .closex{background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer}
        /* keep SweetAlert toasts + confirm dialogs above every modal layer (overlays go up to 3000) */
        .swal2-container{z-index:6000 !important}
        /* second-layer modal (stacks above .ec-overlay) */
        .ec-overlay2{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;z-index:2600;overflow-y:auto;padding:28px 12px}
        .ec-overlay2.open{display:flex}
        .ec-modal2{background:#fff;border-radius:12px;width:100%;max-width:520px;box-shadow:0 24px 60px rgba(0,0,0,.3)}
        /* contact picker */
        .ctp-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;z-index:3000;overflow-y:auto;padding:22px 10px}
        .ctp-overlay.open{display:flex}
        .ctp-modal{background:#fff;border-radius:12px;width:min(1180px,97vw);box-shadow:0 24px 70px rgba(0,0,0,.32);display:flex;flex-direction:column;max-height:93vh}
        .ctp-head{padding:13px 18px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
        .ctp-body{padding:12px 16px;overflow:hidden;display:flex;flex-direction:column}
        .ctp-foot{padding:11px 18px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        .ctp-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:9px 11px;margin:0 0 12px;padding:12px 13px;background:#f9fafb;border:1px solid #eef0f3;border-radius:9px}
        .ctp-ff label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;margin-bottom:3px;white-space:nowrap}
        .ctp-fin{width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-weight:400;box-sizing:border-box}
        .ctp-scroll{overflow:auto;max-height:48vh;border:1px solid #eef0f3;border-radius:8px}
        table.ctp-tbl{border-collapse:separate;border-spacing:0;font-size:13px;white-space:nowrap;min-width:100%}
        table.ctp-tbl th{position:sticky;top:0;background:#f9fafb;text-align:left;padding:8px 9px;border-bottom:1px solid #e5e7eb;z-index:2}
        table.ctp-tbl th .ctp-hl{font-size:11.5px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;cursor:pointer;user-select:none;white-space:nowrap}
        table.ctp-tbl th .ctp-hl:hover{color:#4f46e5}
        table.ctp-tbl td{padding:6px 9px;border-bottom:1px solid #f1f5f9}
        table.ctp-tbl tbody tr{cursor:pointer}
        table.ctp-tbl tbody tr:hover td{background:#f5f7ff}
        /* ── Template HTML editor (mirrors the article editor toolbar) ── */
        .ecedit{border:1px solid #d1d5db;border-radius:8px;overflow:hidden;background:#fff}
        .ecedit-tb{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:6px 8px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
        .ecedit-tb button{width:32px;height:30px;border:1px solid transparent;background:none;border-radius:6px;color:#475569;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;justify-content:center}
        .ecedit-tb button:hover{background:#eef2f7;color:#1e293b}
        .ecedit-tb button.on{background:#e0e7ff;color:#4338ca;border-color:#c7d2fe}
        .ecedit-sep{width:1px;height:20px;background:#e2e8f0;margin:0 4px}
        .ecedit-area{min-height:220px;max-height:52vh;overflow-y:auto;padding:14px 16px;outline:none;font-size:14px;line-height:1.6;color:#111827}
        .ecedit-area:focus{background:#fefefe}
        .ecedit-area p{margin:0 0 1em}
        .ecedit-area img{max-width:100%}
        .ecedit-src{width:100%;min-height:220px;max-height:52vh;border:none;padding:14px 16px;box-sizing:border-box;font-family:ui-monospace,Menlo,Consolas,'Courier New',monospace;font-size:13px;line-height:1.7;background:#0f172a;color:#e2e8f0;outline:none;resize:vertical}
        .ecedit-sel{height:30px;padding:0 6px;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#334155;font-size:12.5px;max-width:190px;cursor:pointer}
        .ecfmt{display:flex;gap:18px;align-items:center;padding:2px 0;flex-wrap:wrap}
        .ecfmt label{font-weight:400;text-transform:none;letter-spacing:0;display:inline-flex;align-items:center;gap:6px;cursor:pointer}
        .ecedit-plain{width:100%;min-height:200px;max-height:52vh;border:none;padding:12px 14px;box-sizing:border-box;font:inherit;font-size:14px;line-height:1.6;outline:none;resize:vertical;color:#111827}
        /* link mini-modal (sits above ecModal 2000 / ecModal2 2600) */
        .eclink-ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;z-index:5200}
        .eclink-ov.open{display:block}
        .eclink{position:fixed;z-index:5300;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:min(460px,94vw);padding:0;display:none}
        .eclink.open{display:block}
        .eclink .eclink-h{padding:13px 16px;border-bottom:1px solid #e5e7eb;font-weight:600;font-size:15px;display:flex;justify-content:space-between;align-items:center}
        .eclink .eclink-b{padding:14px 16px}
        .eclink .eclink-b label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;margin:10px 0 3px}
        .eclink .eclink-b label:first-child{margin-top:0}
        .eclink .eclink-b input,.eclink .eclink-b select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box}
        .eclink .eclink-f{padding:12px 16px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px}
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-area">
            <div class="ec-head"><h1><i class="fas fa-paper-plane"></i> Email Campaigns</h1><p>Import contacts, build audiences and templates, then send tracked, throttled campaigns with suppression and never-email-twice protection.</p></div>
            <div class="ec-tabs">
                <div class="ec-tab active" data-tab="dashboard">Dashboard</div>
                <div class="ec-tab" data-tab="contacts">Contacts</div>
                <div class="ec-tab" data-tab="audiences">Audiences</div>
                <div class="ec-tab" data-tab="templates">Templates</div>
                <div class="ec-tab" data-tab="signatures">Signatures</div>
                <div class="ec-tab" data-tab="campaigns">Campaigns</div>
                <div class="ec-tab" data-tab="sending">Sending</div>
                <div class="ec-tab" data-tab="reports">Reports</div>
                <div class="ec-tab" data-tab="responses">Responses</div>
                <div class="ec-tab" data-tab="guide">Guide</div>
                <?php if ($canSettings): ?><div class="ec-tab" data-tab="settings">Settings</div><?php endif; ?>
            </div>

            <div class="ec-pane active" data-pane="dashboard"><div id="dashWrap" class="ec-card">Loading…</div></div>

            <div class="ec-pane" data-pane="contacts">
                <div class="ec-card">
                    <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap"><button class="ec-btn sm" onclick="cOpenImport()"><i class="fas fa-file-import"></i> Import contacts</button><button class="ec-btn light sm" onclick="cOpenLegacy()"><i class="fas fa-database"></i> Import existing lists</button><button class="ec-btn light sm" onclick="cOpenAdd()"><i class="fas fa-plus"></i> Add one</button><button class="ec-btn light sm" onclick="cManageCats()"><i class="fas fa-tags"></i> Manage categories</button></div>
                    <div class="ec-filters">
                        <div class="ec-fg" style="flex:2;min-width:200px"><label>Search</label><input class="ec-in" id="cSearch" placeholder="email / name / company / city"></div>
                        <div class="ec-fg" style="min-width:150px"><label>Type</label><select class="ec-sel" id="cType"><option value="">All types</option></select></div>
                        <div class="ec-fg" style="min-width:150px"><label>Industry</label><input class="ec-in" id="cIndustry" list="cIndustryList" placeholder="Any"><datalist id="cIndustryList"></datalist></div>
                        <div class="ec-fg" style="min-width:150px"><label>Category</label><select class="ec-sel" id="cCategory"><option value="">All categories</option></select></div>
                        <div class="ec-fg" style="min-width:130px"><label>Country</label><input class="ec-in" id="cCountry" placeholder="e.g. Germany"></div>
                        <label class="ec-chk"><input type="checkbox" id="cExclContacted"> Hide already-emailed</label>
                        <label class="ec-chk"><input type="checkbox" id="cExclSupp"> Hide suppressed</label>
                        <button class="ec-btn sm" onclick="cLoad()"><i class="fas fa-search"></i> Search</button>
                    </div>
                    <div class="ec-hint" id="cCount" style="margin-bottom:8px"></div>
                    <div id="cTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="audiences">
                <div class="ec-card">
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="aBuild()"><i class="fas fa-plus"></i> Create new audience</button><button class="ec-btn light sm" onclick="aCreate()"><i class="fas fa-file"></i> Empty audience</button><span class="ec-hint">Build a list by type/industry/category/country and automatically exclude anyone suppressed (unsubscribed/bounced/replied) — and optionally anyone already emailed.</span></div>
                    <div id="aTable"></div>
                </div>
                <div class="ec-card" id="aMembersCard" style="display:none">
                    <div class="ec-toolbar"><b id="aMembersTitle"></b><span style="flex:1"></span>
                        <input class="ec-in" id="aAddSearch" style="max-width:220px" placeholder="Quick add by search">
                        <button class="ec-btn light sm" onclick="aAddBySearch()">Add matches</button>
                        <button class="ec-btn sm" onclick="aAddMembers()"><i class="fas fa-user-plus"></i> Add members</button>
                    </div>
                    <div id="aMembers"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="templates">
                <div class="ec-card">
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="tNew()"><i class="fas fa-plus"></i> New template</button></div>
                    <div id="tTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="signatures">
                <div class="ec-card">
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="sgNew()"><i class="fas fa-plus"></i> New signature</button><span class="ec-hint">Reusable sign-offs. A plain-text template inserts the signature's plain version; an HTML template inserts its HTML.</span></div>
                    <div id="sgTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="campaigns">
                <div class="ec-card">
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="kNew()"><i class="fas fa-plus"></i> New campaign</button><span style="flex:1"></span><label class="ec-chk"><input type="checkbox" id="kShowArchived" onchange="kLoad()"> Show archived</label></div>
                    <div id="kTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="sending">
                <div class="ec-card">
                    <p class="ec-hint" style="margin-top:0">A sending profile is a reusable sending identity — its own subdomain/from-address, SMTP login, and reply/bounce mailboxes (which you create on the mail server yourself). Each campaign picks a profile.</p>
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="pNew()"><i class="fas fa-plus"></i> New sending profile</button></div>
                    <div id="pTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="reports">
                <div class="ec-card">
                    <div class="ec-toolbar"><select class="ec-sel" id="rCampaign" style="max-width:340px" onchange="rLoad()"><option value="">Choose a campaign…</option></select><button class="ec-btn light sm" onclick="rLoad()"><i class="fas fa-rotate"></i> Refresh</button></div>
                    <div id="rBody"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="responses">
                <div class="ec-card">
                    <div class="ec-toolbar">
                        <button class="ec-btn sm" onclick="respPoll()"><i class="fas fa-inbox"></i> Check for new responses</button>
                        <select class="ec-sel" id="respFilter" style="max-width:200px" onchange="respLoad()"><option value="">All responses</option><option value="reply">Replies only</option><option value="bounce">Bounces only</option></select>
                        <button class="ec-btn light sm" onclick="respLoad()"><i class="fas fa-rotate"></i> Refresh</button>
                        <span class="ec-hint" id="respCount" style="margin-left:auto"></span>
                    </div>
                    <p class="ec-hint">Replies and bounces are collected automatically from your sending profile's reply &amp; bounce mailboxes (needs IMAP configured on the profile and the poll cron running). Click a row to read it.</p>
                    <div id="respTable"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="guide">
                <div class="ec-card ec-guide">
                    <h3 style="margin-top:0">What this tool does</h3>
                    <p class="ec-lead">It emails a list of people for you — one message, personalised for each person — and then shows you who opened it, clicked, replied or asked to stop. It sends gradually so you stay out of spam, and it will never email the same person twice or contact anyone who has opted out.</p>
                    <p class="ec-tip-legend"><i class="fas fa-lightbulb" style="color:#f59e0b"></i> Tip: any word with a dotted underline is jargon — <b>hover over it (or tap on a phone)</b> to see a plain-English explanation.</p>

                    <h3>The idea in one minute</h3>
                    <ol>
                        <li>You have <b>contacts</b> (people, with their email and details).</li>
                        <li>You group some of them into an <span class="ec-term" tabindex="0" data-tip="The list of people a campaign will email — usually built by filtering your contacts (e.g. all brokers in Germany).">audience<span class="ec-q">?</span></span>.</li>
                        <li>You write a <span class="ec-term" tabindex="0" data-tip="An email you write once and reuse: a subject plus the message. It can contain blanks that get filled in for each person.">template<span class="ec-q">?</span></span> (the email itself).</li>
                        <li>You put them together as a <b>campaign</b> and launch. The tool does the sending and the tracking.</li>
                    </ol>

                    <h3>Send a campaign — step by step</h3>
                    <div class="ec-step"><div class="ec-stepn">1</div><div><b>Add your contacts</b> (Contacts tab). Import a spreadsheet (CSV) or paste rows. The only required column is <code>email</code>; you can also include <code>first_name, last_name, company, phone, city, country, contact_type</code>. Give people a <span class="ec-term" tabindex="0" data-tip="A label on each contact, e.g. Insurance Brokers, so you can pick that group out later.">contact type<span class="ec-q">?</span></span> so you can find them later. Duplicates are merged, and anyone on the <span class="ec-term" tabindex="0" data-tip="A do-not-email list. Anyone who unsubscribes, permanently bounces or replies is added automatically and is never emailed again.">do-not-email list<span class="ec-q">?</span></span> is skipped. The scrapers also drop contacts in here automatically.</div></div>
                    <div class="ec-step"><div class="ec-stepn">2</div><div><b>Check who you have</b> (Contacts tab). Filter by type or country, and tick <i>Hide already-emailed</i> / <i>Hide suppressed</i>. The <b>Emailed</b> column shows how many times each person has been contacted.</div></div>
                    <div class="ec-step"><div class="ec-stepn">3</div><div><b>Make an audience</b> (Audiences → <i>Build from filter</i>). Choose e.g. type = Insurance Brokers, country = Germany, and tick <i>exclude already-emailed</i>. People who unsubscribed, bounced or replied are <b>always</b> left out. A live count shows exactly how many will receive it before you save the list.</div></div>
                    <div class="ec-step"><div class="ec-stepn">4</div><div><b>Write the email</b> (Templates tab). Personalise it with <span class="ec-term" tabindex="0" data-tip="A blank in double curly braces, like {{first_name}}, that is swapped for each person's own details when the email is sent.">merge fields<span class="ec-q">?</span></span> — they work in <b>both the subject line and the body</b>. Example subject: <code>{{company}} - The Munich Eye</code> becomes <i>Muster Versicherung - The Munich Eye</i>. Fields you can use: <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{company}}</code>, <code>{{email}}</code>, <code>{{city}}</code>. Type the exact name in double braces — there is no <code>{{company name}}</code>, it is <code>{{company}}</code>; an unknown or misspelt field is left blank. The message must contain an unsubscribe link, <code>{{unsubscribe_url}}</code>. Use <i>Preview</i> and <i>Send test to me</i> before you rely on it.</div></div>
                    <div class="ec-step"><div class="ec-stepn">5</div><div><b>Set up the campaign</b> (Campaigns tab). Choose the <b>audience</b>, the <span class="ec-term" tabindex="0" data-tip="A saved 'who it is from' identity: the from-address plus the mailbox login used to send. You set these up on the Sending tab.">sending profile<span class="ec-q">?</span></span> (who it is from), and your email template. Optionally add a second template to <span class="ec-term" tabindex="0" data-tip="Send two versions and see which does better; the tool splits the audience between them and reports each one.">A/B test<span class="ec-q">?</span></span>. Control the pace with <span class="ec-term" tabindex="0" data-tip="How many emails are sent in each burst.">batch size<span class="ec-q">?</span></span>, the gap between batches, a <span class="ec-term" tabindex="0" data-tip="The most emails sent to one provider (like gmail.com) in a single run.">per-domain limit<span class="ec-q">?</span></span>, a <span class="ec-term" tabindex="0" data-tip="The most emails the tool will send in a whole day.">daily cap<span class="ec-q">?</span></span> and <span class="ec-term" tabindex="0" data-tip="Slowly increasing how much you send from a new address over the first days, to build a good reputation with mail providers.">warm-up<span class="ec-q">?</span></span> — or just leave the defaults. Pick a start time if you do not want to send right now.</div></div>
                    <div class="ec-step"><div class="ec-stepn">6</div><div><b>Review &amp; launch.</b> The tool turns the audience into a final list (leaving out anyone on the do-not-email list, or already in this campaign) and shows the exact number. Launch, and it sends steadily on your schedule instead of all at once.</div></div>
                    <div class="ec-step"><div class="ec-stepn">7</div><div><b>Watch it go</b> (Dashboard). Live tiles show sent, queued, failed, bounced, opened, clicked, replied and unsubscribed, with <b>Pause</b> / <b>Resume</b> / <b>Cancel</b>.</div></div>
                    <div class="ec-step"><div class="ec-stepn">8</div><div><b>See your results</b> (Reports tab — explained below).</div></div>

                    <h3>Add more people later (re-run)</h3>
                    <p>You can top a campaign up at any time — while it runs or after it finishes — with <b>Add new &amp; re-run</b> (on the Campaigns list, and offered on the Dashboard when a campaign completes). It <b>never re-emails anyone already contacted in that campaign</b> — only people who are not yet in it.</p>
                    <ul>
                        <li><b>Widened who you want?</b> e.g. you ran one broker type, then decide you want them all. Broaden the audience and hit <b>Add new &amp; re-run</b> — only the newly-matching people get emailed.</li>
                        <li><b>Scraper found more?</b> If the audience was made with <i>Build from filter</i>, that filter is remembered. Tick <b>pull new contacts from the audience's saved filter</b> and re-run to grab everyone added since, then email just those.</li>
                        <li><b>Adding people yourself?</b> Import them (Contacts) or add them to the audience (Audiences), then <b>Add new &amp; re-run</b>.</li>
                    </ul>

                    <h3>Reading your results (Reports tab)</h3>
                    <p>Pick a campaign to see how it performed. Each number means:</p>
                    <ul>
                        <li><b>Sent</b> — accepted by the mail server for delivery.</li>
                        <li><b>Opened</b> — the person's email app loaded a <span class="ec-term" tabindex="0" data-tip="A tiny invisible image in the email. If the app loads it, we count an open. Many apps block it (or auto-load it), so treat opens as a rough guide, not an exact figure.">tracking pixel<span class="ec-q">?</span></span>. A rough guide only. <b>Only counted when “Track opens” is ticked on the campaign</b> — otherwise the report shows “off”.</li>
                        <li><b>Clicked</b> — clicked a <span class="ec-term" tabindex="0" data-tip="With click tracking on, each link in the email is replaced with a tracked link so we can count clicks; the person still arrives at the real page.">link in the email<span class="ec-q">?</span></span>. <b>Only counted when “Track clicks” is ticked</b> — with it off, links are sent exactly as written and clicks aren’t counted.</li>
                        <li><b>Visits</b> — later browsed one of our sites, still <span class="ec-term" tabindex="0" data-tip="If someone from your email visits one of our sites later, we can still tell the visit came from that email.">traced back<span class="ec-q">?</span></span> to this email.</li>
                        <li><b>Replied</b> — answered. The tool <span class="ec-term" tabindex="0" data-tip="The tool checks the reply mailbox automatically and marks anyone who wrote back.">spots replies<span class="ec-q">?</span></span> and <b>stops</b> emailing that person.</li>
                        <li><b>Bounced</b> — delivery failed. A <span class="ec-term" tabindex="0" data-tip="A permanent failure — the address does not exist. It is added to the do-not-email list automatically.">hard bounce<span class="ec-q">?</span></span> is added to the do-not-email list automatically.</li>
                        <li><b>Unsub</b> — asked to stop; added to the do-not-email list immediately.</li>
                    </ul>
                    <p>If you A/B tested, the <b>variants</b> table compares them side by side so you can see which subject or email won. <b>Show recipients</b> gives a person-by-person breakdown you can export.</p>

                    <h3>Staying out of spam &amp; staying legal</h3>
                    <ul>
                        <li><b>Never twice:</b> every send is recorded, so no one is emailed twice in a campaign, and <i>exclude already-emailed</i> keeps them out of new ones.</li>
                        <li><b>Do-not-email list:</b> unsubscribes, hard bounces, replies and manual do-not-contact all feed one list that is checked before every single send.</li>
                        <li><b>Good habits built in:</b> a one-click unsubscribe (plus a <span class="ec-term" tabindex="0" data-tip="A standard unsubscribe button some email apps show at the very top of a message. The tool adds it for you.">List-Unsubscribe<span class="ec-q">?</span></span> button), both plain-text and HTML versions, one email per person, gentle pacing and warm-up.</li>
                        <li><b>Your responsibility:</b> make sure you are allowed to email the list. In Germany, business cold email is regulated (GDPR / UWG). The tool records a consent/source note per contact.</li>
                    </ul>

                    <details class="ec-adv">
                        <summary>Advanced: one-time server setup (admins only)</summary>

                        <p>None of this is needed day to day — it is done <b>once</b> by an administrator, on the mail server and DNS, before the first campaign can send. If your mail is already set up, you can ignore this section.</p>

                        <h3>1. Create three mailboxes</h3>
                        <p>On the sending (sub)domain, in your hosting control panel:</p>
                        <ul>
                            <li><b>Sending mailbox</b> — the account the tool logs into to send, e.g. <code>news@mail.brokers.example.com</code>.</li>
                            <li><b>Reply mailbox</b> — where people's replies land (can be the same account's inbox).</li>
                            <li><b>Bounce mailbox</b> — receives delivery failures. The tool uses a <span class="ec-term" tabindex="0" data-tip="A return address with a hidden per-person code (bounce+CODE@...), so a failed delivery can be matched to exactly who it failed for.">coded return address<span class="ec-q">?</span></span> <code>bounce+&lt;code&gt;@yourdomain</code>, so route <code>bounce+*@</code> (catch-all or plus-addressing) into this mailbox.</li>
                        </ul>

                        <h3>2. Prove you are allowed to send (DNS)</h3>
                        <p>Without these, your mail goes straight to spam:</p>
                        <ul>
                            <li><span class="ec-term" tabindex="0" data-tip="A DNS record that lists which servers are allowed to send email for your domain.">SPF<span class="ec-q">?</span></span> — a TXT record authorising the server's IP.</li>
                            <li><span class="ec-term" tabindex="0" data-tip="A DNS record plus a signature on each email, so receivers can verify it really came from you and was not tampered with.">DKIM<span class="ec-q">?</span></span> — generate a key, publish the TXT record, enable signing.</li>
                            <li><span class="ec-term" tabindex="0" data-tip="A DNS record that tells receivers what to do with mail that fails the SPF or DKIM checks. Start permissive, then tighten.">DMARC<span class="ec-q">?</span></span> — a TXT record; start with <code>p=none</code>, then tighten.</li>
                            <li><b>Use a dedicated subdomain</b> (e.g. <code>mail.brokers.example.com</code>) so outreach can't harm the main news sites' reputation. <b>Never</b> send through a consumer VPN — it breaks SPF and gets you blocklisted.</li>
                        </ul>

                        <h3>3. Turn on reply/bounce reading &amp; the schedulers</h3>
                        <ul>
                            <li>Enable the PHP <code>imap</code> extension (needed to read replies &amp; bounces). The Settings tab shows whether it is on.</li>
                            <li>Add two <span class="ec-term" tabindex="0" data-tip="A task the server runs automatically on a schedule. Here one sends queued emails, the other checks for replies and bounces.">scheduled tasks<span class="ec-q">?</span></span> (as root, in the tenuser crontab):
                                <ul>
                                    <li><code>* * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_send.php</code> — sends due batches every minute.</li>
                                    <li><code>*/5 * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_imap_poll.php</code> — pulls in replies &amp; bounces every 5 minutes.</li>
                                </ul>
                            </li>
                        </ul>

                        <h3>4. Global settings (Settings tab)</h3>
                        <ul>
                            <li><b>Global fallback identity</b> — default from-name / from-email / reply-to, used only when a campaign has no sending profile.</li>
                            <li><b>Global daily cap</b> — a hard ceiling on total emails per day across everything (0 = no cap).</li>
                            <li><b>Warm-up ramp</b> — a list of daily limits that grows a new address's volume gradually, e.g. <code>[50,100,200,400,800]</code> (day 1 max 50, day 2 max 100…).</li>
                            <li><b>Tracking base URL</b> — where the open/click/unsubscribe links live (normally <code>https://theeyenewspapers.com/management</code>).</li>
                        </ul>

                        <h3>5. Sending profiles (Sending tab)</h3>
                        <p>A sending profile is a reusable "who it is from" identity — make one per subdomain and pick it per campaign.</p>
                        <ul>
                            <li><b>From name / From email</b> — the sender people see; the email's domain is your sending subdomain.</li>
                            <li><b>Reply-to</b> — where replies should go (usually your reply mailbox).</li>
                            <li><b>Bounce address</b> — e.g. <code>bounce@mail.brokers.example.com</code>; the tool adds a per-person code so bounces can be matched.</li>
                            <li><span class="ec-term" tabindex="0" data-tip="The outgoing-mail service the tool logs into to send email.">SMTP<span class="ec-q">?</span></span> — host, port and security. Use <span class="ec-term" tabindex="0" data-tip="Two ways to encrypt the connection to the mail server. Try port 587 (STARTTLS) first; if that fails, try port 465 (SSL/TLS).">STARTTLS on 587 or SSL on 465<span class="ec-q">?</span></span>, plus the mailbox username &amp; password (stored encrypted, never shown again).</li>
                            <li><span class="ec-term" tabindex="0" data-tip="The incoming-mail service the tool reads to find replies and bounces.">IMAP<span class="ec-q">?</span></span> — host, port (usually 993), username &amp; password, and which folders hold replies and bounces.</li>
                        </ul>

                        <h3>Troubleshooting</h3>
                        <ul>
                            <li><b>Everything stuck on "queued":</b> the <code>ec_send.php</code> scheduled task isn't running, or the sending profile's SMTP login is wrong.</li>
                            <li><b>Test send fails:</b> wrong SMTP host/port/security or password; try 587/STARTTLS, then 465/SSL.</li>
                            <li><b>Landing in spam:</b> SPF/DKIM/DMARC not set for the sending domain, or sending from the wrong IP.</li>
                            <li><b>No replies/bounces recorded:</b> the PHP <code>imap</code> extension is off, IMAP details are wrong, or <code>bounce+*@</code> isn't routed to the bounce mailbox.</li>
                            <li><b>Opens look low:</b> normal — many apps block the tracking pixel. Clicks are the more reliable signal.</li>
                        </ul>
                    </details>
                </div>
            </div>

            <?php if ($canSettings): ?>
            <div class="ec-pane" data-pane="settings">
                <div class="ec-card" style="max-width:760px">
                    <p class="ec-hint">Mail servers (SMTP for sending, IMAP for replies/bounces) are configured per sending identity on the <b>Sending</b> tab. The settings here are global defaults and limits that apply across every campaign.</p>
                    <h3 style="margin-top:0">Global fallback identity <span class="ec-hint" style="font-weight:400">(used only when a campaign has no sending profile)</span></h3>
                    <div class="ec-row"><div class="ec-fg"><label>Default from name</label><input class="ec-in" id="s_default_from_name"></div><div class="ec-fg"><label>Default from email</label><input class="ec-in" id="s_default_from_email"></div></div>
                    <div class="ec-fg"><label>Default reply-to</label><input class="ec-in" id="s_default_reply_to"></div>
                    <h3>Throttle &amp; warm-up</h3>
                    <div class="ec-row"><div class="ec-fg"><label>Global daily cap <span class="ec-hint">(0 = none)</span></label><input class="ec-in" id="s_daily_cap" value="0"></div><div class="ec-fg"><label>Warm-up ramp <span class="ec-hint">(JSON daily caps)</span></label><input class="ec-in" id="s_warmup_json" placeholder="[50,100,200,400,800]"></div></div>
                    <h3>Tracking</h3>
                    <div class="ec-fg"><label>Tracking base URL <span class="ec-hint">(where the open/click/unsubscribe endpoints live)</span></label><input class="ec-in" id="s_track_base" value="https://theeyenewspapers.com/management"></div>
                    <div style="display:flex;gap:12px;align-items:center;margin-top:6px"><button class="ec-btn" onclick="sSave()"><i class="fas fa-save"></i> Save settings</button><span class="ec-hint" id="s_imap_status"></span></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- generic modal -->
    <div class="ec-overlay" id="ecModal"><div class="ec-modal"><div class="ec-mh"><h2 id="ecModalTitle"></h2><button class="closex" onclick="ecClose()">&times;</button></div><div class="ec-mb" id="ecModalBody"></div><div class="ec-mf" id="ecModalFoot"></div></div></div>
    <div class="ec-overlay2" id="ecModal2"><div class="ec-modal2"><div class="ec-mh"><h2 id="ecM2Title"></h2><button class="closex" onclick="ecClose2()">&times;</button></div><div class="ec-mb" id="ecM2Body"></div><div class="ec-mf" id="ecM2Foot"></div></div></div>
    <div class="ctp-overlay" id="ctpOverlay"></div>
    <!-- Insert-link mini modal for the template HTML editor -->
    <div class="eclink-ov" id="elOv" onclick="elClose()"></div>
    <div class="eclink" id="elModal">
        <div class="eclink-h"><span>Insert link</span><button class="closex" type="button" onclick="elClose()">&times;</button></div>
        <div class="eclink-b">
            <label>Link text</label><input type="text" id="elText" placeholder="Text to display">
            <label>URL</label><input type="text" id="elUrl" placeholder="https://example.com">
            <label>Title (optional)</label><input type="text" id="elTitle" placeholder="Tooltip">
            <label>Open in</label><select id="elTarget"><option value="_blank">New tab</option><option value="">Same tab</option></select>
        </div>
        <div class="eclink-f"><button class="ec-btn light" type="button" onclick="elClose()">Cancel</button><button class="ec-btn" type="button" onclick="elInsert()">Insert link</button></div>
    </div>

<script>
const EC = { contacts:'ajax/ec_contacts.php', audiences:'ajax/ec_audiences.php', templates:'ajax/ec_templates.php', campaigns:'ajax/ec_campaigns.php', settings:'ajax/ec_settings.php', profiles:'ajax/ec_profiles.php', categories:'ajax/ec_categories.php', responses:'ajax/ec_responses.php', signatures:'ajax/ec_signatures.php' };
const EC_ME = <?php echo json_encode($_SESSION['ten_email'] ?? ''); ?>;
const EC_MERGE = <?php echo json_encode(ec_merge_fields()); ?>; // [{token,label,col}] most-likely first
EC.signaturesCache = [];
function toast(m,i){ Swal.fire({toast:true,position:'top-end',timer:2600,showConfirmButton:false,icon:i||'success',title:m}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function post(url,data){ return $.post(url,data,null,'json'); }
function ecModal(title,body,foot){ document.getElementById('ecModalTitle').textContent=title; document.getElementById('ecModalBody').innerHTML=body; document.getElementById('ecModalFoot').innerHTML=foot; document.getElementById('ecModal').classList.add('open'); }
function ecClose(){ document.getElementById('ecModal').classList.remove('open'); }
/* second-layer modal (opens above the main one) */
function ecModal2(title,body,foot){ document.getElementById('ecM2Title').textContent=title; document.getElementById('ecM2Body').innerHTML=body; document.getElementById('ecM2Foot').innerHTML=foot; document.getElementById('ecModal2').classList.add('open'); }
function ecClose2(){ document.getElementById('ecModal2').classList.remove('open'); }

// tabs
document.querySelectorAll('.ec-tab').forEach(t=>t.addEventListener('click',function(){
    document.querySelectorAll('.ec-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.ec-pane').forEach(x=>x.classList.remove('active'));
    this.classList.add('active');
    document.querySelector('.ec-pane[data-pane="'+this.dataset.tab+'"]').classList.add('active');
    const f={dashboard:dashLoad,contacts:cLoad,audiences:aLoad,templates:tLoad,signatures:sgLoad,campaigns:kLoad,sending:pLoad,reports:rInit,responses:respLoad,settings:sLoad}[this.dataset.tab];
    if(f) f();
}));

/* ---------- Dashboard ---------- */
let dashTimer=null;
function dashLoad(){ post(EC.campaigns,{action:'list'}).done(function(r){
    if(!r.success){document.getElementById('dashWrap').textContent=r.message;return;}
    const active=r.rows.filter(c=>['sending','scheduled','paused'].includes(c.status));
    let h='<h3 style="margin-top:0">Active campaigns</h3>';
    if(!active.length) h+='<p class="ec-hint">No active campaigns. Create one under the Campaigns tab.</p>';
    active.forEach(function(c){ h+='<div id="dash'+c.id+'" class="ec-dashblock"><h4>'+esc(c.name)+' &nbsp;<span class="ec-badge b-'+c.status+'">'+c.status+'</span></h4><div class="ec-hint" style="margin-bottom:4px">Audience: '+esc(c.audience_name||'—')+'</div><div class="dashprog"></div></div>'; });
    const completed=r.rows.filter(c=>c.status==='completed');
    if(completed.length){ h+='<h3 style="margin-top:24px">Recently completed — add newly‑found contacts?</h3>';
        completed.slice(0,8).forEach(function(c){ h+='<div class="ec-dashblock"><h4>'+esc(c.name)+' &nbsp;<span class="ec-badge b-completed">completed</span></h4><div class="ec-hint" style="margin:4px 0 10px">'+c.sent+' sent. The scraper may have added new '+esc(c.audience_name||'')+' contacts since — you can send to just the new ones without re‑emailing anyone already contacted in this campaign.</div><button class="ec-btn sm" onclick="kRerun('+c.id+')"><i class="fas fa-user-plus"></i> Add new &amp; re-run</button></div>'; }); }
    h+='<h3 style="margin-top:22px">All campaigns</h3><table class="ec-tbl"><thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Total</th></tr></thead><tbody>';
    r.rows.forEach(c=>h+='<tr><td>'+esc(c.name)+'</td><td><span class="ec-badge b-'+c.status+'">'+c.status+'</span></td><td>'+c.sent+'</td><td>'+c.total+'</td></tr>');
    h+='</tbody></table>';
    document.getElementById('dashWrap').innerHTML=h;
    active.forEach(c=>dashProg(c.id));
    if(dashTimer) clearInterval(dashTimer);
    if(active.length) dashTimer=setInterval(()=>active.forEach(c=>dashProg(c.id)),5000);
}); }
function dashProg(id){ post(EC.campaigns,{action:'progress',id:id}).done(function(r){
    const el=document.querySelector('#dash'+id+' .dashprog'); if(!el||!r.success)return;
    const s=r.by_status||{},ev=r.events||{},sent=s.sent||0,total=r.total||0,pct=total?Math.round(sent*100/total):0;
    el.innerHTML='<div class="ec-statrow">'+
        stat(sent,'sent')+stat((s.queued||0),'queued')+stat((s.failed||0),'failed')+stat((s.bounced||0),'bounced')+
        stat((ev.open||0),'opened')+stat((ev.click||0),'clicked')+stat((ev.reply||0),'replied')+stat((ev.unsubscribe||0),'unsub')+
        '</div><div class="ec-prog"><i style="width:'+pct+'%"></i></div><div class="ec-hint">'+sent+' / '+total+' sent ('+pct+'%)</div>'+
        '<div style="margin-top:14px;display:flex;gap:8px"><button class="ec-btn light sm" onclick="kCtl('+id+',\'pause\')">Pause</button><button class="ec-btn light sm" onclick="kCtl('+id+',\'resume\')">Resume</button><button class="ec-btn danger sm" onclick="kCtl('+id+',\'cancel\')">Cancel</button></div>';
}); }
function stat(n,l){ return '<span class="ec-stat"><b>'+n+'</b><span>'+l+'</span></span>'; }

/* ---------- Contacts ---------- */
let _types=[],_cats=[],_industries=[];
function cTypes(cb){ post(EC.contacts,{action:'types'}).done(function(r){ _types=(r.rows||[]).map(x=>x.contact_type); const sel=document.getElementById('cType'); if(sel){ const cur=sel.value; sel.innerHTML='<option value="">All types</option>'+_types.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join(''); } if(cb)cb(); }); }
function cCats(cb){ post(EC.categories,{action:'list'}).done(function(r){ _cats=(r.rows||[]).map(x=>x.name); const sel=document.getElementById('cCategory'); if(sel){ const cur=sel.value; sel.innerHTML='<option value="">All categories</option>'+_cats.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join(''); } if(cb)cb(); }); }
function cIndustries(cb){ post(EC.contacts,{action:'industries'}).done(function(r){ _industries=(r.rows||[]).map(x=>x.industry); const dl=document.getElementById('cIndustryList'); if(dl){ dl.innerHTML=_industries.map(t=>'<option value="'+esc(t)+'">').join(''); } if(cb)cb(); }); }
function cLoad(){ cTypes(); cCats(); cIndustries(); post(EC.contacts,{action:'list',q:document.getElementById('cSearch').value,type:document.getElementById('cType').value,industry:document.getElementById('cIndustry').value,category:document.getElementById('cCategory').value,country:document.getElementById('cCountry').value,exclude_contacted:document.getElementById('cExclContacted').checked?1:0,exclude_suppressed:document.getElementById('cExclSupp').checked?1:0}).done(function(r){
    if(!r.success){toast(r.message,'error');return;}
    document.getElementById('cCount').textContent=r.total+' matching contact'+(r.total===1?'':'s');
    let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Type</th><th>Category</th><th>Country</th><th>Emailed</th><th>Status</th><th></th></tr></thead><tbody>';
    r.rows.forEach(c=>h+='<tr><td>'+esc(c.email)+'</td><td>'+esc((c.first_name||'')+' '+(c.last_name||''))+'</td><td>'+esc(c.company||'')+'</td><td>'+esc(c.contact_type||'')+'</td><td>'+esc(c.category||'')+'</td><td>'+esc(c.country||'')+'</td><td>'+(c.times_sent>0?('<b>'+c.times_sent+'×</b>'):'—')+'</td><td>'+(c.suppressed==1?'<span class="ec-badge b-cancelled">suppressed</span>':esc(c.status))+'</td><td style="white-space:nowrap"><button class="ec-btn light sm" onclick="cEdit('+c.id+')">Edit</button>'+(c.suppressed==1?' <button class="ec-btn light sm" onclick="cUnsuppress(\''+esc(c.email)+'\')">Unsuppress</button>':' <button class="ec-btn danger sm" onclick="cSuppress(\''+esc(c.email)+'\')">Suppress</button>')+'</td></tr>');
    h+='</tbody></table>'; document.getElementById('cTable').innerHTML=h;
}); }
/* Shared add/edit form. All fields optional except email. */
function cForm(ct){ ct=ct||{};
    var typeOpts=_types.map(t=>'<option value="'+esc(t)+'">').join('');
    var indOpts=_industries.map(t=>'<option value="'+esc(t)+'">').join('');
    var catOpts='<option value="">— none —</option>'+_cats.map(t=>'<option'+(t===(ct.category||'')?' selected':'')+'>'+esc(t)+'</option>').join('');
    // include the contact's own category even if it is not in the managed list
    if(ct.category && _cats.indexOf(ct.category)<0) catOpts='<option value="">— none —</option><option selected>'+esc(ct.category)+'</option>'+_cats.map(t=>'<option>'+esc(t)+'</option>').join('');
    return '<input type="hidden" id="cfId" value="'+(ct.id||'')+'">'+
    '<div class="ec-fg"><label>Email *</label><input class="ec-in" id="cfEmail" value="'+esc(ct.email||'')+'"></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>First name</label><input class="ec-in" id="cfFn" value="'+esc(ct.first_name||'')+'"></div><div class="ec-fg"><label>Last name</label><input class="ec-in" id="cfLn" value="'+esc(ct.last_name||'')+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Job title</label><input class="ec-in" id="cfTitle" value="'+esc(ct.job_title||'')+'"></div><div class="ec-fg"><label>Business name</label><input class="ec-in" id="cfCompany" value="'+esc(ct.company||'')+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Industry <span class="ec-hint">(area of business)</span></label><input class="ec-in" id="cfIndustry" list="cfIndustryList" value="'+esc(ct.industry||'')+'"><datalist id="cfIndustryList">'+indOpts+'</datalist></div><div class="ec-fg"><label>Category <span class="ec-hint">(<a href="#" onclick="cManageCats();return false;">manage</a>)</span></label><select class="ec-sel" id="cfCategory">'+catOpts+'</select></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Contact type</label><input class="ec-in" id="cfType" list="cfTypeList" value="'+esc(ct.contact_type||'')+'"><datalist id="cfTypeList">'+typeOpts+'</datalist></div><div class="ec-fg"><label>Website</label><input class="ec-in" id="cfWebsite" value="'+esc(ct.website||'')+'"></div></div>'+
    '<div class="ec-fg"><label>Address</label><input class="ec-in" id="cfAddress" value="'+esc(ct.address||'')+'"></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>City</label><input class="ec-in" id="cfCity" value="'+esc(ct.city||'')+'"></div><div class="ec-fg"><label>Postcode</label><input class="ec-in" id="cfPostcode" value="'+esc(ct.postcode||'')+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Region / State</label><input class="ec-in" id="cfRegion" value="'+esc(ct.region||'')+'"></div><div class="ec-fg"><label>Country</label><input class="ec-in" id="cfCountry" value="'+esc(ct.country||'')+'"></div></div>'+
    '<div class="ec-fg"><label>Phone</label><input class="ec-in" id="cfPhone" value="'+esc(ct.phone||'')+'"></div>'; }
function cGather(){ return { email:document.getElementById('cfEmail').value, first_name:document.getElementById('cfFn').value, last_name:document.getElementById('cfLn').value, job_title:document.getElementById('cfTitle').value, company:document.getElementById('cfCompany').value, industry:document.getElementById('cfIndustry').value, category:document.getElementById('cfCategory').value, contact_type:document.getElementById('cfType').value, website:document.getElementById('cfWebsite').value, address:document.getElementById('cfAddress').value, city:document.getElementById('cfCity').value, postcode:document.getElementById('cfPostcode').value, region:document.getElementById('cfRegion').value, country:document.getElementById('cfCountry').value, phone:document.getElementById('cfPhone').value }; }
function cOpenImport(){ ecModal('Import contacts',
    '<p class="ec-hint">Paste CSV with a header row (<code>email</code> required; any of these optional columns are picked up: <code>first_name, last_name, company, job_title, contact_type, industry, category, website, address, city, postcode, region, country, phone</code>) or one email per line. Unknown columns are ignored.</p>'+
    '<textarea class="ec-ta" id="impData" style="min-height:170px" placeholder="email,first_name,company,city\njohn@x.de,John,ACME Versicherung,Berlin"></textarea>'+
    '<div class="ec-row" style="margin-top:10px"><div class="ec-fg"><label>Contact type <span class="ec-hint">(e.g. Insurance Brokers, Restaurants)</span></label><input class="ec-in" id="impType" list="impTypeList" placeholder="Insurance Brokers"><datalist id="impTypeList">'+_types.map(t=>'<option value="'+esc(t)+'">').join('')+'</datalist></div><div class="ec-fg"><label>Source tag</label><input class="ec-in" id="impSource" value="csv"></div></div>'+
    '<div class="ec-fg"><label>Consent basis (optional, for your records)</label><input class="ec-in" id="impConsent" placeholder="e.g. legitimate interest / opted-in list"></div>',
    '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="cDoImport()">Import</button>'); }
function cDoImport(){ post(EC.contacts,{action:'import',data:document.getElementById('impData').value,contact_type:document.getElementById('impType').value,source:document.getElementById('impSource').value,consent_basis:document.getElementById('impConsent').value}).done(function(r){ if(r.success){ecClose();toast('New '+r.new+', updated '+r.updated+', skipped '+r.skipped_suppressed+', invalid '+r.invalid);cLoad();}else toast(r.message,'error'); }); }
function cOpenLegacy(){
    ecModal('Import from existing lists','<p class="ec-hint"><i class="fas fa-spinner fa-spin"></i> Loading counts…</p>','<button class="ec-btn light" onclick="ecClose()">Close</button>');
    post(EC.contacts,{action:'legacy_preview'}).done(function(r){
        if(!r.success){ document.getElementById('ecModalBody').innerHTML='<p class="ec-hint">'+esc(r.message||'Failed to load')+'</p>'; return; }
        var h='<p class="ec-hint">Your existing TEN lists. Only <b>contactable</b> records are imported — anyone who unsubscribed or is marked do-not-contact is skipped <b>and</b> added to the suppression list, so old opt-outs are always honoured. These were sourced from public/online resources (recorded as each contact\'s consent note).</p>';
        h+='<div id="legProgress" class="ec-hint" style="margin:8px 0;min-height:18px"></div>';
        h+='<table class="ec-tbl"><thead><tr><th>List</th><th>Contactable</th><th></th></tr></thead><tbody>';
        (r.pr_contacts.roles||[]).forEach(function(x){
            h+='<tr><td>PR · '+esc(x.label)+'</td><td>'+x.contactable+'</td><td><button class="ec-btn sm" onclick="cRunLegacy(\'pr_contacts\',\''+esc(x.role)+'\',\''+esc(x.label)+'\',this)">Import</button></td></tr>';
        });
        h+='<tr><td>Venues (event venue contacts)</td><td>'+r.venue_contacts.contactable+'</td><td><button class="ec-btn sm" onclick="cRunLegacy(\'venue_contacts\',\'\',\'Venues\',this)">Import</button></td></tr>';
        h+='<tr><td>Clinics</td><td>'+r.clinics.contactable+'</td><td><button class="ec-btn sm" onclick="cRunLegacy(\'clinics\',\'\',\'Clinics\',this)">Import</button></td></tr>';
        h+='</tbody></table>';
        h+='<p class="ec-hint" style="margin-top:8px">Opt-outs that will be added to the suppression list: PR '+r.pr_contacts.opt_out+', Venues '+r.venue_contacts.opt_out+'. Large lists import in batches — leave this open until it finishes.</p>';
        document.getElementById('ecModalBody').innerHTML=h;
    }).fail(function(){ document.getElementById('ecModalBody').innerHTML='<p class="ec-hint">Failed to load preview.</p>'; });
}
var _legTotals;
function cRunLegacy(source, role, label, btn){
    if(btn) btn.disabled=true;
    _legTotals={n:0,u:0,skip:0,inv:0,sup:0};
    var prog=document.getElementById('legProgress');
    function step(offset){
        if(prog) prog.innerHTML='<i class="fas fa-spinner fa-spin"></i> Importing '+esc(label)+'… '+(_legTotals.n+_legTotals.u)+' processed';
        post(EC.contacts,{action:'legacy_import',source:source,role:role,offset:offset,batch:2000}).done(function(r){
            if(!r.success){ toast(r.message||'Import failed','error'); if(btn) btn.disabled=false; return; }
            _legTotals.n+=r.new; _legTotals.u+=r.updated; _legTotals.skip+=r.skipped_suppressed; _legTotals.inv+=r.invalid; _legTotals.sup+=r.suppressed_added;
            if(!r.done && r.next_offset!==null){ step(r.next_offset); }
            else {
                if(prog) prog.innerHTML='<b>'+esc(label)+' imported.</b> New '+_legTotals.n+', updated '+_legTotals.u+', skipped (already suppressed) '+_legTotals.skip+', invalid '+_legTotals.inv+', opt-outs suppressed '+_legTotals.sup+'.';
                toast(esc(label)+': +'+_legTotals.n+' new');
                if(btn){ btn.disabled=false; btn.innerHTML='Done ✓'; }
                cTypes();
            }
        }).fail(function(){ toast('Import failed','error'); if(btn) btn.disabled=false; });
    }
    step(0);
}
function cReady(cb){ let n=3; const done=()=>{ if(--n===0) cb(); }; cTypes(done); cCats(done); cIndustries(done); }
function cOpenAdd(){ cReady(function(){ ecModal('Add contact', cForm({}), '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="cDoAdd()">Add</button>'); }); }
function cDoAdd(){ post(EC.contacts,Object.assign({action:'add'},cGather())).done(function(r){ if(r.success){ecClose();toast('Added');cLoad();}else toast(r.message,'error'); }); }
function cEdit(id){ cReady(function(){ post(EC.contacts,{action:'get',id:id}).done(function(r){ if(!r.success){toast(r.message||'Not found','error');return;} ecModal('Edit contact', cForm(r.contact), '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="cDoUpdate()">Save</button>'); }); }); }
function cDoUpdate(){ post(EC.contacts,Object.assign({action:'update',id:document.getElementById('cfId').value},cGather())).done(function(r){ if(r.success){ecClose();toast('Saved');cLoad();}else toast(r.message,'error'); }); }
function cManageCats(){
    ecModal('Manage categories','<p class="ec-hint">Categories you create here appear in the dropdown when adding or editing a contact. Renaming one updates every contact that uses it; deleting one only removes it from the list.</p><div id="catList" class="ec-hint">Loading…</div><div class="ec-row" style="margin-top:12px;align-items:end"><div class="ec-fg" style="flex:1"><label>New category</label><input class="ec-in" id="catNew" placeholder="e.g. VIP prospects"></div><button class="ec-btn" onclick="cCatCreate()">Add</button></div>','<button class="ec-btn light" onclick="ecClose()">Close</button>');
    cCatRender();
}
function cCatRender(){ post(EC.categories,{action:'list'}).done(function(r){ _cats=(r.rows||[]).map(x=>x.name); var el=document.getElementById('catList'); if(!el)return; if(!r.rows||!r.rows.length){ el.innerHTML='<p class="ec-hint">No categories yet.</p>'; return; } var h='<table class="ec-tbl"><thead><tr><th>Category</th><th>Contacts</th><th></th></tr></thead><tbody>'; r.rows.forEach(x=>h+='<tr><td>'+esc(x.name)+'</td><td>'+x.contacts+'</td><td style="white-space:nowrap"><button class="ec-btn light sm" onclick="cCatRename('+x.id+',\''+esc(x.name).replace(/\'/g,"")+'\')">Rename</button> <button class="ec-btn danger sm" onclick="cCatDelete('+x.id+')">Delete</button></td></tr>'); h+='</tbody></table>'; el.innerHTML=h; }); }
function cCatCreate(){ var v=document.getElementById('catNew').value.trim(); if(!v)return; post(EC.categories,{action:'create',name:v}).done(function(r){ if(r.success){document.getElementById('catNew').value='';cCatRender();cCats();}else toast(r.message,'error'); }); }
function cCatRename(id,cur){ Swal.fire({title:'Rename category',input:'text',inputValue:cur,showCancelButton:true,confirmButtonText:'Rename'}).then(function(x){ if(x.isConfirmed && x.value){ post(EC.categories,{action:'rename',id:id,name:x.value}).done(function(r){ if(r.success){cCatRender();cCats();}else toast(r.message,'error'); }); } }); }
function cCatDelete(id){ Swal.fire({title:'Delete category?',text:'Contacts keep their current value; it is just removed from the list.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Delete'}).then(function(x){ if(x.isConfirmed) post(EC.categories,{action:'delete',id:id}).done(function(r){ if(r.success){cCatRender();cCats();}else toast(r.message,'error'); }); }); }
function cSuppress(email){ Swal.fire({title:'Suppress '+email+'?',text:'They will never be emailed again.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Suppress'}).then(x=>{ if(x.isConfirmed) post(EC.contacts,{action:'suppress',email:email,reason:'do_not_contact'}).done(()=>{toast('Suppressed');cLoad();}); }); }
function cUnsuppress(email){ Swal.fire({title:'Unsuppress '+email+'?',text:'They can be emailed again. Only do this if they did not opt out.',icon:'warning',showCancelButton:true,confirmButtonText:'Unsuppress'}).then(x=>{ if(x.isConfirmed) post(EC.contacts,{action:'unsuppress',email:email}).done(()=>{toast('Unsuppressed');cLoad();}); }); }

/* ======= Reusable contact picker — full filterable / sortable / paged table =======
 * ctpOpen({multi, onPick, title, confirmLabel}). Single-select fires onPick(row) on
 * click; multi-select fires onPick([rows]) from the Add button. Renders in its own
 * top-layer overlay so it stacks above any open modal. Filters map to the list
 * action's f_<col> params; sorting maps to sort/dir. */
const CTP_COLS=[
  {k:'email',l:'Email'},{k:'first_name',l:'First name'},{k:'last_name',l:'Last name'},
  {k:'company',l:'Company'},{k:'job_title',l:'Job title'},{k:'contact_type',l:'Type'},
  {k:'industry',l:'Industry'},{k:'category',l:'Category'},{k:'city',l:'City'},
  {k:'region',l:'Region'},{k:'postcode',l:'Postcode'},{k:'country',l:'Country'},
  {k:'phone',l:'Phone'},{k:'website',l:'Website'},{k:'address',l:'Address'},{k:'status',l:'Status'}
];
let _ctp=null,_ctpT;
function ctpOpen(opts){
  _ctp={multi:!!opts.multi,onPick:opts.onPick,title:opts.title||(opts.multi?'Select contacts':'Choose a contact'),
        confirmLabel:opts.confirmLabel||'Add selected',filters:{},sort:'',dir:'asc',offset:0,limit:100,selected:{},byId:{},q:'',total:0};
  var head=(_ctp.multi?'<th style="width:32px"><input type="checkbox" title="Select all on this page" onclick="ctpAll(this)"></th>':'<th style="width:74px"></th>')+
    CTP_COLS.map(function(col){ return '<th><span class="ctp-hl" onclick="ctpSort(\''+col.k+'\')">'+col.l+' <span id="ctpar_'+col.k+'"></span></span></th>'; }).join('');
  var filterGrid=CTP_COLS.map(function(col){ return '<div class="ctp-ff"><label>'+col.l+'</label><input class="ctp-fin" data-col="'+col.k+'" oninput="ctpFilterInput()" placeholder="contains…"></div>'; }).join('');
  var ov=document.getElementById('ctpOverlay');
  ov.innerHTML='<div class="ctp-modal">'+
    '<div class="ctp-head"><b>'+esc(_ctp.title)+'</b><button class="closex" onclick="ctpClose()">&times;</button></div>'+
    '<div class="ctp-body">'+
      '<div style="display:flex;gap:12px;align-items:center;margin-bottom:10px;flex-wrap:wrap">'+
        '<input class="ec-in" id="ctpQ" style="max-width:300px" placeholder="Search all fields…" oninput="ctpQInput()">'+
        '<label class="ec-chk" style="padding:0"><input type="checkbox" id="ctpHideSupp" checked onchange="ctpReload()"> Hide suppressed</label>'+
        '<label class="ec-chk" style="padding:0"><input type="checkbox" id="ctpHideEmailed" onchange="ctpReload()"> Hide already-emailed</label>'+
        '<button class="ec-btn light sm" onclick="ctpClearFilters()">Clear filters</button>'+
        '<span style="flex:1"></span><span class="ec-hint" id="ctpCount"></span>'+
      '</div>'+
      '<div class="ctp-filters">'+filterGrid+'</div>'+
      '<div class="ctp-scroll"><table class="ctp-tbl"><thead><tr>'+head+'</tr></thead><tbody id="ctpBody"></tbody></table></div>'+
    '</div>'+
    '<div class="ctp-foot">'+
      '<div><button class="ec-btn light sm" onclick="ctpPage(-1)">‹ Prev</button> <button class="ec-btn light sm" onclick="ctpPage(1)">Next ›</button> <span class="ec-hint" id="ctpPageInfo"></span></div>'+
      '<div style="display:flex;align-items:center;gap:10px">'+(_ctp.multi?'<span class="ec-hint" id="ctpSel">0 selected</span><button class="ec-btn" onclick="ctpConfirm()">'+esc(_ctp.confirmLabel)+'</button>':'<span class="ec-hint">Click a row to choose</span>')+' <button class="ec-btn light" onclick="ctpClose()">Close</button></div>'+
    '</div></div>';
  ov.classList.add('open');
  ctpFetch();
}
function ctpClose(){ var ov=document.getElementById('ctpOverlay'); if(ov){ ov.classList.remove('open'); ov.innerHTML=''; } _ctp=null; }
function ctpQInput(){ _ctp.q=document.getElementById('ctpQ').value; clearTimeout(_ctpT); _ctpT=setTimeout(function(){ _ctp.offset=0; ctpFetch(); },300); }
function ctpFilterInput(){ var f={}; document.querySelectorAll('#ctpOverlay .ctp-fin').forEach(function(i){ if(i.value.trim()!=='') f[i.dataset.col]=i.value.trim(); }); _ctp.filters=f; clearTimeout(_ctpT); _ctpT=setTimeout(function(){ _ctp.offset=0; ctpFetch(); },350); }
function ctpClearFilters(){ document.querySelectorAll('#ctpOverlay .ctp-fin').forEach(function(i){ i.value=''; }); var q=document.getElementById('ctpQ'); if(q) q.value=''; _ctp.filters={}; _ctp.q=''; _ctp.offset=0; ctpFetch(); }
function ctpReload(){ _ctp.offset=0; ctpFetch(); }
function ctpSort(k){ if(_ctp.sort===k){ _ctp.dir=(_ctp.dir==='asc'?'desc':'asc'); } else { _ctp.sort=k; _ctp.dir='asc'; } _ctp.offset=0; ctpFetch(); }
function ctpPage(d){ var no=_ctp.offset+d*_ctp.limit; if(no<0||no>=_ctp.total)return; _ctp.offset=no; ctpFetch(); }
function ctpAll(cb){ document.querySelectorAll('#ctpBody .ctp-chk').forEach(function(x){ x.checked=cb.checked; ctpMark(x.value,cb.checked); }); ctpSelCount(); }
function ctpMark(id,on){ if(on){ _ctp.selected[id]=_ctp.byId[id]||{id:id}; } else { delete _ctp.selected[id]; } }
function ctpChk(el){ ctpMark(el.value,el.checked); ctpSelCount(); }
function ctpSelCount(){ var el=document.getElementById('ctpSel'); if(el) el.textContent=Object.keys(_ctp.selected).length+' selected'; }
function ctpRowToggle(id){ var cb=document.querySelector('#ctpBody .ctp-chk[value="'+id+'"]'); if(cb){ cb.checked=!cb.checked; ctpChk(cb); } }
function ctpChoose(id){ var row=(_ctp.byId&&_ctp.byId[id])||{id:id}; var cb=_ctp.onPick; ctpClose(); if(cb) cb(row); }
function ctpConfirm(){ var ids=Object.keys(_ctp.selected); if(!ids.length){ toast('Tick some contacts first','error'); return; } var rows=ids.map(function(id){ return _ctp.selected[id]; }); var cb=_ctp.onPick; ctpClose(); if(cb) cb(rows); }
function ctpFetch(){
  var p={action:'list',q:_ctp.q,limit:_ctp.limit,offset:_ctp.offset};
  if(_ctp.sort){ p.sort=_ctp.sort; p.dir=_ctp.dir; }
  Object.keys(_ctp.filters).forEach(function(k){ p['f_'+k]=_ctp.filters[k]; });
  if(document.getElementById('ctpHideSupp') && document.getElementById('ctpHideSupp').checked) p.exclude_suppressed=1;
  if(document.getElementById('ctpHideEmailed') && document.getElementById('ctpHideEmailed').checked) p.exclude_contacted=1;
  post(EC.contacts,p).done(function(r){
    if(!r.success){ toast(r.message,'error'); return; }
    _ctp.total=r.total;
    var b=document.getElementById('ctpBody'); if(!b)return;
    if(!r.rows.length){ b.innerHTML='<tr><td colspan="'+(CTP_COLS.length+1)+'" style="padding:18px;color:#6b7280;text-align:center">No contacts match these filters.</td></tr>'; }
    else { b.innerHTML=r.rows.map(function(c){
        _ctp.byId[c.id]=c;
        var cells=CTP_COLS.map(function(col){ var v=c[col.k]||''; if(col.k==='status'&&c.suppressed==1)v='suppressed'; return '<td>'+esc(v)+'</td>'; }).join('');
        var first=_ctp.multi
          ? '<td onclick="event.stopPropagation()"><input type="checkbox" class="ctp-chk" value="'+c.id+'" '+(_ctp.selected[c.id]?'checked':'')+' onchange="ctpChk(this)"></td>'
          : '<td><button class="ec-btn light sm" onclick="event.stopPropagation();ctpChoose('+c.id+')">Choose</button></td>';
        return '<tr '+(_ctp.multi?'onclick="ctpRowToggle('+c.id+')"':'onclick="ctpChoose('+c.id+')"')+'>'+first+cells+'</tr>';
      }).join(''); }
    CTP_COLS.forEach(function(col){ var a=document.getElementById('ctpar_'+col.k); if(a) a.textContent=(_ctp.sort===col.k?(_ctp.dir==='asc'?'▲':'▼'):''); });
    var cc=document.getElementById('ctpCount'); if(cc) cc.textContent=(r.total).toLocaleString()+' contact'+(r.total===1?'':'s');
    var from=_ctp.total?_ctp.offset+1:0, to=Math.min(_ctp.offset+_ctp.limit,_ctp.total);
    var pi=document.getElementById('ctpPageInfo'); if(pi) pi.textContent=from+'–'+to+' of '+(_ctp.total).toLocaleString();
    ctpSelCount();
  });
}

/* ---------- Audiences ---------- */
let aCurrent=null;
function aLoad(){ post(EC.audiences,{action:'list'}).done(function(r){
    if(!r.success){toast(r.message,'error');return;}
    let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Members</th><th>Sendable</th><th></th></tr></thead><tbody>';
    r.rows.forEach(a=>h+='<tr><td>'+esc(a.name)+'</td><td>'+a.members+'</td><td>'+a.sendable+'</td><td><button class="ec-btn light sm" onclick="aOpen('+a.id+',\''+esc(a.name).replace(/\'/g,"")+'\')">Members</button> <button class="ec-btn danger sm" onclick="aDel('+a.id+')">Delete</button></td></tr>');
    h+='</tbody></table>'; document.getElementById('aTable').innerHTML=h;
}); }
function aCreate(){ ecModal('New audience','<div class="ec-fg"><label>Name *</label><input class="ec-in" id="auName"></div><div class="ec-fg"><label>Description</label><input class="ec-in" id="auDesc"></div>','<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="aDoCreate()">Create</button>'); }
function aBuild(){ cReady(function(){
    ecModal('Create new audience',
    '<div class="ec-fg"><label>Audience name *</label><input class="ec-in" id="abName" placeholder="Brokers — Germany (Sept)"></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Contact type</label><select class="ec-sel" id="abType"><option value="">Any type</option>'+_types.map(t=>'<option>'+esc(t)+'</option>').join('')+'</select></div><div class="ec-fg"><label>Country</label><input class="ec-in" id="abCountry" placeholder="Germany"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Industry</label><input class="ec-in" id="abIndustry" list="cIndustryList" placeholder="Any industry"></div><div class="ec-fg"><label>Category</label><select class="ec-sel" id="abCategory"><option value="">Any category</option>'+_cats.map(t=>'<option>'+esc(t)+'</option>').join('')+'</select></div></div>'+
    '<div class="ec-fg"><label>Extra search (optional)</label><input class="ec-in" id="abQ" placeholder="company / city / keyword"></div>'+
    '<label class="ec-chk"><input type="checkbox" id="abExclContacted" checked> Exclude anyone already emailed in a previous campaign</label>'+
    '<div class="ec-hint" style="margin-top:6px">Suppressed contacts (unsubscribed, bounced, replied, do-not-contact) are <b>always</b> excluded.</div>'+
    '<div style="margin-top:12px;padding:12px;background:#eef2ff;border-radius:8px"><b id="abCount">—</b> contacts match. <button class="ec-btn light sm" style="margin-left:8px" onclick="aBuildCount()">Recount</button></div>',
    '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="aBuildDo()">Create &amp; populate</button>');
    aBuildCount();
}); }
function aBuildFilter(){ return {q:document.getElementById('abQ').value,type:document.getElementById('abType').value,country:document.getElementById('abCountry').value,industry:document.getElementById('abIndustry').value,category:document.getElementById('abCategory').value,exclude_contacted:document.getElementById('abExclContacted').checked?1:0}; }
function aBuildCount(){ post(EC.audiences,Object.assign({action:'count_filter'},aBuildFilter())).done(function(r){ if(r.success) document.getElementById('abCount').textContent=r.count; }); }
function aBuildDo(){ const name=document.getElementById('abName').value.trim(); if(!name){toast('Name required','error');return;}
    post(EC.audiences,Object.assign({action:'create',name:name,save_filter:1},aBuildFilter())).done(function(r){ if(!r.success){toast(r.message,'error');return;} post(EC.audiences,Object.assign({action:'add_members',audience_id:r.id},aBuildFilter())).done(function(x){ ecClose(); toast('Audience created with '+(x.added||0)+' contacts (filter saved for refresh)'); aLoad(); }); }); }
function aDoCreate(){ post(EC.audiences,{action:'create',name:document.getElementById('auName').value,description:document.getElementById('auDesc').value}).done(function(r){ if(r.success){ecClose();toast('Created');aLoad();}else toast(r.message,'error'); }); }
function aDel(id){ Swal.fire({title:'Delete audience?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.audiences,{action:'delete',id:id}).done(()=>{toast('Deleted');aLoad();}); }); }
function aOpen(id,name){ aCurrent=id; document.getElementById('aMembersCard').style.display='block'; document.getElementById('aMembersTitle').textContent='Members of "'+name+'"'; aMembers(); }
function aMembers(){ post(EC.audiences,{action:'members',audience_id:aCurrent}).done(function(r){ let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Status</th><th></th></tr></thead><tbody>'; (r.rows||[]).forEach(m=>h+='<tr><td>'+esc(m.email)+'</td><td>'+esc((m.first_name||'')+' '+(m.last_name||''))+'</td><td>'+esc(m.company||'')+'</td><td>'+esc(m.status)+'</td><td><button class="ec-btn light sm" onclick="aRemove('+m.id+')">Remove</button></td></tr>'); h+='</tbody></table>'; document.getElementById('aMembers').innerHTML=h; }); }
function aAddBySearch(){ post(EC.audiences,{action:'add_members',audience_id:aCurrent,q:document.getElementById('aAddSearch').value}).done(function(r){ if(r.success){toast('Added '+r.added);aMembers();aLoad();}else toast(r.message,'error'); }); }
function aAddMembers(){ if(!aCurrent){toast('Open an audience first','error');return;} cReady(function(){
    var body='<div class="ec-tabs" style="margin-bottom:16px">'+
        '<div class="ec-tab active" data-am="select" onclick="amTab(\'select\')">Select from contacts</div>'+
        '<div class="ec-tab" data-am="import" onclick="amTab(\'import\')">Import</div>'+
        '<div class="ec-tab" data-am="manual" onclick="amTab(\'manual\')">Add new contact</div></div>'+
        '<div class="am-pane" data-am="select">'+
            '<p class="ec-hint">Open the full contact list — search, filter by any field and sort — then tick everyone you want and add them to this audience in one go.</p>'+
            '<button class="ec-btn" onclick="amPick()"><i class="fas fa-table"></i> Open contact picker</button></div>'+
        '<div class="am-pane" data-am="import" style="display:none">'+
            '<p class="ec-hint">Paste CSV (<code>email</code> required, same optional columns as Import contacts). New contacts are created and added to this audience.</p>'+
            '<textarea class="ec-ta" id="amImp" style="min-height:150px" placeholder="email,first_name,company,city"></textarea>'+
            '<div class="ec-fg" style="margin-top:8px"><label>Contact type (optional)</label><input class="ec-in" id="amImpType" placeholder="e.g. Insurance Brokers"></div>'+
            '<button class="ec-btn" onclick="amImport()">Import &amp; add</button></div>'+
        '<div class="am-pane" data-am="manual" style="display:none">'+cForm({})+
            '<button class="ec-btn" style="margin-top:6px" onclick="amManual()">Add contact to audience</button></div>';
    ecModal('Add members', body, '<button class="ec-btn light" onclick="ecClose()">Done</button>');
}); }
function amTab(which){ document.querySelectorAll('.ec-tab[data-am]').forEach(t=>t.classList.toggle('active',t.dataset.am===which)); document.querySelectorAll('.am-pane').forEach(p=>p.style.display=(p.dataset.am===which?'':'none')); }
function amPick(){ ctpOpen({multi:true,title:'Add contacts to this audience',confirmLabel:'Add selected to audience',onPick:function(rows){ var ids=rows.map(function(r){return r.id;}); post(EC.audiences,{action:'add_members',audience_id:aCurrent,contact_ids:ids.join(',')}).done(function(r){ if(r.success){ toast('Added '+r.added+' to audience'); aMembers(); aLoad(); } else toast(r.message,'error'); }); }}); }
function amImport(){ post(EC.contacts,{action:'import',data:document.getElementById('amImp').value,contact_type:document.getElementById('amImpType').value,source:'audience_add',audience_id:aCurrent}).done(function(r){ if(r.success){toast('New '+r.new+', '+(r.added_to_audience||0)+' added to audience');aMembers();aLoad();}else toast(r.message,'error'); }); }
function amManual(){ post(EC.contacts,Object.assign({action:'add',audience_id:aCurrent},cGather())).done(function(r){ if(r.success){toast('Contact added to audience');ecClose();aMembers();aLoad();}else toast(r.message,'error'); }); }
function aRemove(cid){ post(EC.audiences,{action:'remove_member',audience_id:aCurrent,contact_id:cid}).done(()=>{aMembers();aLoad();}); }

/* ---------- Templates ---------- */
function tLoad(){ post(EC.templates,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Subject</th><th>Format</th><th>From</th><th></th></tr></thead><tbody>'; r.rows.forEach(t=>h+='<tr><td>'+esc(t.name)+'</td><td>'+esc(t.subject)+'</td><td>'+(t.is_plain==1?'Plain text':'HTML')+'</td><td>'+esc(t.from_email||'')+'</td><td><button class="ec-btn light sm" onclick="tEdit('+t.id+')">Edit</button> <button class="ec-btn danger sm" onclick="tDel('+t.id+')">Delete</button></td></tr>'); h+='</tbody></table>'; document.getElementById('tTable').innerHTML=h; }); }
/* ============================================================================
 * Shared rich editor — used by BOTH the Template editor and the Signature editor
 * (only one modal is open at a time, so they reuse the same element ids). It gives
 * an HTML/plain format chooser, an article-style toolbar with a Source toggle, an
 * "Insert field" merge-tag dropdown, an "Insert signature" dropdown (templates only)
 * and an insert-link mini-modal.
 * ==========================================================================*/
var tSrcMode=false;   // editor showing raw HTML source?
var elRange=null;     // saved editor selection for link insertion
var ecReqUnsub=true;  // does the current editor require {{unsubscribe_url}}? (templates yes, signatures no)
var _tDefaultHtml='<p>Hello {{first_name}},</p><p></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>';
// Insert-controls strip (merge fields + optional signature picker) targeting 'html' or 'text'.
// Merge fields are recipient data, so they only belong in templates — not signatures (which
// are the sender's own sign-off). Returns '' when neither control is wanted.
function ecInsertStrip(target,includeMerge,includeSig){
    if(!includeMerge && !includeSig) return '';
    var mergeOpts='<option value="">Insert field ▾</option>'+EC_MERGE.map(function(f){return '<option value="'+f.token+'">'+esc(f.label)+'</option>';}).join('');
    return '<div class="ecedit-tb">'+
        (includeMerge?'<select class="ecedit-sel ecMergeSel" title="Insert a contact field" onchange="ecInsField(this,\''+target+'\')">'+mergeOpts+'</select>':'')+
        (includeSig?'<select class="ecedit-sel ecSigSel" title="Insert a signature" onchange="ecInsSig(this,\''+target+'\')"><option value="">Insert signature ▾</option></select>':'')+
    '</div>';
}
// The reusable editor markup. opts:{includeMerge,includeSig,requireUnsub}
function ecEditorBlock(opts){ opts=opts||{}; var incM=!!opts.includeMerge, incS=!!opts.includeSig;
    return '<div class="ec-fg"><label>Email format</label><div class="ecfmt">'+
            '<label><input type="radio" name="ecFmt" value="html" onchange="ecFmtToggle()"> HTML <span class="ec-hint">(formatting, links, images)</span></label>'+
            '<label><input type="radio" name="ecFmt" value="plain" onchange="ecFmtToggle()"> Plain text <span class="ec-hint">(no formatting — most inbox-friendly)</span></label>'+
        '</div></div>'+
        '<div class="ec-fg" id="ecHtmlBlock"><label id="ecHtmlLbl">HTML body *</label>'+
            '<div class="ecedit">'+
                ecInsertStrip('html',incM,incS)+
                '<div class="ecedit-tb">'+
                    '<button type="button" data-cmd="bold" title="Bold"><i class="fas fa-bold"></i></button>'+
                    '<button type="button" data-cmd="italic" title="Italic"><i class="fas fa-italic"></i></button>'+
                    '<button type="button" data-cmd="underline" title="Underline"><i class="fas fa-underline"></i></button>'+
                    '<span class="ecedit-sep"></span>'+
                    '<button type="button" data-cmd="formatBlock" data-val="&lt;h2&gt;" title="Heading"><i class="fas fa-heading"></i></button>'+
                    '<button type="button" data-cmd="insertUnorderedList" title="Bulleted list"><i class="fas fa-list-ul"></i></button>'+
                    '<button type="button" data-cmd="insertOrderedList" title="Numbered list"><i class="fas fa-list-ol"></i></button>'+
                    '<span class="ecedit-sep"></span>'+
                    '<button type="button" id="ecLinkBtn" title="Insert link"><i class="fas fa-link"></i></button>'+
                    '<button type="button" data-cmd="unlink" title="Remove link"><i class="fas fa-unlink"></i></button>'+
                    '<span class="ecedit-sep"></span>'+
                    '<button type="button" id="ecSrcBtn" title="View HTML source"><i class="fas fa-code"></i></button>'+
                '</div>'+
                '<div class="ecedit-area" id="ecBody" contenteditable="true"></div>'+
                '<textarea class="ecedit-src" id="ecSrc" spellcheck="false"></textarea>'+
            '</div></div>'+
        '<div class="ec-fg" id="ecTextBlock"><label id="ecTextLbl"></label>'+
            '<div class="ecedit">'+ecInsertStrip('text',incM,incS)+'<textarea class="ecedit-plain" id="ecText"></textarea></div>'+
        '</div>'; }
// Wire the editor after ecModal has injected the markup. data has {id,is_plain,html_body,text_body,_defaultHtml}.
function ecEditorInit(data){ data=data||{};
    tSrcMode=false;
    var body=document.getElementById('ecBody'); if(body){ body.innerHTML=(data.html_body&&data.html_body.trim())?data.html_body:(data.id?'':(data._defaultHtml||'')); body.style.display=''; }
    var src=document.getElementById('ecSrc'); if(src){ src.style.display='none'; src.value=''; }
    var txt=document.getElementById('ecText'); if(txt) txt.value=data.text_body||'';
    var sb=document.getElementById('ecSrcBtn'); if(sb) sb.classList.remove('on');
    var plain=(data.is_plain==1); var r=document.querySelector('input[name="ecFmt"][value="'+(plain?'plain':'html')+'"]'); if(r) r.checked=true;
    document.querySelectorAll('.ecedit-tb button[data-cmd]').forEach(function(b){ b.addEventListener('mousedown',function(e){ e.preventDefault(); }); b.addEventListener('click',function(){ ecCmd(b.getAttribute('data-cmd'), b.getAttribute('data-val')); }); });
    var lk=document.getElementById('ecLinkBtn'); if(lk) lk.addEventListener('click',elOpen);
    if(sb) sb.addEventListener('click',ecSrcToggle);
    ecFmtToggle();
    // Load signatures for the "insert signature" dropdown(s), if present.
    if(document.querySelector('.ecSigSel')){ ecFillSigSelects(); post(EC.signatures,{action:'list'}).done(function(rr){ EC.signaturesCache=rr.rows||[]; ecFillSigSelects(); }); }
}
function ecFillSigSelects(){ var opts='<option value="">Insert signature ▾</option>'+(EC.signaturesCache||[]).map(function(s){return '<option value="'+s.id+'">'+esc(s.name)+(s.is_plain==1?' (plain)':'')+'</option>';}).join(''); document.querySelectorAll('.ecSigSel').forEach(function(sel){ sel.innerHTML=opts; }); }
function ecFmtToggle(){ var plain=(document.querySelector('input[name="ecFmt"]:checked')||{}).value==='plain';
    var hb=document.getElementById('ecHtmlBlock'), lbl=document.getElementById('ecTextLbl');
    if(hb) hb.style.display=plain?'none':'';
    var noun=ecReqUnsub?'message':'signature';
    if(lbl) lbl.innerHTML = plain
        ? 'Plain-text '+noun+' * <span class="ec-hint">'+(ecReqUnsub?'merge fields work here — must include {{unsubscribe_url}}':'the plain-text version')+'</span>'
        : 'Plain text <span class="ec-hint">(optional alternative — auto-generated if left blank; used when this is placed in a plain-text email)</span>';
}
function ecCmd(cmd,val){ var b=document.getElementById('ecBody'); if(!b) return; b.focus(); try{ document.execCommand(cmd,false,val||null); }catch(e){} }
function ecSrcToggle(){ var b=document.getElementById('ecBody'), s=document.getElementById('ecSrc'), btn=document.getElementById('ecSrcBtn'); if(!b||!s) return;
    if(tSrcMode){ b.innerHTML=s.value; s.style.display='none'; b.style.display=''; if(btn) btn.classList.remove('on'); }
    else { s.value=b.innerHTML; b.style.display='none'; s.style.display='block'; if(btn) btn.classList.add('on'); }
    tSrcMode=!tSrcMode;
}
function ecHtmlValue(){ if(tSrcMode){ var s=document.getElementById('ecSrc'); return s?s.value:''; } var b=document.getElementById('ecBody'); return b?b.innerHTML:''; }
function ecTextValue(){ var t=document.getElementById('ecText'); return t?t.value:''; }
function ecIsPlain(){ return ((document.querySelector('input[name="ecFmt"]:checked')||{}).value==='plain')?1:0; }
/* ---- insert merge field / signature ---- */
function ecStripTags(h){ var d=document.createElement('div'); d.innerHTML=h||''; return d.textContent||d.innerText||''; }
function ecNl2br(s){ return esc(s).replace(/\n/g,'<br>'); }
function ecInsertAtCaret(ta,text){ ta.focus(); var s=ta.selectionStart||0,e=ta.selectionEnd||0,v=ta.value; ta.value=v.slice(0,s)+text+v.slice(e); var p=s+text.length; ta.selectionStart=ta.selectionEnd=p; }
function ecInsertInto(target,payload,isHtml){
    if(target==='html' && !tSrcMode){ var b=document.getElementById('ecBody'); if(!b) return; b.focus(); try{ document.execCommand(isHtml?'insertHTML':'insertText',false,payload); }catch(e){} }
    else { var ta=(target==='text')?document.getElementById('ecText'):document.getElementById('ecSrc'); if(ta) ecInsertAtCaret(ta,payload); }
}
function ecInsField(sel,target){ var tok=sel.value; sel.selectedIndex=0; if(tok) ecInsertInto(target,tok,false); }
function ecInsSig(sel,target){ var id=sel.value; sel.selectedIndex=0; if(!id) return;
    var sig=(EC.signaturesCache||[]).find(function(x){return String(x.id)===String(id);}); if(!sig){ toast('Signature not loaded yet — try again','error'); return; }
    if(target==='text'){ // plain destination: always use the plain-text version
        var txt=(sig.text_body&&sig.text_body.trim())?sig.text_body:ecStripTags(sig.html_body||'');
        ecInsertInto('text','\n'+txt+'\n',false);
    } else { // html destination
        var html=(sig.is_plain==1)?('<p>'+ecNl2br(sig.text_body||'')+'</p>'):(sig.html_body||('<p>'+ecNl2br(sig.text_body||'')+'</p>'));
        ecInsertInto('html','<br>'+html,true);
    }
}
/* ---- insert-link mini modal (targets the HTML editor) ---- */
function elOpen(){ var b=document.getElementById('ecBody'); if(!b) return; b.focus();
    var sel=window.getSelection(); elRange=(sel&&sel.rangeCount&&b.contains(sel.getRangeAt(0).commonAncestorContainer))?sel.getRangeAt(0):null;
    var a=elAnchorInSelection();
    document.getElementById('elText').value=a?a.textContent:(elRange?elRange.toString():'');
    document.getElementById('elUrl').value=a?a.getAttribute('href')||'':'';
    document.getElementById('elTitle').value=a?a.getAttribute('title')||'':'';
    document.getElementById('elTarget').value=a?(a.getAttribute('target')||''):'_blank';
    document.getElementById('elOv').classList.add('open'); document.getElementById('elModal').classList.add('open');
    setTimeout(function(){ document.getElementById('elUrl').focus(); },60);
}
function elClose(){ document.getElementById('elOv').classList.remove('open'); document.getElementById('elModal').classList.remove('open'); }
function elAnchorInSelection(){ var n=elRange?elRange.commonAncestorContainer:null; while(n&&n.nodeName){ if(n.nodeName==='A') return n; n=n.parentNode; if(n&&n.id==='ecBody') break; } return null; }
function elInsert(){ var url=(document.getElementById('elUrl').value||'').trim(), text=(document.getElementById('elText').value||'').trim(), title=(document.getElementById('elTitle').value||'').trim(), target=document.getElementById('elTarget').value;
    if(!url){ toast('Enter a URL','error'); return; }
    if(!text) text=url;
    var b=document.getElementById('ecBody'); b.focus();
    var sel=window.getSelection(); sel.removeAllRanges(); if(elRange) sel.addRange(elRange);
    var existing=elAnchorInSelection();
    if(existing){ existing.setAttribute('href',url); existing.textContent=text; if(title) existing.setAttribute('title',title); else existing.removeAttribute('title'); if(target){ existing.setAttribute('target',target); existing.setAttribute('rel','noopener noreferrer'); } else { existing.removeAttribute('target'); existing.removeAttribute('rel'); } }
    else {
        var a=document.createElement('a'); a.setAttribute('href',url); if(title) a.setAttribute('title',title); if(target){ a.setAttribute('target',target); a.setAttribute('rel','noopener noreferrer'); } a.textContent=text;
        if(elRange){ elRange.deleteContents(); elRange.insertNode(a); } else { b.appendChild(a); }
    }
    elClose();
}
/* ---- Template form (uses the shared editor) ---- */
function tForm(t){ t=t||{}; return '<input type="hidden" id="tId" value="'+(t.id||'')+'">'+
    '<div class="ec-row"><div class="ec-fg"><label>Name *</label><input class="ec-in" id="tName" value="'+esc(t.name||'')+'"></div><div class="ec-fg"><label>Subject * <span class="ec-hint">merge fields work here too, e.g. {{company}} - The Munich Eye</span></label><input class="ec-in" id="tSubject" value="'+esc(t.subject||'')+'"></div></div>'+
    /* From/Reply-to are set by the sending profile (Sending tab), so they're hidden here to avoid confusion. */
    '<input type="hidden" id="tFromName" value=""><input type="hidden" id="tFromEmail" value=""><input type="hidden" id="tReplyTo" value="">'+
    ecEditorBlock({includeMerge:true,includeSig:true,requireUnsub:true}); }
function tFoot(){ return '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn light" onclick="tPreview()">Preview</button><button class="ec-btn light" onclick="tTestModal()">Send test</button><button class="ec-btn" onclick="tSave()">Save</button>'; }
function tNew(){ ecReqUnsub=true; ecModal('New template',tForm(),tFoot()); ecEditorInit({_defaultHtml:_tDefaultHtml}); }
function tEdit(id){ post(EC.templates,{action:'get',id:id}).done(function(r){ if(r.success){ ecReqUnsub=true; ecModal('Edit template',tForm(r.template),tFoot()); ecEditorInit(Object.assign({_defaultHtml:_tDefaultHtml},r.template)); } }); }
function tPayload(){ return {id:document.getElementById('tId').value,name:document.getElementById('tName').value,subject:document.getElementById('tSubject').value,from_name:document.getElementById('tFromName').value,from_email:document.getElementById('tFromEmail').value,reply_to:document.getElementById('tReplyTo').value,is_plain:ecIsPlain(),html_body:ecHtmlValue(),text_body:ecTextValue()}; }
/* ---- Signatures ---- */
function sgLoad(){ post(EC.signatures,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} EC.signaturesCache=r.rows||[]; let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Format</th><th></th></tr></thead><tbody>'; (r.rows||[]).forEach(function(s){ h+='<tr><td>'+esc(s.name)+'</td><td>'+(s.is_plain==1?'Plain text':'HTML')+'</td><td><button class="ec-btn light sm" onclick="sgEdit('+s.id+')">Edit</button> <button class="ec-btn danger sm" onclick="sgDel('+s.id+')">Delete</button></td></tr>'; }); h+='</tbody></table>'; if(!(r.rows||[]).length) h='<p class="ec-hint">No signatures yet. Create one, then insert it into a template with the toolbar\'s "Insert signature" dropdown.</p>'; document.getElementById('sgTable').innerHTML=h; }); }
function sgForm(s){ s=s||{}; return '<input type="hidden" id="sgId" value="'+(s.id||'')+'">'+
    '<div class="ec-fg"><label>Name *</label><input class="ec-in" id="sgName" value="'+esc(s.name||'')+'"></div>'+
    ecEditorBlock({includeMerge:false,includeSig:false,requireUnsub:false}); }
function sgFoot(){ return '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn light" onclick="sgPreview()">Preview</button><button class="ec-btn" onclick="sgSave()">Save</button>'; }
function sgNew(){ ecReqUnsub=false; ecModal('New signature',sgForm(),sgFoot()); ecEditorInit({}); }
function sgEdit(id){ post(EC.signatures,{action:'get',id:id}).done(function(r){ if(r.success){ ecReqUnsub=false; ecModal('Edit signature',sgForm(r.signature),sgFoot()); ecEditorInit(r.signature); } }); }
function sgPayload(){ return {id:document.getElementById('sgId').value,name:document.getElementById('sgName').value,is_plain:ecIsPlain(),html_body:ecHtmlValue(),text_body:ecTextValue()}; }
function sgSave(){ post(EC.signatures,Object.assign({action:'save'},sgPayload())).done(function(r){ if(r.success){ecClose();toast('Saved');sgLoad();}else toast(r.message,'error'); }); }
function sgDel(id){ Swal.fire({title:'Delete signature?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.signatures,{action:'delete',id:id}).done(()=>{toast('Deleted');sgLoad();}); }); }
function sgPreview(){ var w=window.open('','_blank'); if(ecIsPlain()) w.document.write('<pre style="white-space:pre-wrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px">'+esc(ecTextValue())+'</pre>'); else w.document.write(ecHtmlValue()); }
function tSave(){ post(EC.templates,Object.assign({action:'save'},tPayload())).done(function(r){ if(r.success){ecClose();toast('Saved');tLoad();}else toast(r.message,'error'); }); }
function tDel(id){ Swal.fire({title:'Delete template?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.templates,{action:'delete',id:id}).done(()=>{toast('Deleted');tLoad();}); }); }
function tPreview(){ post(EC.templates,Object.assign({action:'preview'},tPayload())).done(function(r){ if(r.success){ const w=window.open('','_blank'); w.document.write('<h3 style="font-family:sans-serif">Subject: '+esc(r.subject)+'</h3><hr>'+r.html); } }); }
/* Send-test opens a second-layer modal above the still-open template editor, so
 * unsaved edits (read via tPayload) survive and are what gets sent. "Choose from
 * contacts" opens the full picker (top layer) and fills the address. */
function tTestModal(){ post(EC.profiles,{action:'list'}).done(function(r){
    var profs=r.rows||[];
    var opts=profs.map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+(p.from_email?(' — '+esc(p.from_email)):'')+'</option>'; }).join('');
    var profField = profs.length
        ? '<div class="ec-fg"><label>Send using profile *</label><select class="ec-sel" id="ttProfile">'+opts+'</select></div>'
        : '<div class="ec-fg"><label>Send using profile</label><div class="ec-hint" style="color:#b45309">No sending profiles yet — create one in the <b>Sending</b> tab first (that\'s where the SMTP host/login live). The test can\'t send without one.</div></div>';
    var body='<p class="ec-hint" style="margin-top:0">Send a one-off test of this template. Merge fields use sample values, and it goes out through the chosen sending profile.</p>'+
        profField+
        '<div class="ec-fg"><label>Send to *</label><input class="ec-in" id="ttTo" value="'+esc(EC_ME||'')+'"></div>'+
        '<button class="ec-btn light sm" onclick="ttPick()"><i class="fas fa-address-book"></i> Choose from contacts</button>';
    ecModal2('Send test email', body, '<button class="ec-btn light" onclick="ecClose2()">Cancel</button><button class="ec-btn" onclick="tTestSend()">Send test</button>');
}); }
function ttPick(){ ctpOpen({multi:false,title:'Choose a contact to send the test to',onPick:function(c){ var f=document.getElementById('ttTo'); if(f) f.value=c.email; }}); }
function tTestSend(){ var to=(document.getElementById('ttTo').value||'').trim(); if(!to){ toast('Enter an email address','error'); return; } var pf=document.getElementById('ttProfile'); post(EC.templates,Object.assign({action:'test_send',to:to,profile_id:(pf?pf.value:0)},tPayload())).done(function(r){ toast(r.message, r.success?'success':'error'); if(r.success) ecClose2(); }); }

/* ---------- Campaigns ---------- */
function kLoad(){ var showArch=document.getElementById('kShowArchived')&&document.getElementById('kShowArchived').checked?1:0; post(EC.campaigns,{action:'list',include_archived:showArch}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Audience</th><th>Status</th><th>Sent/Total</th><th></th></tr></thead><tbody>'; r.rows.forEach(function(c){ var arch=(c.archived==1); h+='<tr'+(arch?' style="opacity:.55"':'')+'><td>'+esc(c.name)+(arch?' <span class="ec-badge b-cancelled">archived</span>':'')+'</td><td>'+esc(c.audience_name||'')+'</td><td><span class="ec-badge b-'+c.status+'">'+c.status+'</span></td><td>'+c.sent+'/'+c.total+'</td><td style="white-space:nowrap"><button class="ec-btn light sm" onclick="kEdit('+c.id+')">Edit</button> <button class="ec-btn light sm" onclick="kReview('+c.id+')">Review &amp; launch</button> <button class="ec-btn light sm" onclick="kRerun('+c.id+')">Add new &amp; re-run</button> '+(c.total>0?'<button class="ec-btn light sm" onclick="kResend('+c.id+')">Send again</button> ':'')+(arch?'<button class="ec-btn light sm" onclick="kArchive('+c.id+',0)">Unarchive</button>':'<button class="ec-btn light sm" onclick="kArchive('+c.id+',1)">Archive</button>')+' <button class="ec-btn danger sm" onclick="kDelete('+c.id+')">Delete</button></td></tr>'; }); h+='</tbody></table>'; document.getElementById('kTable').innerHTML=h; }); }
/* Send again: shows everyone already in the campaign and re-queues all or a selection
 * to be emailed AGAIN (overrides the never-email-twice default). */
function kResend(id){ post(EC.campaigns,{action:'recipients',id:id,limit:1000}).done(function(r){ if(!r.success){toast(r.message,'error');return;}
    var rows=r.rows||[], total=r.total||rows.length;
    var body='<p class="ec-hint" style="margin-top:0">Everyone already contacted in this campaign. Re-queuing sends the email to them <b>again</b>. Anyone who has since unsubscribed or bounced is still skipped automatically at send time.</p>'+
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><button class="ec-btn" onclick="kResendDo('+id+',true)">Re-send to everyone ('+total+')</button><span class="ec-hint" id="rsSel"></span></div>'+
        '<div style="max-height:46vh;overflow:auto;border:1px solid #eef0f3;border-radius:8px"><table class="ec-tbl"><thead><tr><th style="width:30px"><input type="checkbox" onclick="rsAll(this)"></th><th>Email</th><th>Last status</th><th>Last sent</th></tr></thead><tbody>'+
        rows.map(function(x){ return '<tr><td><input type="checkbox" class="rsChk" value="'+x.contact_id+'" onchange="rsCount()"></td><td>'+esc(x.email)+'</td><td>'+esc(x.status)+(x.error?(' <span style="color:#b91c1c">('+esc(x.error)+')</span>'):'')+'</td><td>'+esc((x.sent_at||'').replace('T',' '))+'</td></tr>'; }).join('')+
        '</tbody></table></div>'+
        (total>rows.length?('<p class="ec-hint">Showing '+rows.length+' of '+total+'. "Re-send to everyone" still covers all of them.</p>'):'');
    ecModal('Send again', body, '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="kResendDo('+id+',false)">Re-send to selected</button>');
}); }
function rsAll(cb){ document.querySelectorAll('.rsChk').forEach(function(x){ x.checked=cb.checked; }); rsCount(); }
function rsCount(){ var n=document.querySelectorAll('.rsChk:checked').length; var el=document.getElementById('rsSel'); if(el) el.textContent=n?(n+' selected'):''; }
function kResendDo(id,all){ var ids=''; if(!all){ var arr=[]; document.querySelectorAll('.rsChk:checked').forEach(function(x){ arr.push(x.value); }); if(!arr.length){ toast('Tick some recipients, or use "Re-send to everyone"','error'); return; } ids=arr.join(','); }
    var msg = all ? 'Re-send this campaign to EVERYONE already contacted?' : 'Re-send to the selected recipients?';
    Swal.fire({title:msg,text:'They will be emailed again.',icon:'warning',showCancelButton:true,confirmButtonText:'Re-send'}).then(function(x){ if(!x.isConfirmed) return; post(EC.campaigns,{action:'resend',id:id,contact_ids:ids}).done(function(r){ if(r.success){ ecClose(); toast(r.requeued+' re-queued — sending again'); kLoad(); dashLoad(); } else toast(r.message,'error'); }); }); }
function kArchive(id,on){ post(EC.campaigns,{action:(on?'archive':'unarchive'),id:id}).done(function(r){ if(r.success){toast(on?'Archived':'Unarchived');kLoad();}else toast(r.message,'error'); }); }
function kDelete(id){ Swal.fire({title:'Delete campaign?',text:'This permanently removes the campaign and all its recipients, tracking and reports. This cannot be undone.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Delete permanently'}).then(function(x){ if(x.isConfirmed) post(EC.campaigns,{action:'delete',id:id}).done(function(r){ if(r.success){toast('Deleted');kLoad();}else toast(r.message,'error'); }); }); }
let _tpls=[],_auds=[],_profs=[];
function kNew(){ Promise.all([post(EC.audiences,{action:'list'}),post(EC.templates,{action:'list'}),post(EC.profiles,{action:'list'})]).then(function(a){ _auds=a[0].rows||[]; _tpls=a[1].rows||[]; _profs=a[2].rows||[]; kForm({}); }); }
function kEdit(id){ Promise.all([post(EC.audiences,{action:'list'}),post(EC.templates,{action:'list'}),post(EC.profiles,{action:'list'}),post(EC.campaigns,{action:'get',id:id})]).then(function(a){ _auds=a[0].rows||[]; _tpls=a[1].rows||[]; _profs=a[2].rows||[]; kForm(a[3].campaign, a[3].variants); }); }
function optList(arr,sel,val,txt){ return arr.map(o=>'<option value="'+o[val]+'"'+(String(o[val])===String(sel)?' selected':'')+'>'+esc(o[txt])+'</option>').join(''); }
function kForm(c,vars){ c=c||{}; vars=vars&&vars.length?vars:[{template_id:'',weight:1}];
    const tplOpts=id=>'<option value="">— template —</option>'+optList(_tpls,id,'id','name');
    let vh=vars.map((v,i)=>'<div class="ec-row" style="align-items:end"><div class="ec-fg"><label>Variant '+String.fromCharCode(65+i)+' template</label><select class="ec-sel kVarTpl">'+tplOpts(v.template_id)+'</select></div><div class="ec-fg"><label>Weight</label><input class="ec-in kVarW" value="'+(v.weight||1)+'"></div></div>').join('');
    ecModal(c.id?'Edit campaign':'New campaign',
        '<input type="hidden" id="kId" value="'+(c.id||'')+'">'+
        '<div class="ec-row"><div class="ec-fg"><label>Name *</label><input class="ec-in" id="kName" value="'+esc(c.name||'')+'"></div><div class="ec-fg"><label>Audience *</label><select class="ec-sel" id="kAud"><option value="">— audience —</option>'+optList(_auds,c.audience_id,'id','name')+'</select></div></div>'+
        '<div class="ec-fg"><label>Sending profile * <span class="ec-hint">(the From address + SMTP to send from; manage under the Sending tab)</span></label><select class="ec-sel" id="kProfile"><option value="">— use global default —</option>'+optList(_profs,c.sending_profile_id,'id','name')+'</select></div>'+
        /* From/Reply-to come from the sending profile, so they're hidden here to avoid confusion. */
        '<input type="hidden" id="kFromName" value=""><input type="hidden" id="kFromEmail" value=""><input type="hidden" id="kReplyTo" value="">'+
        '<div class="ec-row"><div class="ec-fg"><label>Batch size</label><input class="ec-in" id="kBatch" value="'+(c.batch_size||50)+'"></div><div class="ec-fg"><label>Interval between batches (min)</label><input class="ec-in" id="kInterval" value="'+(c.batch_interval_min||10)+'"></div></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>Per-domain limit / run <span class="ec-hint">(0=none)</span></label><input class="ec-in" id="kPerDomain" value="'+(c.per_domain_limit||0)+'"></div><div class="ec-fg"><label>Daily cap <span class="ec-hint">(0=none)</span></label><input class="ec-in" id="kDaily" value="'+(c.daily_cap||0)+'"></div></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>Schedule start <span class="ec-hint">(blank=now)</span></label><input class="ec-in" id="kSched" type="datetime-local"></div><div class="ec-fg"><label><input type="checkbox" id="kWarm" '+(c.warmup_enabled?'checked':'')+'> Warm-up ramp &nbsp; <input type="checkbox" id="kAB" '+(c.ab_enabled?'checked':'')+'> A/B</label></div></div>'+
        '<div class="ec-fg" style="border:1px solid var(--ten-border,#e2e2e2);border-radius:8px;padding:10px 12px;margin:4px 0">'+
          '<div style="font-weight:600;margin-bottom:2px">Tracking</div>'+
          '<div class="ec-hint" id="kFmtHint" style="margin:0 0 8px"></div>'+
          '<label style="display:block;margin:6px 0"><input type="checkbox" id="kTrackOpens" '+(c.track_opens?'checked':'')+'> <b>Track opens</b> <span class="ec-hint">— adds a tiny invisible image so you can see who opened. Off = better privacy &amp; deliverability.</span></label>'+
          '<label style="display:block;margin:6px 0"><input type="checkbox" id="kTrackClicks" '+(c.track_clicks?'checked':'')+'> <b>Track clicks on the links in this email</b> <span class="ec-hint">— rewrites every link so clicks are counted; recipients pass through our server first. Off = links stay exactly as you wrote them.</span></label>'+
        '</div>'+
        '<div id="kVars">'+vh+'</div><button class="ec-btn light sm" onclick="kAddVar()">+ Add A/B variant</button>',
        '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="kSave()">Save</button>');
    // Set dropdown values explicitly — reliable preselection on edit (inline `selected` can miss).
    var ka=document.getElementById('kAud'); if(ka && c.audience_id) ka.value=String(c.audience_id);
    var kp=document.getElementById('kProfile'); if(kp && c.sending_profile_id) kp.value=String(c.sending_profile_id);
    document.querySelectorAll('.kVarTpl').forEach(function(sel,i){ if(vars[i] && vars[i].template_id) sel.value=String(vars[i].template_id); });
    // Tracking defaults follow the chosen template's format (HTML on, plain off). On edit,
    // respect the saved values; on a new campaign (or when the template changes), re-default.
    document.querySelectorAll('.kVarTpl').forEach(function(sel){ sel.addEventListener('change',function(){ kTrackSync(true); }); });
    kTrackSync(!c.id);
}
// Is the currently-selected (first variant) template plain text? true/false, or null if none picked.
function kSelPlain(){ var sel=document.querySelector('.kVarTpl'); if(!sel||!sel.value) return null; var t=_tpls.find(function(x){return String(x.id)===String(sel.value);}); return t?(t.is_plain==1):null; }
// Sync the tracking boxes to the selected template. Plain text => tracking unavailable; HTML =>
// available, and defaulted ON when applyDefault (new campaign or a fresh template choice).
function kTrackSync(applyDefault){ var to=document.getElementById('kTrackOpens'), tc=document.getElementById('kTrackClicks'), hint=document.getElementById('kFmtHint'); if(!to||!tc) return;
    var plain=kSelPlain();
    if(plain===true){ to.checked=false; tc.checked=false; to.disabled=tc.disabled=true; to.closest('label').style.opacity=tc.closest('label').style.opacity='0.5'; if(hint) hint.textContent='This template is plain text — tracking is off (a text email can carry neither a tracking pixel nor rewritten links).'; }
    else { to.disabled=tc.disabled=false; to.closest('label').style.opacity=tc.closest('label').style.opacity=''; if(applyDefault && plain===false){ to.checked=true; tc.checked=true; } if(hint) hint.textContent=(plain===false?'This template is HTML — tracking is on by default; untick to turn it off.':'Pick a template below to set the email format.'); }
}
function kAddVar(){ const i=document.querySelectorAll('.kVarTpl').length; const tplOpts='<option value="">— template —</option>'+_tpls.map(o=>'<option value="'+o.id+'">'+esc(o.name)+'</option>').join(''); const div=document.createElement('div'); div.className='ec-row'; div.style.alignItems='end'; div.innerHTML='<div class="ec-fg"><label>Variant '+String.fromCharCode(65+i)+' template</label><select class="ec-sel kVarTpl">'+tplOpts+'</select></div><div class="ec-fg"><label>Weight</label><input class="ec-in kVarW" value="1"></div>'; document.getElementById('kVars').appendChild(div); document.querySelector('#kVars .ec-row:last-child .kVarTpl').addEventListener('change',function(){ kTrackSync(true); }); }
function kSave(){ const vars=[]; document.querySelectorAll('.kVarTpl').forEach((el,i)=>{ if(el.value) vars.push({label:String.fromCharCode(65+i),template_id:el.value,weight:document.querySelectorAll('.kVarW')[i].value||1}); }); if(!vars.length){toast('Add at least one template variant','error');return;}
    post(EC.campaigns,{action:'save',id:document.getElementById('kId').value,name:document.getElementById('kName').value,audience_id:document.getElementById('kAud').value,sending_profile_id:document.getElementById('kProfile').value,from_name:document.getElementById('kFromName').value,from_email:document.getElementById('kFromEmail').value,reply_to:document.getElementById('kReplyTo').value,batch_size:document.getElementById('kBatch').value,batch_interval_min:document.getElementById('kInterval').value,per_domain_limit:document.getElementById('kPerDomain').value,daily_cap:document.getElementById('kDaily').value,warmup_enabled:document.getElementById('kWarm').checked?1:0,ab_enabled:document.getElementById('kAB').checked?1:0,track_opens:document.getElementById('kTrackOpens').checked?1:0,track_clicks:document.getElementById('kTrackClicks').checked?1:0,scheduled_at:document.getElementById('kSched').value,variants:JSON.stringify(vars)}).done(function(r){ if(r.success){ecClose();toast('Saved');kLoad();}else toast(r.message,'error'); }); }
function kReview(id){ post(EC.campaigns,{action:'materialise',id:id}).done(function(m){ post(EC.campaigns,{action:'preview_count',id:id}).done(function(p){
    ecModal('Review & launch','<p>Materialised <b>'+(m.materialised||0)+'</b> new recipients this run.</p><p>Total queued to send (after suppression &amp; dedup): confirm below.</p><p class="ec-hint">Suppressed and already-contacted addresses are excluded automatically.</p>',
    '<button class="ec-btn light" onclick="ecClose()">Close</button><button class="ec-btn" onclick="kLaunch('+id+')">Launch now</button>'); }); }); }
function kLaunch(id){ post(EC.campaigns,{action:'launch',id:id}).done(function(r){ if(r.success){ecClose();toast('Campaign '+r.status);kLoad();dashLoad();}else toast(r.message,'error'); }); }
function kCtl(id,act){ post(EC.campaigns,{action:act,id:id}).done(()=>{toast(act+'d');dashLoad();kLoad();}); }
function kRerun(id){ post(EC.campaigns,{action:'get',id:id}).done(function(g){ var aud=(g.campaign&&g.campaign.audience_id)?g.campaign.audience_id:0;
    ecModal('Add new contacts & re-run',
    '<p>This queues <b>new</b> contacts for this campaign and resumes sending. Anyone already contacted in <b>this</b> campaign is skipped, and suppressed/unsubscribed/replied contacts are always excluded.</p>'+
    '<div style="padding:12px;background:#f9fafb;border:1px solid #eef0f3;border-radius:9px;margin-bottom:12px"><b style="font-size:13px">Add contacts to this campaign</b><div style="margin-top:8px">'+
        (aud?('<button class="ec-btn sm" onclick="kRerunPick('+aud+')"><i class="fas fa-user-plus"></i> Pick contacts</button> <span class="ec-hint" id="rrAdded"></span>')
            :'<span class="ec-hint">This campaign has no audience, so contacts can\'t be added here.</span>')+
        '</div></div>'+
    (aud?'<label class="ec-chk" style="padding:4px 0"><input type="checkbox" id="rrFilter"> Also pull new contacts from the audience\'s saved filter <span class="ec-hint">(re-applies e.g. "all brokers in Germany" to catch ones added since)</span></label>':'<input type="hidden" id="rrFilter">')+
    '<p class="ec-hint">Pick contacts above (they\'re added to the audience), then click <b>Queue &amp; continue</b> to send to just the new ones.</p>',
    '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="kRerunDo('+id+')">Queue &amp; continue</button>'); }); }
function kRerunPick(aud){ ctpOpen({multi:true,title:'Add contacts to this campaign',confirmLabel:'Add to campaign',onPick:function(rows){ var ids=rows.map(function(r){return r.id;}); post(EC.audiences,{action:'add_members',audience_id:aud,contact_ids:ids.join(',')}).done(function(r){ if(r.success){ var el=document.getElementById('rrAdded'); if(el) el.innerHTML='<b>'+(r.added||0)+'</b> added — now click "Queue &amp; continue".'; toast('Added '+r.added); } else toast(r.message,'error'); }); }}); }
function kRerunDo(id){ var rf=document.getElementById('rrFilter'); post(EC.campaigns,{action:'rerun',id:id,refresh_from_filter:(rf&&rf.checked)?1:0}).done(function(r){ if(r.success){ ecClose(); toast((r.materialised||0)+' new recipient(s) queued'); kLoad(); dashLoad(); } else toast(r.message,'error'); }); }

/* ---------- Sending profiles ---------- */
function pLoad(){ post(EC.profiles,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>From</th><th>SMTP</th><th>IMAP</th><th>Active</th><th></th></tr></thead><tbody>'; (r.rows||[]).forEach(p=>h+='<tr><td>'+esc(p.name)+'</td><td>'+esc(p.from_email)+'</td><td>'+esc(p.smtp_host||'')+(p.smtp_pass_set==1?' 🔑':'')+'</td><td>'+esc(p.imap_host||'')+(p.imap_pass_set==1?' 🔑':'')+'</td><td>'+(p.active==1?'yes':'no')+'</td><td><button class="ec-btn light sm" onclick="pEdit('+p.id+')">Edit</button> <button class="ec-btn danger sm" onclick="pDel('+p.id+')">Delete</button></td></tr>'); h+='</tbody></table>'; document.getElementById('pTable').innerHTML=h; }); }
function pForm(p){ p=p||{}; return '<input type="hidden" id="pId" value="'+(p.id||'')+'">'+
    '<div class="ec-row"><div class="ec-fg"><label>Profile name *</label><input class="ec-in" id="pName" value="'+esc(p.name||'')+'" placeholder="Brokers DE — mail.brokers.tld"></div><div class="ec-fg"><label>Active</label><select class="ec-sel" id="pActive"><option value="1"'+(p.active!=0?' selected':'')+'>Active</option><option value="0"'+(p.active==0?' selected':'')+'>Inactive</option></select></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>From name</label><input class="ec-in" id="pFromName" value="'+esc(p.from_name||'')+'"></div><div class="ec-fg"><label>From email * <span class="ec-hint">(this sets the sending subdomain)</span></label><input class="ec-in" id="pFromEmail" value="'+esc(p.from_email||'')+'" placeholder="news@mail.brokers.tld"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Reply-to mailbox</label><input class="ec-in" id="pReplyTo" value="'+esc(p.reply_to||'')+'" placeholder="replies@mail.brokers.tld"></div><div class="ec-fg"><label>Bounce address (VERP) <span class="ec-hint">(e.g. bounce@mail.brokers.tld)</span></label><input class="ec-in" id="pBounce" value="'+esc(p.bounce_address||'')+'"></div></div>'+
    '<h4 style="margin:14px 0 6px">SMTP (sending)</h4>'+
    '<div class="ec-row"><div class="ec-fg"><label>Host</label><input class="ec-in" id="pSmtpHost" value="'+esc(p.smtp_host||'')+'"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="pSmtpPort" value="'+(p.smtp_port||587)+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Security</label><select class="ec-sel" id="pSmtpSec"><option value="tls"'+((p.smtp_security||"tls")=="tls"?" selected":"")+'>STARTTLS (587)</option><option value="ssl"'+(p.smtp_security=="ssl"?" selected":"")+'>SSL (465)</option><option value="none"'+(p.smtp_security=="none"?" selected":"")+'>None</option></select></div><div class="ec-fg"><label>Username</label><input class="ec-in" id="pSmtpUser" value="'+esc(p.smtp_user||'')+'"></div></div>'+
    '<div class="ec-fg"><label>SMTP password <span class="ec-hint">'+(p.smtp_pass_set==1?'(a password is saved — leave blank to keep it, or type a new one to change it)':'(no password saved yet)')+'</span></label><input class="ec-in" id="pSmtpPass" type="password" autocomplete="off" readonly onfocus="this.removeAttribute(\'readonly\')" placeholder="'+(p.smtp_pass_set==1?'••••••••••••  (saved)':'no password set')+'"></div>'+
    '<h4 style="margin:14px 0 6px">IMAP (replies &amp; bounces)</h4>'+
    '<div class="ec-row"><div class="ec-fg"><label>Host</label><input class="ec-in" id="pImapHost" value="'+esc(p.imap_host||'')+'"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="pImapPort" value="'+(p.imap_port||993)+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Username</label><input class="ec-in" id="pImapUser" value="'+esc(p.imap_user||'')+'"></div><div class="ec-fg"><label>IMAP password <span class="ec-hint">'+(p.imap_pass_set==1?'(a password is saved — leave blank to keep it, or type a new one to change it)':'(no password saved yet)')+'</span></label><input class="ec-in" id="pImapPass" type="password" autocomplete="off" readonly onfocus="this.removeAttribute(\'readonly\')" placeholder="'+(p.imap_pass_set==1?'••••••••••••  (saved)':'no password set')+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Reply mailbox (folder)</label><input class="ec-in" id="pReplyMbx" value="'+esc(p.reply_mailbox||'INBOX')+'"></div><div class="ec-fg"><label>Bounce mailbox (folder)</label><input class="ec-in" id="pBounceMbx" value="'+esc(p.bounce_mailbox||'')+'"></div></div>'; }
function pNew(){ ecModal('New sending profile',pForm(),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="pSave()">Save</button>'); }
function pEdit(id){ post(EC.profiles,{action:'get',id:id}).done(function(r){ if(r.success) ecModal('Edit sending profile',pForm(r.profile),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="pSave()">Save</button>'); }); }
function pSave(){ const d={action:'save',id:document.getElementById('pId').value,name:document.getElementById('pName').value,active:document.getElementById('pActive').value,from_name:document.getElementById('pFromName').value,from_email:document.getElementById('pFromEmail').value,reply_to:document.getElementById('pReplyTo').value,bounce_address:document.getElementById('pBounce').value,smtp_host:document.getElementById('pSmtpHost').value,smtp_port:document.getElementById('pSmtpPort').value,smtp_security:document.getElementById('pSmtpSec').value,smtp_user:document.getElementById('pSmtpUser').value,smtp_pass:document.getElementById('pSmtpPass').value,imap_host:document.getElementById('pImapHost').value,imap_port:document.getElementById('pImapPort').value,imap_user:document.getElementById('pImapUser').value,imap_pass:document.getElementById('pImapPass').value,reply_mailbox:document.getElementById('pReplyMbx').value,bounce_mailbox:document.getElementById('pBounceMbx').value}; post(EC.profiles,d).done(function(r){ if(r.success){ecClose();toast('Saved');pLoad();}else toast(r.message,'error'); }); }
function pDel(id){ Swal.fire({title:'Delete profile?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.profiles,{action:'delete',id:id}).done(()=>{toast('Deleted');pLoad();}); }); }

/* ---------- Reports ---------- */
function rInit(){ post(EC.campaigns,{action:'list'}).done(function(r){ const sel=document.getElementById('rCampaign'); sel.innerHTML='<option value="">Choose a campaign…</option>'+(r.rows||[]).map(c=>'<option value="'+c.id+'">'+esc(c.name)+'</option>').join(''); }); }
function rLoad(){ const id=document.getElementById('rCampaign').value; if(!id){document.getElementById('rBody').innerHTML='';return;} post(EC.campaigns,{action:'report',id:id}).done(function(r){ if(!r.success){toast(r.message,'error');return;} const f=r.funnel,bs=r.by_status||{},cm=r.campaign||{};
    const fmt=function(d){ return d? String(d).replace('T',' ').slice(0,16) : '—'; };
    let h='<div class="ec-hint" style="margin:0 0 14px">Status: <b>'+esc(cm.status||'')+'</b> &nbsp;·&nbsp; Created '+fmt(cm.created_at)+(cm.scheduled_at?(' &nbsp;·&nbsp; Scheduled '+fmt(cm.scheduled_at)):'')+' &nbsp;·&nbsp; Started '+fmt(cm.started_at)+' &nbsp;·&nbsp; Completed '+fmt(cm.completed_at)+'</div>';
    // Delivery row — so a campaign that sent nothing still shows why.
    h+='<h3 style="margin-top:0">Delivery</h3><div class="ec-statrow">'+stat(bs.sent||0,'sent')+stat(bs.queued||0,'queued')+stat(bs.sending||0,'sending')+stat(bs.failed||0,'failed')+stat(bs.skipped||0,'skipped')+'</div>';
    if((bs.failed||0)>0 || (bs.skipped||0)>0){
        h+='<div class="ec-callout" style="margin-top:10px"><b>'+((bs.failed||0)+(bs.skipped||0))+' recipient(s) did not send.</b> ';
        if(r.errors&&r.errors.length){ h+='Reasons: '+r.errors.map(e=>esc(e.error)+' ('+e.n+')').join(', ')+'.'; }
        h+=' See the per-recipient list below.</div>';
    }
    // A metric whose tracking was off shows "off" rather than a misleading 0.
    var opensOn=!cm.plain_text&&cm.track_opens==1, clicksOn=!cm.plain_text&&cm.track_clicks==1;
    h+='<h3>Engagement</h3><div class="ec-statrow">'+stat(f.sent,'sent')+(opensOn?stat(f.open,'opened'):stat('off','opens'))+(clicksOn?stat(f.click,'clicked'):stat('off','clicks'))+stat(f.reply,'replied')+stat(f.visit,'visits')+stat(f.bounce,'bounced')+stat(f.unsubscribe,'unsub')+'</div>';
    if(cm.plain_text){ h+='<div class="ec-hint" style="margin-top:6px">Sent as plain text — open &amp; click tracking off.</div>'; }
    else if(!opensOn||!clicksOn){ h+='<div class="ec-hint" style="margin-top:6px">'+([opensOn?'':'open',clicksOn?'':'click'].filter(Boolean).join(' &amp; '))+' tracking was off for this campaign.</div>'; }
    h+='<h3>A/B variants</h3><table class="ec-tbl"><thead><tr><th>Variant</th><th>Recipients</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Replied</th></tr></thead><tbody>';
    (r.variants||[]).forEach(v=>h+='<tr><td>'+esc(v.label)+'</td><td>'+v.recips+'</td><td>'+(v.sent||0)+'</td><td>'+(v.opened||0)+'</td><td>'+(v.clicked||0)+'</td><td>'+(v.replied||0)+'</td></tr>');
    h+='</tbody></table><div style="margin-top:10px"><button class="ec-btn light sm" onclick="rRecips('+id+')">Show recipients</button></div><div id="rRecips"></div>';
    document.getElementById('rBody').innerHTML=h;
    if((bs.failed||0)>0 || (bs.skipped||0)>0) rRecips(id); // auto-open so the reason is visible
    }); }
function rRecips(id){ post(EC.campaigns,{action:'recipients',id:id}).done(function(r){ let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Status</th><th>Reason / error</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Replied</th><th>Unsub</th><th>Bounced</th></tr></thead><tbody>'; (r.rows||[]).forEach(x=>h+='<tr><td>'+esc(x.email)+'</td><td>'+esc(x.status)+'</td><td style="color:#b91c1c">'+esc(x.error||'')+'</td><td>'+esc((x.sent_at||'').replace('T',' '))+'</td><td>'+(x.opened_at?'✓':'')+'</td><td>'+(x.first_click_at?'✓':'')+'</td><td>'+(x.replied_at?'✓':'')+'</td><td>'+(x.unsubscribed_at?'<span style="color:#b45309">✓</span>':'')+'</td><td>'+(x.bounce_type?esc(x.bounce_type):'')+'</td></tr>'); h+='</tbody></table>'; document.getElementById('rRecips').innerHTML=h; }); }

/* ---------- Responses (replies + bounces) ---------- */
function respLoad(){ var type=document.getElementById('respFilter')?document.getElementById('respFilter').value:''; post(EC.responses,{action:'list',type:type,limit:200}).done(function(r){ if(!r.success){toast(r.message,'error');return;}
    var cc=document.getElementById('respCount'); if(cc) cc.textContent=(r.total||0)+' '+(type||'response')+(r.total===1?'':'s');
    if(!r.rows||!r.rows.length){ document.getElementById('respTable').innerHTML='<p class="ec-hint">No responses collected yet. Click <b>Check for new responses</b> to poll your mailboxes now (IMAP must be set on the sending profile).</p>'; return; }
    var h='<table class="ec-tbl"><thead><tr><th>Type</th><th>From</th><th>Campaign</th><th>Subject</th><th>When</th><th></th></tr></thead><tbody>';
    r.rows.forEach(function(x){
        var badge = x.type==='bounce' ? '<span class="ec-badge b-cancelled">bounce'+(x.bounce_type?(' · '+esc(x.bounce_type)):'')+'</span>' : '<span class="ec-badge b-sending">reply</span>';
        h+='<tr style="cursor:pointer" onclick="respView('+x.id+')"><td>'+badge+'</td><td>'+esc(x.contact_email||'')+'</td><td>'+esc(x.campaign||'—')+'</td><td>'+esc(x.subject||'(no subject)')+'<div class="ec-hint" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(x.snippet||'')+'</div></td><td>'+esc((x.received_at||'').replace('T',' '))+'</td><td><button class="ec-btn light sm" onclick="event.stopPropagation();respView('+x.id+')">Read</button></td></tr>';
    });
    h+='</tbody></table>'; document.getElementById('respTable').innerHTML=h;
}); }
function respView(id){ post(EC.responses,{action:'get',id:id}).done(function(r){ if(!r.success){toast(r.message||'Not found','error');return;} var x=r.response;
    var meta='<div class="ec-hint" style="margin-bottom:12px">'+(x.type==='bounce'?'<b>Bounce</b>'+(x.bounce_type?(' ('+esc(x.bounce_type)+')'):''):'<b>Reply</b>')+' from <b>'+esc(x.contact_email||'')+'</b>'+(x.campaign?(' · campaign: '+esc(x.campaign)):'')+' · '+esc((x.received_at||'').replace('T',' '))+'</div>';
    var body='<div style="white-space:pre-wrap;font-size:13.5px;line-height:1.55;max-height:52vh;overflow:auto;border:1px solid #eef0f3;border-radius:8px;padding:12px;background:#fafafa">'+esc(x.body||'(no text content)')+'</div>';
    ecModal(x.subject||'(no subject)', meta+body, '<button class="ec-btn danger light" onclick="respDel('+id+')">Delete</button><span style="flex:1"></span><button class="ec-btn" onclick="ecClose()">Close</button>');
}); }
function respDel(id){ post(EC.responses,{action:'delete',id:id}).done(function(r){ if(r.success){ ecClose(); toast('Deleted'); respLoad(); } else toast(r.message,'error'); }); }
function respPoll(){ toast('Checking mailboxes…','info'); post(EC.responses,{action:'poll'}).done(function(r){ if(r.success){ toast('Checked. '+(r.log||'').split('\n')[0]); respLoad(); } else toast(r.message||'Poll failed','error'); }); }

/* ---------- Settings ---------- */
const S_FIELDS=['default_from_name','default_from_email','default_reply_to','daily_cap','warmup_json','track_base'];
function sLoad(){ post(EC.settings,{action:'get'}).done(function(r){ if(!r.success){toast(r.message||'no access','error');return;} const s=r.settings||{}; S_FIELDS.forEach(k=>{ const el=document.getElementById('s_'+k); if(el&&s[k]!=null) el.value=s[k]; }); post(EC.settings,{action:'imap_check'}).done(x=>{ document.getElementById('s_imap_status').textContent='PHP IMAP extension: '+x.imap_ext; }); }); }
function sSave(){ const d={action:'save'}; S_FIELDS.forEach(k=>{ const el=document.getElementById('s_'+k); if(el) d[k]=el.value; }); post(EC.settings,d).done(function(r){ toast(r.success?'Saved':(r.message||'Failed'), r.success?'success':'error'); if(r.success) sLoad(); }); }

dashLoad();
</script>
</body>
</html>
