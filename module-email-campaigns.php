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
        .ec-head{margin-bottom:18px}.ec-head h1{font-size:28px;font-weight:700;color:#111827;margin:0 0 4px}.ec-head p{color:#6b7280;font-size:14px;margin:0}
        .ec-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;margin-bottom:20px}
        .ec-tab{padding:10px 16px;font-size:14px;font-weight:600;color:#6b7280;cursor:pointer;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0}
        .ec-tab.active{color:#4f46e5;background:#fff;border-color:#e5e7eb}
        .ec-pane{display:none}.ec-pane.active{display:block}
        .ec-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:16px}
        .ec-btn{background:#4f46e5;color:#fff;border:none;padding:9px 15px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
        .ec-btn:hover{background:#4338ca}.ec-btn.light{background:#fff;color:#374151;border:1px solid #d1d5db}.ec-btn.danger{background:#fff;color:#dc2626;border:1px solid #fecaca}
        .ec-btn.sm{padding:5px 10px;font-size:12.5px}
        .ec-in,.ec-sel,.ec-ta{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
        .ec-ta{min-height:120px;resize:vertical}
        .ec-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .ec-fg{margin-bottom:12px}.ec-fg label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:5px}
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
        .ec-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:flex-start;justify-content:center;z-index:2000;overflow-y:auto;padding:34px 14px}
        .ec-overlay.open{display:flex}.ec-modal{background:#fff;border-radius:12px;width:100%;max-width:720px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
        .ec-modal h2{font-size:17px;margin:0}.ec-mh{padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
        .ec-mb{padding:18px 20px}.ec-mf{padding:14px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px}
        .closex{background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer}
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
                <div class="ec-tab" data-tab="campaigns">Campaigns</div>
                <div class="ec-tab" data-tab="sending">Sending</div>
                <div class="ec-tab" data-tab="reports">Reports</div>
                <div class="ec-tab" data-tab="guide">Guide</div>
                <?php if ($canSettings): ?><div class="ec-tab" data-tab="settings">Settings</div><?php endif; ?>
            </div>

            <div class="ec-pane active" data-pane="dashboard"><div id="dashWrap" class="ec-card">Loading…</div></div>

            <div class="ec-pane" data-pane="contacts">
                <div class="ec-card">
                    <div style="display:flex;gap:10px;margin-bottom:14px"><button class="ec-btn sm" onclick="cOpenImport()"><i class="fas fa-file-import"></i> Import contacts</button><button class="ec-btn light sm" onclick="cOpenAdd()"><i class="fas fa-plus"></i> Add one</button></div>
                    <div class="ec-filters">
                        <div class="ec-fg" style="flex:2;min-width:220px"><label>Search</label><input class="ec-in" id="cSearch" placeholder="email / name / company / city"></div>
                        <div class="ec-fg" style="min-width:170px"><label>Type</label><select class="ec-sel" id="cType"><option value="">All types</option></select></div>
                        <div class="ec-fg" style="min-width:140px"><label>Country</label><input class="ec-in" id="cCountry" placeholder="e.g. Germany"></div>
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
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="aBuild()"><i class="fas fa-filter"></i> Build from filter</button><button class="ec-btn light sm" onclick="aCreate()"><i class="fas fa-plus"></i> Empty audience</button><span class="ec-hint">Build a list by type/country and automatically exclude anyone suppressed (unsubscribed/bounced/replied) — and optionally anyone already emailed.</span></div>
                    <div id="aTable"></div>
                </div>
                <div class="ec-card" id="aMembersCard" style="display:none">
                    <div class="ec-toolbar"><b id="aMembersTitle"></b><span style="flex:1"></span>
                        <input class="ec-in" id="aAddSearch" style="max-width:240px" placeholder="Add by search (email/company/city)">
                        <button class="ec-btn sm" onclick="aAddBySearch()">Add matches</button>
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

            <div class="ec-pane" data-pane="campaigns">
                <div class="ec-card">
                    <div class="ec-toolbar"><button class="ec-btn sm" onclick="kNew()"><i class="fas fa-plus"></i> New campaign</button></div>
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
                    <div class="ec-toolbar"><select class="ec-sel" id="rCampaign" style="max-width:340px" onchange="rLoad()"><option value="">Choose a campaign…</option></select></div>
                    <div id="rBody"></div>
                </div>
            </div>

            <div class="ec-pane" data-pane="guide">
                <div class="ec-card ec-guide">
                    <h3 style="margin-top:0">How the Email Campaign Manager works</h3>
                    <p>This tool takes lists of contacts (typed in, imported, or fed in by the scrapers), lets you write reusable templates, and sends them out in controlled batches from a chosen sending identity — while tracking who opened, clicked, replied, bounced or unsubscribed, and making sure nobody is ever emailed twice or contacted after opting out.</p>

                    <h3>Create a campaign, start to finish</h3>
                    <div class="ec-step"><div class="ec-stepn">1</div><div><b>Set up a Sending profile</b> (Sending tab). Create the identity you'll send from — its subdomain/from‑address, SMTP login, and reply/bounce mailboxes (you create those mailboxes on the mail server yourself). You can keep several profiles and pick one per campaign.</div></div>
                    <div class="ec-step"><div class="ec-stepn">2</div><div><b>Get contacts in</b> (Contacts tab). Import a CSV/paste (set a <i>Contact type</i> like "Insurance Brokers"), or let the broker/café scrapers fill them in. Duplicates merge and anyone already suppressed is skipped automatically.</div></div>
                    <div class="ec-step"><div class="ec-stepn">3</div><div><b>Build an audience</b> (Audiences → <i>Build from filter</i>). Choose e.g. type = Insurance Brokers, country = Germany, tick <i>exclude already‑emailed</i>. Suppressed/unsubscribed/replied contacts are always excluded. The live count shows exactly how many will receive it.</div></div>
                    <div class="ec-step"><div class="ec-stepn">4</div><div><b>Write a template</b> (Templates tab). Use merge fields like <code>{{first_name}}</code> and <code>{{company}}</code>, and include the required <code>{{unsubscribe_url}}</code>. Preview it and send a test to yourself.</div></div>
                    <div class="ec-step"><div class="ec-stepn">5</div><div><b>Create the campaign</b> (Campaigns tab). Pick the audience, the sending profile and one or more template variants (add a second for an A/B test). Set the batch size, the interval between batches, per‑domain limit, daily cap, optional warm‑up ramp, and an optional scheduled start.</div></div>
                    <div class="ec-step"><div class="ec-stepn">6</div><div><b>Review &amp; launch.</b> "Review &amp; launch" expands the audience into recipients (excluding suppressed and anyone already in this campaign) and shows the true send count. Launch — the sender then trickles the emails out in batches on the schedule you set.</div></div>
                    <div class="ec-step"><div class="ec-stepn">7</div><div><b>Watch it run</b> (Dashboard). Live counters show sent / queued / failed / opened / clicked / replied / unsubscribed, with pause/resume/cancel.</div></div>
                    <div class="ec-step"><div class="ec-stepn">8</div><div><b>See the results</b> (Reports). Per‑campaign funnel and A/B comparison, plus a per‑recipient breakdown. Replies auto‑stop further emails to that person; hard bounces and unsubscribes go onto the global suppression list so they're never contacted again.</div></div>

                    <h3>How it protects deliverability &amp; compliance</h3>
                    <ul>
                        <li><b>Never twice:</b> every send is recorded; a contact can't be added to the same campaign twice, and "exclude already‑emailed" keeps them out of new ones.</li>
                        <li><b>Suppression:</b> unsubscribes, hard bounces, replies and manual do‑not‑contact all feed one global list that's checked before every single send.</li>
                        <li><b>One‑click unsubscribe</b> + <code>List‑Unsubscribe</code> headers, plain‑text + HTML parts, per‑domain throttling and warm‑up — all on by default.</li>
                        <li><b>Your responsibility:</b> confirm you have a lawful basis to email the list (in Germany, B2B cold email is regulated). The tool records a consent/source note per contact.</li>
                    </ul>
                    <p class="ec-hint">Sending needs the mail server set up (SMTP/IMAP mailboxes + SPF/DKIM/DMARC) and the two cron jobs running. Ask your admin if the Dashboard shows sends stuck in "queued".</p>
                </div>
            </div>

            <?php if ($canSettings): ?>
            <div class="ec-pane" data-pane="settings">
                <div class="ec-card" style="max-width:760px">
                    <h3 style="margin-top:0">Sending (SMTP)</h3>
                    <div class="ec-row"><div class="ec-fg"><label>SMTP host</label><input class="ec-in" id="s_smtp_host"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="s_smtp_port" value="587"></div></div>
                    <div class="ec-row"><div class="ec-fg"><label>Security</label><select class="ec-sel" id="s_smtp_security"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL (465)</option><option value="none">None</option></select></div><div class="ec-fg"><label>SMTP username</label><input class="ec-in" id="s_smtp_user"></div></div>
                    <div class="ec-fg"><label>SMTP password <span class="ec-hint">(leave blank to keep existing — <span id="s_smtp_pass_set"></span>)</span></label><input class="ec-in" id="s_smtp_pass" type="password" autocomplete="new-password"></div>
                    <div class="ec-row"><div class="ec-fg"><label>Default from name</label><input class="ec-in" id="s_default_from_name"></div><div class="ec-fg"><label>Default from email</label><input class="ec-in" id="s_default_from_email"></div></div>
                    <div class="ec-fg"><label>Default reply-to</label><input class="ec-in" id="s_default_reply_to"></div>
                    <h3>Replies &amp; bounces (IMAP)</h3>
                    <div class="ec-row"><div class="ec-fg"><label>IMAP host</label><input class="ec-in" id="s_imap_host"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="s_imap_port" value="993"></div></div>
                    <div class="ec-row"><div class="ec-fg"><label>IMAP username</label><input class="ec-in" id="s_imap_user"></div><div class="ec-fg"><label>IMAP password <span class="ec-hint">(<span id="s_imap_pass_set"></span>)</span></label><input class="ec-in" id="s_imap_pass" type="password" autocomplete="new-password"></div></div>
                    <div class="ec-fg"><label>Bounce mailbox <span class="ec-hint">(folder receiving VERP bounce+*@ mail; blank = INBOX)</span></label><input class="ec-in" id="s_imap_bounce_mailbox"></div>
                    <h3>Throttle &amp; warm-up</h3>
                    <div class="ec-row"><div class="ec-fg"><label>Global daily cap <span class="ec-hint">(0 = none)</span></label><input class="ec-in" id="s_daily_cap" value="0"></div><div class="ec-fg"><label>Warm-up ramp (JSON daily caps)</label><input class="ec-in" id="s_warmup_json" placeholder="[50,100,200,400,800]"></div></div>
                    <div class="ec-fg"><label>Tracking base URL</label><input class="ec-in" id="s_track_base" value="https://theeyenewspapers.com/management"></div>
                    <div style="display:flex;gap:10px;align-items:center"><button class="ec-btn" onclick="sSave()"><i class="fas fa-save"></i> Save settings</button><span class="ec-hint" id="s_imap_status"></span></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- generic modal -->
    <div class="ec-overlay" id="ecModal"><div class="ec-modal"><div class="ec-mh"><h2 id="ecModalTitle"></h2><button class="closex" onclick="ecClose()">&times;</button></div><div class="ec-mb" id="ecModalBody"></div><div class="ec-mf" id="ecModalFoot"></div></div></div>

<script>
const EC = { contacts:'ajax/ec_contacts.php', audiences:'ajax/ec_audiences.php', templates:'ajax/ec_templates.php', campaigns:'ajax/ec_campaigns.php', settings:'ajax/ec_settings.php', profiles:'ajax/ec_profiles.php' };
function toast(m,i){ Swal.fire({toast:true,position:'top-end',timer:2600,showConfirmButton:false,icon:i||'success',title:m}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function post(url,data){ return $.post(url,data,null,'json'); }
function ecModal(title,body,foot){ document.getElementById('ecModalTitle').textContent=title; document.getElementById('ecModalBody').innerHTML=body; document.getElementById('ecModalFoot').innerHTML=foot; document.getElementById('ecModal').classList.add('open'); }
function ecClose(){ document.getElementById('ecModal').classList.remove('open'); }

// tabs
document.querySelectorAll('.ec-tab').forEach(t=>t.addEventListener('click',function(){
    document.querySelectorAll('.ec-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.ec-pane').forEach(x=>x.classList.remove('active'));
    this.classList.add('active');
    document.querySelector('.ec-pane[data-pane="'+this.dataset.tab+'"]').classList.add('active');
    const f={dashboard:dashLoad,contacts:cLoad,audiences:aLoad,templates:tLoad,campaigns:kLoad,sending:pLoad,reports:rInit,settings:sLoad}[this.dataset.tab];
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
let _types=[];
function cTypes(cb){ post(EC.contacts,{action:'types'}).done(function(r){ _types=(r.rows||[]).map(x=>x.contact_type); const sel=document.getElementById('cType'); if(sel){ const cur=sel.value; sel.innerHTML='<option value="">All types</option>'+_types.map(t=>'<option'+(t===cur?' selected':'')+'>'+esc(t)+'</option>').join(''); } if(cb)cb(); }); }
function cLoad(){ cTypes(); post(EC.contacts,{action:'list',q:document.getElementById('cSearch').value,type:document.getElementById('cType').value,country:document.getElementById('cCountry').value,exclude_contacted:document.getElementById('cExclContacted').checked?1:0,exclude_suppressed:document.getElementById('cExclSupp').checked?1:0}).done(function(r){
    if(!r.success){toast(r.message,'error');return;}
    document.getElementById('cCount').textContent=r.total+' matching contact'+(r.total===1?'':'s');
    let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Type</th><th>Country</th><th>Emailed</th><th>Status</th><th></th></tr></thead><tbody>';
    r.rows.forEach(c=>h+='<tr><td>'+esc(c.email)+'</td><td>'+esc((c.first_name||'')+' '+(c.last_name||''))+'</td><td>'+esc(c.company||'')+'</td><td>'+esc(c.contact_type||'')+'</td><td>'+esc(c.country||'')+'</td><td>'+(c.times_sent>0?('<b>'+c.times_sent+'×</b>'):'—')+'</td><td>'+(c.suppressed==1?'<span class="ec-badge b-cancelled">suppressed</span>':esc(c.status))+'</td><td>'+(c.suppressed==1?'':'<button class="ec-btn danger sm" onclick="cSuppress(\''+esc(c.email)+'\')">Suppress</button>')+'</td></tr>');
    h+='</tbody></table>'; document.getElementById('cTable').innerHTML=h;
}); }
function cOpenImport(){ ecModal('Import contacts',
    '<p class="ec-hint">Paste CSV with a header row (<code>email</code> required; optional <code>first_name,last_name,company,phone,city,country,contact_type</code>) or one email per line.</p>'+
    '<textarea class="ec-ta" id="impData" style="min-height:170px" placeholder="email,first_name,company,city\njohn@x.de,John,ACME Versicherung,Berlin"></textarea>'+
    '<div class="ec-row" style="margin-top:10px"><div class="ec-fg"><label>Contact type <span class="ec-hint">(e.g. Insurance Brokers, Restaurants)</span></label><input class="ec-in" id="impType" list="impTypeList" placeholder="Insurance Brokers"><datalist id="impTypeList">'+_types.map(t=>'<option value="'+esc(t)+'">').join('')+'</datalist></div><div class="ec-fg"><label>Source tag</label><input class="ec-in" id="impSource" value="csv"></div></div>'+
    '<div class="ec-fg"><label>Consent basis (optional, for your records)</label><input class="ec-in" id="impConsent" placeholder="e.g. legitimate interest / opted-in list"></div>',
    '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="cDoImport()">Import</button>'); }
function cDoImport(){ post(EC.contacts,{action:'import',data:document.getElementById('impData').value,contact_type:document.getElementById('impType').value,source:document.getElementById('impSource').value,consent_basis:document.getElementById('impConsent').value}).done(function(r){ if(r.success){ecClose();toast('New '+r.new+', updated '+r.updated+', skipped '+r.skipped_suppressed+', invalid '+r.invalid);cLoad();}else toast(r.message,'error'); }); }
function cOpenAdd(){ ecModal('Add contact','<div class="ec-fg"><label>Email *</label><input class="ec-in" id="adEmail"></div><div class="ec-row"><div class="ec-fg"><label>First name</label><input class="ec-in" id="adFn"></div><div class="ec-fg"><label>Last name</label><input class="ec-in" id="adLn"></div></div><div class="ec-fg"><label>Company</label><input class="ec-in" id="adCo"></div>','<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="cDoAdd()">Add</button>'); }
function cDoAdd(){ post(EC.contacts,{action:'add',email:document.getElementById('adEmail').value,first_name:document.getElementById('adFn').value,last_name:document.getElementById('adLn').value,company:document.getElementById('adCo').value}).done(function(r){ if(r.success){ecClose();toast('Added');cLoad();}else toast(r.message,'error'); }); }
function cSuppress(email){ Swal.fire({title:'Suppress '+email+'?',text:'They will never be emailed again.',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Suppress'}).then(x=>{ if(x.isConfirmed) post(EC.contacts,{action:'suppress',email:email,reason:'do_not_contact'}).done(()=>{toast('Suppressed');cLoad();}); }); }

/* ---------- Audiences ---------- */
let aCurrent=null;
function aLoad(){ post(EC.audiences,{action:'list'}).done(function(r){
    if(!r.success){toast(r.message,'error');return;}
    let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Members</th><th>Sendable</th><th></th></tr></thead><tbody>';
    r.rows.forEach(a=>h+='<tr><td>'+esc(a.name)+'</td><td>'+a.members+'</td><td>'+a.sendable+'</td><td><button class="ec-btn light sm" onclick="aOpen('+a.id+',\''+esc(a.name).replace(/\'/g,"")+'\')">Members</button> <button class="ec-btn danger sm" onclick="aDel('+a.id+')">Delete</button></td></tr>');
    h+='</tbody></table>'; document.getElementById('aTable').innerHTML=h;
}); }
function aCreate(){ ecModal('New audience','<div class="ec-fg"><label>Name *</label><input class="ec-in" id="auName"></div><div class="ec-fg"><label>Description</label><input class="ec-in" id="auDesc"></div>','<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="aDoCreate()">Create</button>'); }
function aBuild(){ cTypes(function(){
    ecModal('Build audience from filter',
    '<div class="ec-fg"><label>Audience name *</label><input class="ec-in" id="abName" placeholder="Brokers — Germany (Sept)"></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Contact type</label><select class="ec-sel" id="abType"><option value="">Any type</option>'+_types.map(t=>'<option>'+esc(t)+'</option>').join('')+'</select></div><div class="ec-fg"><label>Country</label><input class="ec-in" id="abCountry" placeholder="Germany"></div></div>'+
    '<div class="ec-fg"><label>Extra search (optional)</label><input class="ec-in" id="abQ" placeholder="company / city / keyword"></div>'+
    '<label class="ec-chk"><input type="checkbox" id="abExclContacted" checked> Exclude anyone already emailed in a previous campaign</label>'+
    '<div class="ec-hint" style="margin-top:6px">Suppressed contacts (unsubscribed, bounced, replied, do-not-contact) are <b>always</b> excluded.</div>'+
    '<div style="margin-top:12px;padding:12px;background:#eef2ff;border-radius:8px"><b id="abCount">—</b> contacts match. <button class="ec-btn light sm" style="margin-left:8px" onclick="aBuildCount()">Recount</button></div>',
    '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="aBuildDo()">Create &amp; populate</button>');
    aBuildCount();
}); }
function aBuildFilter(){ return {q:document.getElementById('abQ').value,type:document.getElementById('abType').value,country:document.getElementById('abCountry').value,exclude_contacted:document.getElementById('abExclContacted').checked?1:0}; }
function aBuildCount(){ post(EC.audiences,Object.assign({action:'count_filter'},aBuildFilter())).done(function(r){ if(r.success) document.getElementById('abCount').textContent=r.count; }); }
function aBuildDo(){ const name=document.getElementById('abName').value.trim(); if(!name){toast('Name required','error');return;}
    post(EC.audiences,{action:'create',name:name}).done(function(r){ if(!r.success){toast(r.message,'error');return;} post(EC.audiences,Object.assign({action:'add_members',audience_id:r.id},aBuildFilter())).done(function(x){ ecClose(); toast('Audience created with '+(x.added||0)+' contacts'); aLoad(); }); }); }
function aDoCreate(){ post(EC.audiences,{action:'create',name:document.getElementById('auName').value,description:document.getElementById('auDesc').value}).done(function(r){ if(r.success){ecClose();toast('Created');aLoad();}else toast(r.message,'error'); }); }
function aDel(id){ Swal.fire({title:'Delete audience?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.audiences,{action:'delete',id:id}).done(()=>{toast('Deleted');aLoad();}); }); }
function aOpen(id,name){ aCurrent=id; document.getElementById('aMembersCard').style.display='block'; document.getElementById('aMembersTitle').textContent='Members of "'+name+'"'; aMembers(); }
function aMembers(){ post(EC.audiences,{action:'members',audience_id:aCurrent}).done(function(r){ let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Name</th><th>Company</th><th>Status</th><th></th></tr></thead><tbody>'; (r.rows||[]).forEach(m=>h+='<tr><td>'+esc(m.email)+'</td><td>'+esc((m.first_name||'')+' '+(m.last_name||''))+'</td><td>'+esc(m.company||'')+'</td><td>'+esc(m.status)+'</td><td><button class="ec-btn light sm" onclick="aRemove('+m.id+')">Remove</button></td></tr>'); h+='</tbody></table>'; document.getElementById('aMembers').innerHTML=h; }); }
function aAddBySearch(){ post(EC.audiences,{action:'add_members',audience_id:aCurrent,q:document.getElementById('aAddSearch').value}).done(function(r){ if(r.success){toast('Added '+r.added);aMembers();aLoad();}else toast(r.message,'error'); }); }
function aRemove(cid){ post(EC.audiences,{action:'remove_member',audience_id:aCurrent,contact_id:cid}).done(()=>{aMembers();aLoad();}); }

/* ---------- Templates ---------- */
function tLoad(){ post(EC.templates,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Subject</th><th>From</th><th></th></tr></thead><tbody>'; r.rows.forEach(t=>h+='<tr><td>'+esc(t.name)+'</td><td>'+esc(t.subject)+'</td><td>'+esc(t.from_email||'')+'</td><td><button class="ec-btn light sm" onclick="tEdit('+t.id+')">Edit</button> <button class="ec-btn danger sm" onclick="tDel('+t.id+')">Delete</button></td></tr>'); h+='</tbody></table>'; document.getElementById('tTable').innerHTML=h; }); }
function tForm(t){ t=t||{}; return '<input type="hidden" id="tId" value="'+(t.id||'')+'">'+
    '<div class="ec-row"><div class="ec-fg"><label>Name *</label><input class="ec-in" id="tName" value="'+esc(t.name||'')+'"></div><div class="ec-fg"><label>Subject *</label><input class="ec-in" id="tSubject" value="'+esc(t.subject||'')+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>From name</label><input class="ec-in" id="tFromName" value="'+esc(t.from_name||'')+'"></div><div class="ec-fg"><label>From email</label><input class="ec-in" id="tFromEmail" value="'+esc(t.from_email||'')+'"></div></div>'+
    '<div class="ec-fg"><label>Reply-to</label><input class="ec-in" id="tReplyTo" value="'+esc(t.reply_to||'')+'"></div>'+
    '<div class="ec-fg"><label>HTML body * <span class="ec-hint">merge: {{first_name}} {{company}} — must include {{unsubscribe_url}}</span></label><textarea class="ec-ta" id="tHtml" style="min-height:200px">'+esc(t.html_body||'<p>Hello {{first_name}},</p>\n\n<p>...</p>\n\n<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>')+'</textarea></div>'+
    '<div class="ec-fg"><label>Plain text (optional — auto-generated if blank)</label><textarea class="ec-ta" id="tText">'+esc(t.text_body||'')+'</textarea></div>'; }
function tNew(){ ecModal('New template',tForm(),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn light" onclick="tPreview()">Preview</button><button class="ec-btn light" onclick="tTest()">Send test to me</button><button class="ec-btn" onclick="tSave()">Save</button>'); }
function tEdit(id){ post(EC.templates,{action:'get',id:id}).done(function(r){ if(r.success){ ecModal('Edit template',tForm(r.template),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn light" onclick="tPreview()">Preview</button><button class="ec-btn light" onclick="tTest()">Send test to me</button><button class="ec-btn" onclick="tSave()">Save</button>'); } }); }
function tPayload(){ return {id:document.getElementById('tId').value,name:document.getElementById('tName').value,subject:document.getElementById('tSubject').value,from_name:document.getElementById('tFromName').value,from_email:document.getElementById('tFromEmail').value,reply_to:document.getElementById('tReplyTo').value,html_body:document.getElementById('tHtml').value,text_body:document.getElementById('tText').value}; }
function tSave(){ post(EC.templates,Object.assign({action:'save'},tPayload())).done(function(r){ if(r.success){ecClose();toast('Saved');tLoad();}else toast(r.message,'error'); }); }
function tDel(id){ Swal.fire({title:'Delete template?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.templates,{action:'delete',id:id}).done(()=>{toast('Deleted');tLoad();}); }); }
function tPreview(){ post(EC.templates,Object.assign({action:'preview'},tPayload())).done(function(r){ if(r.success){ const w=window.open('','_blank'); w.document.write('<h3 style="font-family:sans-serif">Subject: '+esc(r.subject)+'</h3><hr>'+r.html); } }); }
function tTest(){ post(EC.templates,Object.assign({action:'test_send'},tPayload())).done(function(r){ toast(r.message, r.success?'success':'error'); }); }

/* ---------- Campaigns ---------- */
function kLoad(){ post(EC.campaigns,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>Audience</th><th>Status</th><th>Sent/Total</th><th></th></tr></thead><tbody>'; r.rows.forEach(c=>h+='<tr><td>'+esc(c.name)+'</td><td>'+esc(c.audience_name||'')+'</td><td><span class="ec-badge b-'+c.status+'">'+c.status+'</span></td><td>'+c.sent+'/'+c.total+'</td><td><button class="ec-btn light sm" onclick="kEdit('+c.id+')">Edit</button> <button class="ec-btn light sm" onclick="kReview('+c.id+')">Review &amp; launch</button></td></tr>'); h+='</tbody></table>'; document.getElementById('kTable').innerHTML=h; }); }
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
        '<div class="ec-fg"><label>Sending profile <span class="ec-hint">(the subdomain + mailboxes to send from; manage under the Sending tab)</span></label><select class="ec-sel" id="kProfile"><option value="">— use global default —</option>'+optList(_profs,c.sending_profile_id,'id','name')+'</select></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>From name <span class="ec-hint">(blank = use profile)</span></label><input class="ec-in" id="kFromName" value="'+esc(c.from_name||'')+'"></div><div class="ec-fg"><label>From email <span class="ec-hint">(blank = use profile)</span></label><input class="ec-in" id="kFromEmail" value="'+esc(c.from_email||'')+'"></div></div>'+
        '<div class="ec-fg"><label>Reply-to <span class="ec-hint">(blank = use profile)</span></label><input class="ec-in" id="kReplyTo" value="'+esc(c.reply_to||'')+'"></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>Batch size</label><input class="ec-in" id="kBatch" value="'+(c.batch_size||50)+'"></div><div class="ec-fg"><label>Interval between batches (min)</label><input class="ec-in" id="kInterval" value="'+(c.batch_interval_min||10)+'"></div></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>Per-domain limit / run <span class="ec-hint">(0=none)</span></label><input class="ec-in" id="kPerDomain" value="'+(c.per_domain_limit||0)+'"></div><div class="ec-fg"><label>Daily cap <span class="ec-hint">(0=none)</span></label><input class="ec-in" id="kDaily" value="'+(c.daily_cap||0)+'"></div></div>'+
        '<div class="ec-row"><div class="ec-fg"><label>Schedule start <span class="ec-hint">(blank=now)</span></label><input class="ec-in" id="kSched" type="datetime-local"></div><div class="ec-fg"><label><input type="checkbox" id="kWarm" '+(c.warmup_enabled?'checked':'')+'> Warm-up ramp &nbsp; <input type="checkbox" id="kAB" '+(c.ab_enabled?'checked':'')+'> A/B</label></div></div>'+
        '<div id="kVars">'+vh+'</div><button class="ec-btn light sm" onclick="kAddVar()">+ Add A/B variant</button>',
        '<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="kSave()">Save</button>');
}
function kAddVar(){ const i=document.querySelectorAll('.kVarTpl').length; const tplOpts='<option value="">— template —</option>'+_tpls.map(o=>'<option value="'+o.id+'">'+esc(o.name)+'</option>').join(''); const div=document.createElement('div'); div.className='ec-row'; div.style.alignItems='end'; div.innerHTML='<div class="ec-fg"><label>Variant '+String.fromCharCode(65+i)+' template</label><select class="ec-sel kVarTpl">'+tplOpts+'</select></div><div class="ec-fg"><label>Weight</label><input class="ec-in kVarW" value="1"></div>'; document.getElementById('kVars').appendChild(div); }
function kSave(){ const vars=[]; document.querySelectorAll('.kVarTpl').forEach((el,i)=>{ if(el.value) vars.push({label:String.fromCharCode(65+i),template_id:el.value,weight:document.querySelectorAll('.kVarW')[i].value||1}); }); if(!vars.length){toast('Add at least one template variant','error');return;}
    post(EC.campaigns,{action:'save',id:document.getElementById('kId').value,name:document.getElementById('kName').value,audience_id:document.getElementById('kAud').value,sending_profile_id:document.getElementById('kProfile').value,from_name:document.getElementById('kFromName').value,from_email:document.getElementById('kFromEmail').value,reply_to:document.getElementById('kReplyTo').value,batch_size:document.getElementById('kBatch').value,batch_interval_min:document.getElementById('kInterval').value,per_domain_limit:document.getElementById('kPerDomain').value,daily_cap:document.getElementById('kDaily').value,warmup_enabled:document.getElementById('kWarm').checked?1:0,ab_enabled:document.getElementById('kAB').checked?1:0,scheduled_at:document.getElementById('kSched').value,variants:JSON.stringify(vars)}).done(function(r){ if(r.success){ecClose();toast('Saved');kLoad();}else toast(r.message,'error'); }); }
function kReview(id){ post(EC.campaigns,{action:'materialise',id:id}).done(function(m){ post(EC.campaigns,{action:'preview_count',id:id}).done(function(p){
    ecModal('Review &amp; launch','<p>Materialised <b>'+(m.materialised||0)+'</b> new recipients this run.</p><p>Total queued to send (after suppression &amp; dedup): confirm below.</p><p class="ec-hint">Suppressed and already-contacted addresses are excluded automatically.</p>',
    '<button class="ec-btn light" onclick="ecClose()">Close</button><button class="ec-btn" onclick="kLaunch('+id+')">Launch now</button>'); }); }); }
function kLaunch(id){ post(EC.campaigns,{action:'launch',id:id}).done(function(r){ if(r.success){ecClose();toast('Campaign '+r.status);kLoad();dashLoad();}else toast(r.message,'error'); }); }
function kCtl(id,act){ post(EC.campaigns,{action:act,id:id}).done(()=>{toast(act+'d');dashLoad();kLoad();}); }

/* ---------- Sending profiles ---------- */
function pLoad(){ post(EC.profiles,{action:'list'}).done(function(r){ if(!r.success){toast(r.message,'error');return;} let h='<table class="ec-tbl"><thead><tr><th>Name</th><th>From</th><th>SMTP</th><th>IMAP</th><th>Active</th><th></th></tr></thead><tbody>'; (r.rows||[]).forEach(p=>h+='<tr><td>'+esc(p.name)+'</td><td>'+esc(p.from_email)+'</td><td>'+esc(p.smtp_host||'')+(p.smtp_pass_set==1?' 🔑':'')+'</td><td>'+esc(p.imap_host||'')+(p.imap_pass_set==1?' 🔑':'')+'</td><td>'+(p.active==1?'yes':'no')+'</td><td><button class="ec-btn light sm" onclick="pEdit('+p.id+')">Edit</button> <button class="ec-btn danger sm" onclick="pDel('+p.id+')">Delete</button></td></tr>'); h+='</tbody></table>'; document.getElementById('pTable').innerHTML=h; }); }
function pForm(p){ p=p||{}; return '<input type="hidden" id="pId" value="'+(p.id||'')+'">'+
    '<div class="ec-row"><div class="ec-fg"><label>Profile name *</label><input class="ec-in" id="pName" value="'+esc(p.name||'')+'" placeholder="Brokers DE — mail.brokers.tld"></div><div class="ec-fg"><label>Active</label><select class="ec-sel" id="pActive"><option value="1"'+(p.active!=0?' selected':'')+'>Active</option><option value="0"'+(p.active==0?' selected':'')+'>Inactive</option></select></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>From name</label><input class="ec-in" id="pFromName" value="'+esc(p.from_name||'')+'"></div><div class="ec-fg"><label>From email * <span class="ec-hint">(this sets the sending subdomain)</span></label><input class="ec-in" id="pFromEmail" value="'+esc(p.from_email||'')+'" placeholder="news@mail.brokers.tld"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Reply-to mailbox</label><input class="ec-in" id="pReplyTo" value="'+esc(p.reply_to||'')+'" placeholder="replies@mail.brokers.tld"></div><div class="ec-fg"><label>Bounce address (VERP) <span class="ec-hint">(e.g. bounce@mail.brokers.tld)</span></label><input class="ec-in" id="pBounce" value="'+esc(p.bounce_address||'')+'"></div></div>'+
    '<h4 style="margin:14px 0 6px">SMTP (sending)</h4>'+
    '<div class="ec-row"><div class="ec-fg"><label>Host</label><input class="ec-in" id="pSmtpHost" value="'+esc(p.smtp_host||'')+'"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="pSmtpPort" value="'+(p.smtp_port||587)+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Security</label><select class="ec-sel" id="pSmtpSec"><option value="tls"'+((p.smtp_security||"tls")=="tls"?" selected":"")+'>STARTTLS (587)</option><option value="ssl"'+(p.smtp_security=="ssl"?" selected":"")+'>SSL (465)</option><option value="none"'+(p.smtp_security=="none"?" selected":"")+'>None</option></select></div><div class="ec-fg"><label>Username</label><input class="ec-in" id="pSmtpUser" value="'+esc(p.smtp_user||'')+'"></div></div>'+
    '<div class="ec-fg"><label>SMTP password <span class="ec-hint">(blank = keep; currently '+(p.smtp_pass_set==1?'set':'not set')+')</span></label><input class="ec-in" id="pSmtpPass" type="password" autocomplete="new-password"></div>'+
    '<h4 style="margin:14px 0 6px">IMAP (replies &amp; bounces)</h4>'+
    '<div class="ec-row"><div class="ec-fg"><label>Host</label><input class="ec-in" id="pImapHost" value="'+esc(p.imap_host||'')+'"></div><div class="ec-fg"><label>Port</label><input class="ec-in" id="pImapPort" value="'+(p.imap_port||993)+'"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Username</label><input class="ec-in" id="pImapUser" value="'+esc(p.imap_user||'')+'"></div><div class="ec-fg"><label>IMAP password <span class="ec-hint">(blank = keep; currently '+(p.imap_pass_set==1?'set':'not set')+')</span></label><input class="ec-in" id="pImapPass" type="password" autocomplete="new-password"></div></div>'+
    '<div class="ec-row"><div class="ec-fg"><label>Reply mailbox (folder)</label><input class="ec-in" id="pReplyMbx" value="'+esc(p.reply_mailbox||'INBOX')+'"></div><div class="ec-fg"><label>Bounce mailbox (folder)</label><input class="ec-in" id="pBounceMbx" value="'+esc(p.bounce_mailbox||'')+'"></div></div>'; }
function pNew(){ ecModal('New sending profile',pForm(),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="pSave()">Save</button>'); }
function pEdit(id){ post(EC.profiles,{action:'get',id:id}).done(function(r){ if(r.success) ecModal('Edit sending profile',pForm(r.profile),'<button class="ec-btn light" onclick="ecClose()">Cancel</button><button class="ec-btn" onclick="pSave()">Save</button>'); }); }
function pSave(){ const d={action:'save',id:document.getElementById('pId').value,name:document.getElementById('pName').value,active:document.getElementById('pActive').value,from_name:document.getElementById('pFromName').value,from_email:document.getElementById('pFromEmail').value,reply_to:document.getElementById('pReplyTo').value,bounce_address:document.getElementById('pBounce').value,smtp_host:document.getElementById('pSmtpHost').value,smtp_port:document.getElementById('pSmtpPort').value,smtp_security:document.getElementById('pSmtpSec').value,smtp_user:document.getElementById('pSmtpUser').value,smtp_pass:document.getElementById('pSmtpPass').value,imap_host:document.getElementById('pImapHost').value,imap_port:document.getElementById('pImapPort').value,imap_user:document.getElementById('pImapUser').value,imap_pass:document.getElementById('pImapPass').value,reply_mailbox:document.getElementById('pReplyMbx').value,bounce_mailbox:document.getElementById('pBounceMbx').value}; post(EC.profiles,d).done(function(r){ if(r.success){ecClose();toast('Saved');pLoad();}else toast(r.message,'error'); }); }
function pDel(id){ Swal.fire({title:'Delete profile?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626'}).then(x=>{ if(x.isConfirmed) post(EC.profiles,{action:'delete',id:id}).done(()=>{toast('Deleted');pLoad();}); }); }

/* ---------- Reports ---------- */
function rInit(){ post(EC.campaigns,{action:'list'}).done(function(r){ const sel=document.getElementById('rCampaign'); sel.innerHTML='<option value="">Choose a campaign…</option>'+(r.rows||[]).map(c=>'<option value="'+c.id+'">'+esc(c.name)+'</option>').join(''); }); }
function rLoad(){ const id=document.getElementById('rCampaign').value; if(!id){document.getElementById('rBody').innerHTML='';return;} post(EC.campaigns,{action:'report',id:id}).done(function(r){ if(!r.success){toast(r.message,'error');return;} const f=r.funnel; let h='<div class="ec-statrow">'+stat(f.sent,'sent')+stat(f.open,'opened')+stat(f.click,'clicked')+stat(f.reply,'replied')+stat(f.visit,'visits')+stat(f.bounce,'bounced')+stat(f.unsubscribe,'unsub')+'</div>';
    h+='<h3>A/B variants</h3><table class="ec-tbl"><thead><tr><th>Variant</th><th>Recipients</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Replied</th></tr></thead><tbody>';
    (r.variants||[]).forEach(v=>h+='<tr><td>'+esc(v.label)+'</td><td>'+v.recips+'</td><td>'+(v.sent||0)+'</td><td>'+(v.opened||0)+'</td><td>'+(v.clicked||0)+'</td><td>'+(v.replied||0)+'</td></tr>');
    h+='</tbody></table><div style="margin-top:10px"><button class="ec-btn light sm" onclick="rRecips('+id+')">Show recipients</button></div><div id="rRecips"></div>';
    document.getElementById('rBody').innerHTML=h; }); }
function rRecips(id){ post(EC.campaigns,{action:'recipients',id:id}).done(function(r){ let h='<table class="ec-tbl"><thead><tr><th>Email</th><th>Status</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Replied</th></tr></thead><tbody>'; (r.rows||[]).forEach(x=>h+='<tr><td>'+esc(x.email)+'</td><td>'+esc(x.status)+'</td><td>'+esc(x.sent_at||'')+'</td><td>'+(x.opened_at?'✓':'')+'</td><td>'+(x.first_click_at?'✓':'')+'</td><td>'+(x.replied_at?'✓':'')+'</td></tr>'); h+='</tbody></table>'; document.getElementById('rRecips').innerHTML=h; }); }

/* ---------- Settings ---------- */
function sLoad(){ post(EC.settings,{action:'get'}).done(function(r){ if(!r.success){toast(r.message||'no access','error');return;} const s=r.settings||{}; ['smtp_host','smtp_port','smtp_security','smtp_user','default_from_name','default_from_email','default_reply_to','imap_host','imap_port','imap_user','imap_bounce_mailbox','warmup_json','daily_cap','track_base'].forEach(k=>{ const el=document.getElementById('s_'+k); if(el&&s[k]!=null) el.value=s[k]; }); document.getElementById('s_smtp_pass_set').textContent=s.smtp_pass_set?'set':'not set'; document.getElementById('s_imap_pass_set').textContent=s.imap_pass_set?'set':'not set'; post(EC.settings,{action:'imap_check'}).done(x=>{ document.getElementById('s_imap_status').textContent='IMAP ext: '+x.imap_ext; }); }); }
function sSave(){ const d={action:'save'}; ['smtp_host','smtp_port','smtp_security','smtp_user','smtp_pass','default_from_name','default_from_email','default_reply_to','imap_host','imap_port','imap_user','imap_pass','imap_bounce_mailbox','warmup_json','daily_cap','track_base'].forEach(k=>{ const el=document.getElementById('s_'+k); if(el) d[k]=el.value; }); post(EC.settings,d).done(function(r){ toast(r.success?'Saved':(r.message||'Failed'), r.success?'success':'error'); if(r.success){document.getElementById('s_smtp_pass').value='';document.getElementById('s_imap_pass').value='';sLoad();} }); }

dashLoad();
</script>
</body>
</html>
