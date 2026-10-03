<?php
require_once 'config.php';
requireLogin();
if (!hasModulePermission('crm.view') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}
require_once 'lib/crm_core.php';
$cConn = crm_db();
crm_ensure_schema($cConn);
$cConn->close();

$currentUser = getCurrentUser();
$currentPage = 'crm';
$canManage = hasModulePermission('crm.manage') || isAdmin();
$pkvVisible = crm_pkv_visible();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM — TEN Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <link rel="stylesheet" href="css/crm.css?v=<?php echo @filemtime('css/crm.css'); ?>">
    <style>
        .swal2-container { z-index: 11000 !important; }
        .swal2-styled.swal2-confirm { background: #1f4e79 !important; border-radius: 3px !important; font-weight: 600 !important; }
        .swal2-styled.swal2-cancel { border-radius: 3px !important; font-weight: 600 !important; }
        .swal2-popup { font-family: "Public Sans", system-ui, sans-serif !important; border-radius: 5px !important; }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content" style="padding:0;display:flex;flex-direction:column;height:100vh;overflow:hidden;">
<?php include 'includes/header.php'; ?>

<div class="crm-app" id="crmApp">

    <div class="crm-topbar">
        <div class="crm-titlerow">
            <h1 class="crm-title">CRM<span class="sub" id="crmSubtitle">Dashboard</span></h1>
            <div class="spacer"></div>
            <div id="crmTopActions"></div>
        </div>
        <div class="crm-tabs">
            <button class="crm-tab active" data-tab="overview" onclick="crmTab('overview')">Overview</button>
            <button class="crm-tab" data-tab="projects" onclick="crmTab('projects')">Projects</button>
            <button class="crm-tab" data-tab="contacts" onclick="crmTab('contacts')">All contacts</button>
            <button class="crm-tab" data-tab="settings" onclick="crmTab('settings')">Settings</button>
        </div>
    </div>

    <div class="crm-view" id="view-overview"></div>
    <div class="crm-view" id="view-projects" style="display:none;"></div>
    <div class="crm-view" id="view-contacts" style="display:none;"></div>
    <div class="crm-view" id="view-settings" style="display:none;"></div>

</div>
</div>

<div class="crm-overlay" id="crmOverlay" onclick="crmCloseDrawer()"></div>
<div class="crm-drawer" id="crmDrawer" aria-hidden="true"></div>

<div class="crm-modal-ov" id="crmModalOv">
    <div class="crm-modal" id="crmModal">
        <div class="crm-modal-head"><h3 id="crmModalTitle"></h3><button class="crm-x" onclick="crmCloseModal()"><i class="fas fa-times"></i></button></div>
        <div class="crm-modal-body" id="crmModalBody"></div>
        <div class="crm-modal-foot" id="crmModalFoot"></div>
    </div>
</div>

<!-- Wide contact modal -->
<div class="crm-modal-ov" id="crmBigOv">
    <div class="crm-modal crm-modal-wide" id="crmBig">
        <div class="crm-modal-head">
            <div style="flex:1;min-width:0;"><h3 id="crmBigTitle"></h3><div class="crm-dim" id="crmBigSub" style="font-size:12.5px;"></div></div>
            <button class="crm-x" onclick="crmCloseBig()"><i class="fas fa-times"></i></button>
        </div>
        <div style="display:flex;gap:18px;border-bottom:1px solid var(--line);padding:0 22px;" id="crmBigTabs"></div>
        <div class="crm-modal-body" id="crmBigBody" style="min-height:280px;"></div>
        <div class="crm-modal-foot" id="crmBigFoot"></div>
    </div>
</div>

<script>
// ════════════════════════════════════════════════════════════════════════
// CRM front-end (rebuilt). See lib/crm_core.php + ajax/crm.php.
// ════════════════════════════════════════════════════════════════════════
const AJAX = 'ajax/crm.php';
const CAN_MANAGE = <?php echo $canManage ? 'true' : 'false'; ?>;
const PKV_VISIBLE = <?php echo $pkvVisible ? 'true' : 'false'; ?>;

const S = {
    tab: 'overview',
    vocab: { categories: [], countries: [] },
    users: [],
    pipelines: [],
    contacts: { q:'', category:'', country:'', has_email:false, hide_suppressed:false, page:1, per:50, total:0, sort:'created', dir:'desc', f_name:'', f_company:'', f_email:'' },
    sel: { ids:new Set(), allFilter:false },
    _pageIds: [],
    leads: { q:'', pipeline_id:'', status:'', page:1, per:50, total:0 },
    board: null,
    currentPipeline: null,
    drawerContactId: null,
};

async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) {
        if (v === undefined || v === null) continue;
        fd.append(k, v);
    }
    try {
        const res = await fetch(AJAX, { method:'POST', body: fd });
        const txt = await res.text();
        try { return JSON.parse(txt); }
        catch(e){ return { success:false, message:'Server error'+(res.status?' ('+res.status+')':'')+': '+txt.replace(/<[^>]+>/g,' ').trim().slice(0,240) }; }
    } catch(e){ return { success:false, message:'Network error: '+e.message }; }
}
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function money(v){ if(v===null||v===''||v===undefined) return ''; const n=parseFloat(v); if(isNaN(n)) return ''; return '€'+n.toLocaleString(undefined,{maximumFractionDigits:0}); }
function toast(msg, ok=true){ Swal.fire({toast:true,position:'top-end',timer:2600,showConfirmButton:false,icon:ok?'success':'error',title:msg}); }
function when(dt){
    if(!dt) return '';
    const d = new Date(String(dt).replace(' ','T'));
    if(isNaN(d)) return esc(dt);
    return d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'}) + ' ' + d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'});
}
function dateOnly(dt){ if(!dt) return ''; const d=new Date(String(dt).replace(' ','T')); return isNaN(d)?esc(dt):d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'}); }

// ── Tabs ────────────────────────────────────────────────────────────────
function crmTab(tab){
    S.tab = tab;
    document.querySelectorAll('.crm-tab').forEach(b=>b.classList.toggle('active', b.dataset.tab===tab));
    ['overview','projects','contacts','settings'].forEach(t=>{ const v=document.getElementById('view-'+t); if(v) v.style.display = (t===tab)?'':'none'; });
    document.getElementById('crmSubtitle').textContent = {overview:'Command centre',projects:'Projects',contacts:'All contacts',settings:'Settings'}[tab];
    document.getElementById('crmTopActions').innerHTML = topActions(tab);
    if(tab==='overview') loadOverview();
    if(tab==='projects') loadProjects();
    if(tab==='contacts') loadContacts();
    if(tab==='settings') loadSettings();
}
function topActions(tab){
    if(!CAN_MANAGE) return '';
    if(tab==='contacts') return `<button class="crm-btn primary" onclick="openContactForm()"><i class="fas fa-plus"></i> New contact</button>`;
    if(tab==='projects') return `<button class="crm-btn primary" onclick="openPipelineForm()"><i class="fas fa-plus"></i> New project</button>`;
    return '';
}

// ── Bootstrap caches ──────────────────────────────────────────────────────
async function bootstrap(){
    const [v,u,p] = await Promise.all([api('vocab'), api('users_list'), api('pipelines_list')]);
    if(v.success) S.vocab = v.data;
    if(u.success) S.users = u.data.users;
    if(p.success) S.pipelines = p.data.pipelines;
}
async function loadPipelinesCache(){ const p = await api('pipelines_list'); if(p.success) S.pipelines = p.data.pipelines; }
async function ensureVocab(){ if(!S.vocab.countries || !S.vocab.countries.length){ const v = await api('vocab'); if(v.success) S.vocab = v.data; } }
function openCategoriesManager(){
    const list = S.vocab.categories.length ? S.vocab.categories.map(c=>`<div style="padding:7px 12px;border-bottom:1px solid var(--line-2);">${esc(c)}</div>`).join('') : '<div class="crm-empty" style="padding:16px;">No categories yet.</div>';
    openModal('Contact categories', `
      <div class="crm-note">Categories are shared with the Email Campaign Manager — anything you add here appears there too.</div>
      <div class="crm-field" style="margin-top:12px;"><label>New category</label>
        <div style="display:flex;gap:8px;"><input class="crm-input" id="newcat" placeholder="e.g. Hotels" onkeydown="if(event.key==='Enter')saveNewCategory()"><button class="crm-btn primary" onclick="saveNewCategory()">Add</button></div></div>
      <div class="crm-section-label">Existing categories</div>
      <div style="max-height:240px;overflow:auto;border:1px solid var(--line);border-radius:3px;">${list}</div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Done</button>`);
}
async function saveNewCategory(){
    const name = document.getElementById('newcat').value.trim();
    if(!name){ toast('Enter a category name',false); return; }
    const r = await api('vocab_add', { kind:'category', name });
    if(!r.success){ toast(r.message,false); return; }
    if(S.vocab.categories.indexOf(name)<0){ S.vocab.categories.push(name); S.vocab.categories.sort(); }
    toast('Category added'); openCategoriesManager();
    if(S.tab==='contacts') loadContacts(true);
}

// ════════════════════════════════════════════════════════════════════════
// OVERVIEW
// ════════════════════════════════════════════════════════════════════════
function kpiNum(n){ return (n===null||n===undefined)?'—':(typeof n==='number'?n.toLocaleString():n); }
function areaCard(a){
    const stats = (a.stats||[]).map(s=>`<div style="flex:1;"><div class="crm-stat-num" style="font-size:20px;">${kpiNum(s[1])}</div><div class="crm-stat-label">${esc(s[0])}</div></div>`).join('');
    const openAct = a.pipeline ? `onclick="openBoard('${a.pipeline}')" ` : `onclick="window.location='${a.url}'" `;
    return `<div class="crm-panel crm-row-click" ${openAct} style="margin:0;">
        <div class="crm-panel-head"><i class="fas ${esc(a.icon)}" style="color:var(--accent);"></i><h3 style="letter-spacing:0;text-transform:none;font-size:14px;color:var(--ink);">${esc(a.name)}</h3><div class="spacer"></div>${a.pipeline?'<span class="crm-badge is-muted">live board</span>':`<i class="fas fa-arrow-up-right-from-square crm-dim" style="font-size:11px;"></i>`}</div>
        <div class="crm-panel-body" style="display:flex;gap:10px;">${stats}</div>
    </div>`;
}
function projCardOv(p){
    const chips = (p.states||[]).slice(0,6).map(s=>`<span class="crm-badge ${s.won?'is-won':(s.lost?'is-lost':'is-muted')}" style="margin:0 6px 6px 0;"><span class="dot"></span>${esc(s.label)} ${s.count}</span>`).join('');
    const srcLabel = {pkv:'PhiCRM / GPHI · read-only', wne:'WNE ATS · read-only', native:'CRM project'}[p.source]||'';
    return `<div class="crm-panel crm-row-click" onclick="goToProject('${p.key}')" style="margin:0;">
        <div class="crm-panel-head"><i class="fas ${p.source==='pkv'?'fa-heart-pulse':(p.source==='wne'?'fa-user-tie':'fa-diagram-project')}" style="color:var(--accent);"></i>
          <h3 style="letter-spacing:0;text-transform:none;font-size:14px;color:var(--ink);">${esc(p.name)}</h3>
          <div class="spacer"></div><span class="crm-badge is-muted">${esc(srcLabel)}</span></div>
        <div class="crm-panel-body"><div style="font-size:22px;font-weight:700;">${(p.count||0).toLocaleString()} <span style="font-size:12px;font-weight:400;color:var(--ink-3);">contacts</span></div>
          <div style="margin-top:10px;">${chips||'<span class="crm-dim" style="font-size:12px;">No status breakdown</span>'}</div></div>
    </div>`;
}
async function loadOverview(){
    const el = document.getElementById('view-overview');
    el.innerHTML = '<div class="crm-spin"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    const [r, pr] = await Promise.all([api('overview'), api('projects_list')]);
    if(!r.success){ el.innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    const d = r.data;
    const projects = pr.success ? pr.data.projects : [];
    const stat = (label,num,sub)=>`<div class="crm-stat"><div class="crm-stat-label">${label}</div><div class="crm-stat-num">${num}</div>${sub?`<div class="crm-stat-sub">${sub}</div>`:''}</div>`;
    let html = `<div class="crm-stats">
        ${stat('Contacts', (d.total_contacts||0).toLocaleString(), (d.with_email||0).toLocaleString()+' with email')}
        ${stat('Projects', projects.length, 'across every tool')}
        ${d.pkv?stat('PKV open', d.pkv.open||0, 'PhiCRM / GPHI'):''}
        ${stat('Emails sent (30d)', (d.sent_30d||0).toLocaleString(), '')}
        ${stat('Suppressed', (d.suppressed||0).toLocaleString(), 'opted out / bounced')}
    </div>`;

    html += `<p class="crm-section-label" style="margin:4px 0 12px;">Projects — who's in them &amp; what state they're in</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:24px;">
      ${projects.length ? projects.map(projCardOv).join('') : '<div class="crm-empty">No projects.</div>'}</div>`;

    html += `<div class="crm-grid-2">`;
    // Recent email activity
    html += `<div class="crm-panel"><div class="crm-panel-head"><h3>Recent email activity</h3><div class="spacer"></div><a class="crm-link" href="module-email-campaigns.php">Email tool</a></div><div class="crm-panel-body flush">`;
    if((d.recent_email||[]).length){
        html += `<table class="crm-table"><tbody>`;
        d.recent_email.forEach(e=>{
            const who = (e.cname||'').trim() || e.email || '—';
            const click = e.contact_id ? `class="crm-row-click" onclick="openContact(${e.contact_id})"` : '';
            html += `<tr ${click}><td><div class="crm-strong">${esc(e.subject||'(no subject)')}</div><div class="crm-dim" style="font-size:12px;">${esc(who)}${e.campaign_name?' · '+esc(e.campaign_name):''}</div></td><td class="num crm-dim" style="white-space:nowrap;">${dateOnly(e.sent_at)}</td></tr>`;
        });
        html += `</tbody></table>`;
    } else { html += `<div class="crm-empty" style="padding:28px;"><i class="far fa-paper-plane"></i>No emails sent yet.</div>`; }
    html += `</div></div>`;

    // Tasks due
    html += `<div class="crm-panel"><div class="crm-panel-head"><h3>Tasks &amp; follow-ups due</h3></div><div class="crm-panel-body flush">`;
    if((d.tasks||[]).length){
        html += `<table class="crm-table"><tbody>`;
        d.tasks.forEach(t=>{
            html += `<tr class="crm-row-click" onclick="openContact(${t.contact_id})"><td><div class="crm-strong">${esc(t.subject||t.type)}</div><div class="crm-dim" style="font-size:12px;">${esc((t.cname||'').trim())}${t.company?' · '+esc(t.company):''}</div></td><td class="num crm-dim">${dateOnly(t.due_at)}</td></tr>`;
        });
        html += `</tbody></table>`;
    } else {
        html += `<div class="crm-empty" style="padding:28px;"><i class="far fa-circle-check"></i>Nothing due. Add a task from any contact.</div>`;
    }
    html += `</div></div></div>`;
    el.innerHTML = html;
}

// ════════════════════════════════════════════════════════════════════════
// PROJECTS WORKSPACE  (pick a project → its people + their status)
// ════════════════════════════════════════════════════════════════════════
const PROJ = { list:[], current:null, meta:null, statuses:[], contacts:[], filter:'', q:'' };
const SRC_GROUPS = [
    { src:'native', label:'CRM projects', icon:'fa-diagram-project' },
    { src:'pkv',    label:'Health insurance', icon:'fa-heart-pulse' },
    { src:'wne',    label:'Recruitment', icon:'fa-user-tie' },
    { src:'email',  label:'Email', icon:'fa-paper-plane' },
];
async function loadProjects(){
    const r = await api('projects_list');
    PROJ.list = r.success ? r.data.projects : [];
    if(PROJ.view==='detail' && PROJ.current && PROJ.list.some(p=>p.key===PROJ.current)) openProject(PROJ.current);
    else renderProjectsGrid();
}
function projName(key){ const p = PROJ.list.find(x=>x.key===key); return p?p.name:''; }
function projCard(p){
    const chips = (p.states||[]).slice(0,5).map(s=>`<span class="crm-badge ${s.won?'is-won':(s.lost?'is-lost':'is-muted')}" style="margin:0 5px 5px 0;"><span class="dot"></span>${esc(s.label)} ${s.count}</span>`).join('');
    const icon = p.source==='pkv'?'fa-heart-pulse':(p.source==='wne'?'fa-user-tie':(p.source==='email'?'fa-paper-plane':'fa-diagram-project'));
    const crumb = p.parent ? `<span class="crm-dim" style="font-size:11.5px;"><i class="fas fa-angle-right" style="font-size:9px;"></i> ${esc(projName(p.parent))}</span>`
        : (p.sub ? `<span class="crm-dim" style="font-size:11.5px;">${esc(p.sub)}</span>` : '');
    return `<div class="crm-pcard crm-row-click" onclick="openProject('${p.key}')">
        <div class="crm-pcard-top"><i class="fas ${icon}" style="color:var(--accent);"></i><span class="crm-pcard-name">${esc(p.name)}</span>${p.editable?'':'<span class="crm-badge is-muted" style="margin-left:auto;">read-only</span>'}</div>
        ${crumb}
        <div class="crm-pcard-count">${(p.count||0).toLocaleString()} <span>contact${p.count==1?'':'s'}</span></div>
        <div style="margin-top:8px;min-height:22px;">${chips}</div>
    </div>`;
}
function renderProjectsGrid(){
    PROJ.view='grid';
    const el = document.getElementById('view-projects');
    el.style.padding='20px 24px';
    if(!PROJ.list.length){ el.innerHTML = '<div class="crm-empty" style="margin-top:40px;"><i class="fas fa-folder-open"></i>No projects yet. Use “New project” (top-right) to create one.</div>'; return; }
    let html='';
    SRC_GROUPS.forEach(g=>{
        const items = PROJ.list.filter(p=>p.source===g.src);
        if(!items.length) return;
        html += `<p class="crm-section-label" style="margin:6px 0 12px;"><i class="fas ${g.icon}"></i> ${g.label}</p>
          <div class="crm-pgrid">${items.map(projCard).join('')}</div>`;
    });
    el.innerHTML = html;
}
// From Overview (or anywhere off the Projects tab): switch to Projects, then open it.
function goToProject(key){
    PROJ.current = key; PROJ.view = 'detail';
    if(S.tab==='projects') openProject(key); else crmTab('projects');
}
async function openProject(key){
    PROJ.current = key; PROJ.filter=''; PROJ.view='detail';
    const el = document.getElementById('view-projects');
    el.style.padding='20px 24px';
    el.innerHTML = '<div class="crm-spin" style="margin-top:60px;"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    const r = await api('project_view', { key });
    if(!r.success){ el.innerHTML = `<div style="margin-bottom:12px;"><a class="crm-link" onclick="renderProjectsGrid()"><i class="fas fa-arrow-left"></i> All projects</a></div><div class="crm-empty">${esc(r.message)}</div>`; return; }
    PROJ.meta = r.data.meta; PROJ.statuses = r.data.statuses; PROJ.contacts = r.data.contacts;
    renderProjectDetail();
}
function projFilter(k){ PROJ.filter=k; renderProjectDetail(); }
function renderProjectDetail(){
    const m = PROJ.meta, el = document.getElementById('view-projects');
    const counts = {}; PROJ.contacts.forEach(c=> counts[c.status]=(counts[c.status]||0)+1);
    const srcBadge = {pkv:'PhiCRM / GPHI · read-only', wne:'WNE ATS · read-only', email:'Email Campaign Manager · read-only', native:'CRM project · editable'}[m.source]||'';
    let actions='';
    if(m.editable && CAN_MANAGE){
        actions = `<button class="crm-btn" onclick="projAddContact()"><i class="fas fa-user-plus"></i> Add contact</button>
                   <button class="crm-btn" onclick="openPipelineStages(${m.key.split(':')[1]})"><i class="fas fa-sliders"></i> Statuses</button>
                   <button class="crm-btn" onclick="openSubProjectForm(${m.key.split(':')[1]})"><i class="fas fa-plus"></i> Sub-project</button>
                   <button class="crm-btn icon" onclick="confirmDeleteProject('${m.key}')" title="Delete project"><i class="fas fa-trash crm-dim"></i></button>`;
    }
    if(m.url){ const ext = /^https?:/i.test(m.url); actions += ` <a class="crm-btn primary" href="${m.url}" ${ext?'target="_blank" rel="noopener"':''}><i class="fas fa-arrow-up-right-from-square"></i> ${esc(m.open_label||'Open in tool')}</a>`; }
    let chips = `<span class="crm-pchip ${PROJ.filter===''?'active':''}" onclick="projFilter('')">All ${PROJ.contacts.length}</span>`;
    PROJ.statuses.forEach(s=>{ const n=counts[s.key]||0; if(n>0||m.editable) chips += `<span class="crm-pchip ${PROJ.filter===String(s.key)?'active':''} ${s.is_won?'won':(s.is_lost?'lost':'')}" onclick="projFilter('${esc(String(s.key))}')">${esc(s.label)} ${n}</span>`; });
    el.innerHTML = `
      <div style="margin-bottom:12px;"><a class="crm-link" onclick="renderProjectsGrid()"><i class="fas fa-arrow-left"></i> All projects</a></div>
      <div class="crm-projhead">
        <div style="flex:1;min-width:0;"><div class="crm-projtitle">${esc(m.name)}</div><div class="crm-projsub">${esc(m.sub||'')}</div></div>
        <span class="crm-badge is-muted">${esc(srcBadge)}</span>
      </div>
      <div class="crm-pchips" style="margin:6px 0 12px;">${chips}</div>
      ${actions?`<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;justify-content:flex-end;">${actions}</div>`:''}
      <div id="projTable"></div>`;
    renderProjTable();
}
function renderProjTable(){
    const m = PROJ.meta;
    const rows = PROJ.contacts.filter(c=> PROJ.filter===''||String(c.status)===String(PROJ.filter));
    const statusCell = (c)=>{
        if(m.editable && CAN_MANAGE){
            return `<select class="crm-select" style="padding:3px 6px;font-size:12px;width:auto;" onclick="event.stopPropagation()" onchange="projMove(${c.lead_id}, this.value)">`
                + PROJ.statuses.map(s=>`<option value="${s.key}" ${String(c.status)===String(s.key)?'selected':''}>${esc(s.label)}</option>`).join('')+`</select>`;
        }
        const s = PROJ.statuses.find(x=>x.key===c.status);
        return `<span class="crm-badge ${s&&s.is_won?'is-won':(s&&s.is_lost?'is-lost':'is-open')}"><span class="dot"></span>${esc(c.status_label||c.status)}</span>`;
    };
    // Columns are defined per project by the backend (meta.fields).
    const fields = m.fields || [
        {key:'name',label:'Name',filter:true,sort:true},{key:'email',label:'Email',filter:true,sort:true},{key:'status',label:'Status',filter:true,sort:true,kind:'status'}
    ];
    const cols = fields.map(f=>{
        let col;
        if(f.kind==='status') col = {key:f.key, label:f.label, filter:f.filter, sort:f.sort, val:c=>c.status_label||'', cell:statusCell};
        else if(f.kind==='date') col = {key:f.key, label:f.label, filter:f.filter, sort:f.sort, val:c=>c[f.key]||'', cell:c=>`<span class="crm-dim">${c[f.key]?when(c[f.key]):'—'}</span>`};
        else if(f.key==='name') col = {key:f.key, label:f.label, filter:f.filter, sort:f.sort, val:c=>c.name||'', cell:c=>`<span class="crm-strong">${esc(c.name)||'<span class=crm-dim>—</span>'}</span>`};
        else col = {key:f.key, label:f.label, filter:f.filter, sort:f.sort, val:c=>c[f.key]||'', cell:c=> (c[f.key]!==undefined&&c[f.key]!=='')?`<span class="crm-dim">${esc(c[f.key])}</span>`:'<span class="crm-dim">—</span>'};
        if(f.options) col.filterOptions = f.options;
        return col;
    });
    if(m.editable && CAN_MANAGE) cols.push({key:'act', label:'', num:true, cell:c=>`<button class="crm-btn sm danger" onclick="event.stopPropagation();projRemove(${c.lead_id})" title="Remove from project"><i class="fas fa-xmark"></i></button>`});
    makeTable(document.getElementById('projTable'), { cols, rows, pageSize:25, empty:'No contacts in this view.',
        onRow:(c)=>{ if(c.kind==='native') openContact(c.contact_id); else projPersonInfo(c.row_id); } });
}

// ── Reusable data table: sortable + per-column filter + pagination + page-size ──
function makeTable(mount, opts){ mount._dt = { sortKey:null, sortDir:1, filters:{}, page:1, pageSize:opts.pageSize||25, opts }; renderDT(mount); }
function dtWrap(node){ let n=node; while(n && !n._dt) n=n.parentElement; return n; }
function dtFiltered(st){
    let rows = st.opts.rows.slice(); const cols = st.opts.cols;
    Object.entries(st.filters).forEach(([k,v])=>{ if(!v) return; const col=cols.find(c=>c.key===k); if(!col||!col.val) return;
        const q=v.toLowerCase(); rows=rows.filter(r=> String(col.val(r)).toLowerCase().includes(q)); });
    if(st.sortKey){ const col=cols.find(c=>c.key===st.sortKey); if(col&&col.val){ rows.sort((a,b)=> String(col.val(a)).localeCompare(String(col.val(b)),undefined,{numeric:true})*st.sortDir); } }
    return rows;
}
function renderDT(mount){
    const st = mount._dt, cols = st.opts.cols;
    const all = dtFiltered(st); mount._rows = all;
    const total = all.length;
    const pageSize = st.pageSize==='All' ? (total||1) : st.pageSize;
    const pages = Math.max(1, Math.ceil(total/pageSize));
    if(st.page>pages) st.page=pages;
    const start=(st.page-1)*pageSize, pageRows = all.slice(start, start+pageSize);
    const arrow = c => st.sortKey===c.key ? ` <i class="fas fa-caret-${st.sortDir>0?'up':'down'}"></i>` : (c.sort?' <i class="fas fa-sort" style="opacity:.3;"></i>':'');
    const head = cols.map(c=>`<th class="${c.num?'num ':''}${c.sort?'crm-row-click':''}" ${c.sort?`onclick="dtSort(this,'${c.key}')"`:''} style="user-select:none;">${esc(c.label)}${arrow(c)}</th>`).join('');
    const filt = cols.map(c=>{
        if(!c.filter) return '<th></th>';
        if(c.filterOptions){
            const opts = ['<option value="">(all)</option>'].concat(c.filterOptions.map(o=>`<option ${String(st.filters[c.key]||'')===String(o)?'selected':''}>${esc(o)}</option>`)).join('');
            return `<th style="padding:4px 8px;"><select class="crm-select" data-fk="${c.key}" onchange="dtFilter(this)" style="padding:4px 6px;font-size:12px;width:100%;">${opts}</select></th>`;
        }
        return `<th style="padding:4px 8px;"><input class="crm-input" data-fk="${c.key}" value="${esc(st.filters[c.key]||'')}" oninput="dtFilter(this)" placeholder="filter…" style="padding:4px 7px;font-size:12px;"></th>`;
    }).join('');
    const body = pageRows.length ? pageRows.map((r,i)=>`<tr class="crm-row-click" data-i="${start+i}" onclick="dtRow(this)">${cols.map(c=>`<td class="${c.num?'num':''}">${c.cell(r)}</td>`).join('')}</tr>`).join('')
        : `<tr><td colspan="${cols.length}"><div class="crm-empty">${esc(st.opts.empty||'No rows.')}</div></td></tr>`;
    const from = total? start+1:0, to = Math.min(total, start+pageSize);
    mount.innerHTML = `
      <div class="crm-dt-bar">
        <label class="crm-dim" style="font-size:12.5px;">Show
          <select class="crm-select" style="width:auto;display:inline-block;padding:4px 8px;" onchange="dtPageSize(this)">
            ${[10,25,50,100,'All'].map(n=>`<option ${String(st.pageSize)===String(n)?'selected':''}>${n}</option>`).join('')}
          </select> per page</label>
        <div class="spacer" style="flex:1;"></div>
        <span class="crm-dim" style="font-size:12.5px;">${total? from+'–'+to : 0} of ${total.toLocaleString()}</span>
        <button class="crm-btn sm" ${st.page<=1?'disabled':''} onclick="dtPage(this,-1)"><i class="fas fa-chevron-left"></i></button>
        <span class="crm-dim" style="font-size:12.5px;">${st.page}/${pages}</span>
        <button class="crm-btn sm" ${st.page>=pages?'disabled':''} onclick="dtPage(this,1)"><i class="fas fa-chevron-right"></i></button>
      </div>
      <div class="crm-panel" style="margin-top:0;"><div class="crm-panel-body flush" style="overflow:auto;">
        <table class="crm-table"><thead><tr>${head}</tr><tr class="crm-filterrow">${filt}</tr></thead><tbody>${body}</tbody></table>
      </div></div>`;
}
function dtSort(th,key){ const m=dtWrap(th), st=m._dt; if(st.sortKey===key) st.sortDir*=-1; else {st.sortKey=key; st.sortDir=1;} st.page=1; renderDT(m); }
function dtFilter(inp){ const m=dtWrap(inp), st=m._dt, fk=inp.dataset.fk; st.filters[fk]=inp.value.trim(); st.page=1; renderDT(m); const n=m.querySelector(`input[data-fk="${fk}"]`); if(n){ n.focus(); try{n.setSelectionRange(n.value.length,n.value.length);}catch(e){} } }
function dtPageSize(sel){ const m=dtWrap(sel), st=m._dt; st.pageSize = sel.value==='All'?'All':parseInt(sel.value); st.page=1; renderDT(m); }
function dtPage(btn,d){ const m=dtWrap(btn), st=m._dt; st.page+=d; renderDT(m); }
function dtRow(tr){ const m=dtWrap(tr), i=parseInt(tr.dataset.i), r=m._rows[i]; if(r && m._dt.opts.onRow) m._dt.opts.onRow(r); }
async function projMove(leadId, stageId){
    const r = await api('lead_move', { lead_id:leadId, to_stage_id:stageId });
    if(!r.success){ toast(r.message,false); return; }
    toast('Status updated'); openProject(PROJ.current);
}
async function projRemove(leadId){
    const res = await Swal.fire({title:'Remove from project?',text:'The contact stays in the database; only this project link is removed.',icon:'warning',showCancelButton:true,confirmButtonText:'Remove'});
    if(!res.isConfirmed) return;
    const r = await api('lead_delete', { lead_id:leadId });
    if(r.success){ toast('Removed'); openProject(PROJ.current); } else toast(r.message,false);
}
function projAddContact(){
    window._papPid = PROJ.meta.key.split(':')[1];
    const cats = ['<option value="">Any category</option>'].concat(S.vocab.categories.map(c=>`<option>${esc(c)}</option>`)).join('');
    const countries = ['<option value="">Any country</option>'].concat(S.vocab.countries.map(c=>`<option>${esc(c)}</option>`)).join('');
    const otherProjects = S.pipelines.filter(p=>p.type==='manual' && String(p.id)!==String(window._papPid)).map(p=>`<option value="${p.id}">${esc(p.name)} (${p.lead_count||0})</option>`).join('');
    openModal('Add contacts to project', `
      <div class="crm-tabs" style="margin-bottom:14px;">
        <button class="crm-tab active" data-apm="search" onclick="apMode('search')">Search &amp; pick</button>
        <button class="crm-tab" data-apm="filter" onclick="apMode('filter')">By category / filter</button>
        <button class="crm-tab" data-apm="project" onclick="apMode('project')">From another project</button>
      </div>
      <div id="ap_search">
        <div class="crm-searchbox"><i class="fas fa-search"></i><input class="crm-input" id="pap_q" placeholder="Search contacts…" oninput="papSearch()"></div>
        <div id="pap_results" style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:3px;margin-top:10px;"></div>
      </div>
      <div id="ap_filter" style="display:none;">
        <div class="crm-note">Add every contact matching a filter at once.</div>
        <div class="crm-form-grid" style="margin-top:12px;">
          <div class="crm-field"><label>Category</label><select class="crm-select" id="apf_cat" onchange="apCount()">${cats}</select></div>
          <div class="crm-field"><label>Country</label><select class="crm-select" id="apf_country" onchange="apCount()">${countries}</select></div>
        </div>
        <div class="crm-field"><label>Search (name / company / email)</label><input class="crm-input" id="apf_q" oninput="apCount()" placeholder="optional"></div>
        <label class="crm-chk" style="margin-bottom:10px;"><input type="checkbox" id="apf_hasemail" onchange="apCount()"> Only contacts with an email</label>
        <div style="display:flex;align-items:center;gap:12px;"><span id="apf_count" class="crm-dim">—</span>
          <div class="spacer" style="flex:1;"></div>
          <button class="crm-btn primary" id="apf_add" onclick="apFilterAdd()">Add all matching</button></div>
      </div>
      <div id="ap_project" style="display:none;">
        <div class="crm-note">Copy everyone from another CRM project into this one.</div>
        <div class="crm-field" style="margin-top:12px;"><label>Source project</label>
          <select class="crm-select" id="apr_proj">${otherProjects||'<option value="">— no other CRM projects —</option>'}</select></div>
        <button class="crm-btn primary" onclick="apProjectAdd()">Add all its contacts</button>
      </div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Close</button>`);
    apMode('search'); papSearch();
}
function apMode(m){
    ['search','filter','project'].forEach(x=>{ const el=document.getElementById('ap_'+x); if(el) el.style.display = x===m?'block':'none'; });
    document.querySelectorAll('#crmModal .crm-tabs .crm-tab').forEach(b=>b.classList.toggle('active', b.dataset.apm===m));
    if(m==='filter') apCount();
}
let _papTimer=null, _apcTimer=null;
function papSearch(){
    clearTimeout(_papTimer);
    _papTimer = setTimeout(async ()=>{
        const q = document.getElementById('pap_q').value.trim();
        const r = await api('contact_pick', { q });
        const box = document.getElementById('pap_results');
        if(!r.success||!r.data.rows.length){ box.innerHTML='<div class="crm-empty" style="padding:18px;">No matches.</div>'; return; }
        box.innerHTML = r.data.rows.map(c=>{
            const name=((c.first_name||'')+' '+(c.last_name||'')).trim()||'(no name)';
            return `<div class="crm-row-click" style="padding:9px 12px;border-bottom:1px solid var(--line-2);display:flex;gap:10px;align-items:center;" onclick="papAdd(${c.id})">
                <div style="flex:1;"><div class="crm-strong">${esc(name)}</div><div class="crm-dim" style="font-size:12px;">${esc(c.company||'')}${c.email?' · '+esc(c.email):''}</div></div>
                <i class="fas fa-plus crm-sbtn"></i></div>`;
        }).join('');
    }, 220);
}
async function papAdd(cid){
    const r = await api('lead_create', { pipeline_id:window._papPid, contact_id:cid });
    if(!r.success){ toast(r.message,false); return; }
    toast('Added'); loadPipelinesCache();
}
function apFilterParams(){
    return { category:document.getElementById('apf_cat').value, country:document.getElementById('apf_country').value,
             q:document.getElementById('apf_q').value.trim(), has_email:document.getElementById('apf_hasemail').checked?1:'' };
}
function apCount(){
    clearTimeout(_apcTimer);
    document.getElementById('apf_count').textContent = 'counting…';
    _apcTimer = setTimeout(async ()=>{
        const r = await api('contacts_list', Object.assign(apFilterParams(), { page:1, per_page:1 }));
        document.getElementById('apf_count').textContent = r.success ? (r.data.total.toLocaleString()+' contacts match') : 'error';
    }, 300);
}
async function apFilterAdd(){
    const btn=document.getElementById('apf_add'); btn.disabled=true; const old=btn.textContent; btn.textContent='Adding…';
    const r = await api('bulk_add_to_pipeline', Object.assign(apFilterParams(), { select_all:1, pipeline_id:window._papPid }));
    btn.disabled=false; btn.textContent=old;
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast(`${r.data.added} added to project`); openProject(PROJ.current); loadPipelinesCache();
}
async function apProjectAdd(){
    const src=document.getElementById('apr_proj').value;
    if(!src){ toast('No source project',false); return; }
    const r = await api('bulk_add_to_pipeline', { from_pipeline:src, pipeline_id:window._papPid });
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast(`${r.data.added} added to project`); openProject(PROJ.current); loadPipelinesCache();
}
function projPersonInfo(rowId){
    const c = PROJ.contacts.find(x=>x.row_id===rowId); if(!c) return;
    const ext = PROJ.meta.url && /^https?:/i.test(PROJ.meta.url);
    const extra = PROJ.meta.url ? `<a class="crm-btn" href="${PROJ.meta.url}" ${ext?'target="_blank" rel="noopener"':''}><i class="fas fa-arrow-up-right-from-square"></i> ${esc(PROJ.meta.open_label||'Open in tool')}</a>` : '';
    const row = (dt,dd)=> dd ? `<dt>${dt}</dt><dd>${dd}</dd>` : '';
    bigReadonly(esc(c.name)||'Record', esc(c.status_label||'')+' · '+esc(PROJ.meta.name), `
        <div class="crm-note">Managed in ${esc(PROJ.meta.name)} — shown read-only here.</div>
        <dl class="crm-dl" style="margin-top:16px;">
          ${row('Email', c.email?`<a class="crm-link" href="mailto:${esc(c.email)}">${esc(c.email)}</a>`:'')}
          ${row('Phone', esc(c.phone||''))}
          ${row('Broker', esc(c.broker||''))}
          ${row('Site', esc(c.source_site||''))}
          ${row('Insurance', esc(c.insurance||''))}
          ${row('Location', esc(c.sub||''))}
          ${row('Date of enquiry', c.enq_date?when(c.enq_date):'')}
          ${row('Last action', c.last_action?when(c.last_action):(c.applied?when(c.applied):''))}
          ${row('Status', esc(c.status_label||''))}
        </dl>
        ${c.kind==='gphi'?'<div id="pkvExtra" style="margin-top:6px;"><div class="crm-dim" style="padding:10px 0;"><i class="fas fa-spinner fa-spin"></i> Loading history…</div></div>':''}`, extra);
    if(c.kind==='gphi') loadPkvExtra(String(c.row_id).replace(/^g/,''));
}
const PKV_STATUS_MEANING = {
    submitted:'Enquiry submitted via the PhiCRM/GPHI website, not yet assigned.',
    broker_assigned:'Assigned to a broker to handle.',
    accepted:'Broker accepted and is working the enquiry.',
    withdrawn:'The enquiry was withdrawn (by the client or a broker/admin) — see who and why below.',
    closed:'Closed.', declined:'Declined.'
};
async function loadPkvExtra(eid){
    const r = await api('pkv_enquiry_detail', { id: eid });
    const box = document.getElementById('pkvExtra'); if(!box) return;
    if(!r.success){ box.innerHTML = `<div class="crm-dim">${esc(r.message)}</div>`; return; }
    const e = r.data.enquiry || {}, hist = r.data.history || [];
    const meaning = PKV_STATUS_MEANING[(e.status||'').toLowerCase()];
    let html = '';
    if(meaning) html += `<div class="crm-note" style="margin-top:4px;"><strong>${esc(e.status||'')}</strong> — ${esc(meaning)}</div>`;
    if(e.close_reason_text || e.close_reason_code) html += `<div class="crm-section-label">Reason</div><div class="crm-dim">${esc(e.close_reason_text||e.close_reason_code)}</div>`;
    if(e.client_message) html += `<div class="crm-section-label">Enquiry message</div><div class="crm-tl-body">${esc(e.client_message)}</div>`;
    html += `<div class="crm-section-label">Status history</div>`;
    if(hist.length){
        html += `<ul class="crm-tl">` + hist.map(h=>`
            <li class="crm-tl-item k-sent">
              <div class="crm-tl-title">${h.from?esc(h.from)+' → ':''}${esc(h.to||'')}</div>
              <div class="crm-tl-meta">${when(h.at)} · by ${esc(h.who||'—')}</div>
              ${h.note?`<div class="crm-tl-body">${esc(h.note)}</div>`:''}
            </li>`).join('') + `</ul>`;
    } else {
        html += `<div class="crm-dim">No status changes recorded.</div>`;
    }
    box.innerHTML = html;
}
function bigReadonly(title, sub, bodyHtml, footExtra){
    document.getElementById('crmBigTitle').textContent = title;
    document.getElementById('crmBigSub').textContent = sub||'';
    document.getElementById('crmBigTabs').innerHTML = '';
    document.getElementById('crmBigBody').innerHTML = bodyHtml;
    document.getElementById('crmBigFoot').innerHTML = (footExtra||'') + `<button class="crm-btn" onclick="crmCloseBig()">Close</button>`;
    document.getElementById('crmBigOv').classList.add('open');
}

// ════════════════════════════════════════════════════════════════════════
// PROJECTS & TOOLS  (legacy tools launcher — kept for reference)
// ════════════════════════════════════════════════════════════════════════
async function loadProjectsTab(){
    const el = document.getElementById('view-projects');
    el.innerHTML = '<div class="crm-spin"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    const r = await api('projects_overview');
    if(!r.success){ el.innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    const d = r.data;
    let html = '';

    // Business areas
    if((d.areas||[]).length){
        html += `<p class="crm-section-label" style="margin:0 0 12px;">Business areas</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:26px;">
          ${d.areas.map(areaCard).join('')}</div>`;
    }

    // Active projects (project-management module)
    html += `<p class="crm-section-label" style="margin:0 0 12px;">Active projects</p><div class="crm-panel"><div class="crm-panel-body flush">`;
    if((d.projects||[]).length){
        html += `<table class="crm-table"><thead><tr><th>Project</th><th class="num">Tasks</th><th>Progress</th><th></th></tr></thead><tbody>`;
        d.projects.forEach(p=>{
            const done = parseInt(p.tasks_done)||0, total = parseInt(p.tasks)||0;
            const pct = total? Math.round(done/total*100):0;
            html += `<tr class="crm-row-click" onclick="window.location='module-project-management.php'">
                <td class="crm-strong">${esc(p.name)}</td>
                <td class="num crm-dim">${done}/${total}</td>
                <td style="min-width:140px;"><div style="height:6px;background:var(--line-2);border-radius:3px;overflow:hidden;"><div style="height:100%;width:${pct}%;background:var(--accent);"></div></div></td>
                <td class="num"><i class="fas fa-arrow-up-right-from-square crm-dim" style="font-size:11px;"></i></td></tr>`;
        });
        html += `</tbody></table>`;
    } else {
        html += `<div class="crm-empty" style="padding:26px;"><i class="fas fa-diagram-project"></i>No active projects. <a class="crm-link" href="module-project-management.php">Open project management →</a></div>`;
    }
    html += `</div></div>`;

    // All tools launcher
    if((d.tools||[]).length){
        html += `<p class="crm-section-label" style="margin:26px 0 12px;">All tools</p>`;
        d.tools.forEach(g=>{
            html += `<div style="margin-bottom:18px;"><div class="crm-dim" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;"><i class="fa ${esc(g.icon)}"></i> ${esc(g.group)}</div>
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">`;
            g.modules.forEach(m=>{
                html += `<a href="${esc(m.module_url||'#')}" class="crm-panel" style="margin:0;display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:var(--ink);">
                    <i class="fa ${esc(m.module_icon||'fa-cube')}" style="color:var(--accent);width:18px;text-align:center;"></i>
                    <span style="font-weight:600;font-size:13px;">${esc(m.module_name)}</span></a>`;
            });
            html += `</div></div>`;
        });
    }
    el.innerHTML = html;
}

// ════════════════════════════════════════════════════════════════════════
// CONTACTS
// ════════════════════════════════════════════════════════════════════════
const C_COLS = [
    {key:'name',      label:'Name',      sort:'name',      filter:'f_name'},
    {key:'company',   label:'Company',   sort:'company',   filter:'f_company'},
    {key:'email',     label:'Email',     sort:'email',     filter:'f_email'},
    {key:'category',  label:'Category',  sort:'category'},
    {key:'location',  label:'Location',  sort:'location'},
    {key:'emails',    label:'Emails',    sort:'emails',    num:true},
    {key:'pipelines', label:'Pipelines', sort:'pipelines', num:true},
];
function contactsToolbar(){
    const cats = ['<option value="">All categories</option>'].concat(S.vocab.categories.map(c=>`<option value="${esc(c)}"${S.contacts.category===c?' selected':''}>${esc(c)}</option>`)).join('');
    const countries = ['<option value="">All countries</option>'].concat(S.vocab.countries.map(c=>`<option value="${esc(c)}"${S.contacts.country===c?' selected':''}>${esc(c)}</option>`)).join('');
    return `<div class="crm-toolbar">
        <div class="crm-searchbox"><i class="fas fa-search"></i><input class="crm-input" id="cQ" placeholder="Search name, company, email, phone…" value="${esc(S.contacts.q)}" onkeydown="if(event.key==='Enter')applyContacts()"></div>
        <select class="crm-select" id="cCat" onchange="applyContacts()">${cats}</select>
        ${CAN_MANAGE?`<button class="crm-btn" onclick="openCategoriesManager()" title="Create / manage contact categories"><i class="fas fa-tags"></i> Categories</button>`:''}
        <select class="crm-select" id="cCountry" onchange="applyContacts()">${countries}</select>
        <label class="crm-chk"><input type="checkbox" id="cHasEmail" ${S.contacts.has_email?'checked':''} onchange="applyContacts()"> Has email</label>
        <label class="crm-chk"><input type="checkbox" id="cHideSup" ${S.contacts.hide_suppressed?'checked':''} onchange="applyContacts()"> Hide suppressed</label>
        <div class="spacer"></div>
        <select class="crm-select" id="cPer" onchange="S.contacts.per=parseInt(this.value);S.contacts.page=1;loadContacts()">
            ${[50,100,200].map(n=>`<option value="${n}"${S.contacts.per===n?' selected':''}>${n}/page</option>`).join('')}
        </select>
    </div>`;
}
function pageAllSelected(){ return S._pageIds.length>0 && S._pageIds.every(id=>S.sel.ids.has(id)); }
function contactsHeadCells(){
    const c = S.contacts;
    const arrow = col => c.sort===col ? ` <i class="fas fa-caret-${c.dir==='asc'?'up':'down'}"></i>` : ' <i class="fas fa-sort" style="opacity:.3;"></i>';
    const chk = `<th style="width:34px;text-align:center;"><input type="checkbox" id="cSelPage" ${pageAllSelected()?'checked':''} onclick="toggleSelectPage(this.checked)" title="Select all on this page"></th>`;
    return chk + C_COLS.map(col=>`<th class="${col.num?'num ':''}crm-row-click" style="user-select:none;" onclick="contactSort('${col.sort}')">${esc(col.label)}${arrow(col.sort)}</th>`).join('') + '<th></th>';
}
function contactsHead(){
    const c = S.contacts;
    const filt = C_COLS.map(col=> col.filter
        ? `<th style="padding:4px 10px;"><input class="crm-input" id="${col.filter}" value="${esc(c[col.filter]||'')}" oninput="cFilterChanged()" placeholder="filter…" style="padding:4px 7px;font-size:12px;"></th>`
        : `<th></th>`).join('');
    return `<thead><tr id="cHeadRow">${contactsHeadCells()}</tr><tr class="crm-filterrow"><th></th>${filt}<th></th></tr></thead>`;
}
function applyContacts(){
    S.contacts.q = document.getElementById('cQ').value.trim();
    S.contacts.category = document.getElementById('cCat').value;
    S.contacts.country = document.getElementById('cCountry').value;
    S.contacts.has_email = document.getElementById('cHasEmail').checked;
    S.contacts.hide_suppressed = document.getElementById('cHideSup').checked;
    S.contacts.page = 1;
    clearSel();
    loadContacts(true);
}
function contactSort(col){
    const c = S.contacts;
    if(c.sort===col){ c.dir = c.dir==='asc'?'desc':'asc'; } else { c.sort = col; c.dir = 'asc'; }
    c.page = 1;
    loadContacts();
}
let _cFilterTimer=null;
function cFilterChanged(){
    clearTimeout(_cFilterTimer);
    _cFilterTimer = setTimeout(()=>{
        ['f_name','f_company','f_email'].forEach(k=>{ const el=document.getElementById(k); if(el) S.contacts[k]=el.value.trim(); });
        S.contacts.page = 1;
        clearSel();
        loadContacts();   // only tbody refreshes, filter inputs keep focus
    }, 300);
}

// ── Contact selection + bulk actions ──
function selCount(){ return S.sel.allFilter ? S.contacts.total : S.sel.ids.size; }
function selPayload(){
    if(S.sel.allFilter){
        const c = S.contacts;
        return { select_all:1, q:c.q, category:c.category, country:c.country, has_email:c.has_email?1:'', hide_suppressed:c.hide_suppressed?1:'', f_name:c.f_name||'', f_company:c.f_company||'', f_email:c.f_email||'' };
    }
    return { contact_ids:[...S.sel.ids].join(',') };
}
function toggleSel(id, on){ if(on) S.sel.ids.add(id); else { S.sel.ids.delete(id); S.sel.allFilter=false; } renderBulkBar(); syncSelPageChk(); }
function toggleSelectPage(on){
    S.sel.allFilter = false;
    S._pageIds.forEach(id=>{ if(on) S.sel.ids.add(id); else S.sel.ids.delete(id); });
    document.querySelectorAll('#cTbody .cChk').forEach(cb=>{ cb.checked = on; });
    renderBulkBar();
}
function syncSelPageChk(){ const m=document.getElementById('cSelPage'); if(m) m.checked = pageAllSelected(); }
function clearSel(){ S.sel.ids.clear(); S.sel.allFilter=false; renderBulkBar(); const m=document.getElementById('cSelPage'); if(m)m.checked=false; document.querySelectorAll('#cTbody .cChk').forEach(cb=>cb.checked=false); }
function selectAllFilter(){ S.sel.allFilter = true; renderBulkBar(); }
function renderBulkBar(){
    const bar = document.getElementById('cBulk');
    if(!bar) return;
    const n = selCount();
    if(n<=0){ bar.style.display='none'; bar.innerHTML=''; return; }
    bar.style.display='flex';
    const total = S.contacts.total;
    let selText;
    if(S.sel.allFilter){ selText = `<strong>All ${total.toLocaleString()}</strong> contacts matching the filter selected`; }
    else {
        selText = `<strong>${n.toLocaleString()}</strong> selected`;
        if(pageAllSelected() && total > S._pageIds.length){ selText += ` · <a class="crm-link" onclick="selectAllFilter()">Select all ${total.toLocaleString()} matching the filter</a>`; }
    }
    bar.innerHTML = `<div style="flex:1;">${selText}</div>
        <button class="crm-btn sm" onclick="openBulkPipeline()"><i class="fas fa-diagram-project"></i> Add to CRM project</button>
        <button class="crm-btn sm primary" onclick="openBulkAudience()"><i class="fas fa-paper-plane"></i> Create / add to audience</button>
        <button class="crm-btn sm" onclick="clearSel()">Clear</button>`;
}
async function loadContacts(rebuildShell){
    const el = document.getElementById('view-contacts');
    if(!el.dataset.init || rebuildShell){
        await ensureVocab();
        el.innerHTML = contactsToolbar()
            + `<div id="cBulk" class="crm-bulkbar" style="display:none;"></div>`
            + `<div class="crm-panel"><div class="crm-panel-body flush" style="overflow:auto;"><table class="crm-table" id="cTable">${contactsHead()}<tbody id="cTbody"></tbody></table></div></div><div id="cPager"></div>`;
        el.dataset.init='1';
    } else {
        const hr = document.getElementById('cHeadRow');
        if(hr) hr.innerHTML = contactsHeadCells();
    }
    const tbody = document.getElementById('cTbody');
    tbody.innerHTML = '<tr><td colspan="9"><div class="crm-spin"><i class="fas fa-spinner fa-spin"></i></div></td></tr>';
    const c = S.contacts;
    const r = await api('contacts_list', { q:c.q, category:c.category, country:c.country, has_email:c.has_email?1:'', hide_suppressed:c.hide_suppressed?1:'', f_name:c.f_name||'', f_company:c.f_company||'', f_email:c.f_email||'', page:c.page, per_page:c.per, sort:c.sort, dir:c.dir });
    if(!r.success){ tbody.innerHTML = `<tr><td colspan="9"><div class="crm-empty">${esc(r.message)}</div></td></tr>`; return; }
    c.total = r.data.total;
    const rows = r.data.rows;
    S._pageIds = rows.map(x=>parseInt(x.id));
    if(!rows.length){ tbody.innerHTML = '<tr><td colspan="9"><div class="crm-empty"><i class="fas fa-address-book"></i>No contacts match.</div></td></tr>'; document.getElementById('cPager').innerHTML=''; renderBulkBar(); syncSelPageChk(); return; }
    tbody.innerHTML = rows.map(ct=>{
        const id = parseInt(ct.id);
        const name = ((ct.first_name||'')+' '+(ct.last_name||'')).trim() || '<span class="crm-dim">— no name —</span>';
        const checked = (S.sel.allFilter || S.sel.ids.has(id)) ? 'checked' : '';
        return `<tr class="crm-row-click" onclick="openContact(${id})">
            <td style="text-align:center;" onclick="event.stopPropagation();"><input type="checkbox" class="cChk" ${checked} onclick="toggleSel(${id}, this.checked)"></td>
            <td class="crm-strong">${name}</td>
            <td>${esc(ct.company||'')||'<span class="crm-dim">—</span>'}</td>
            <td>${ct.email?esc(ct.email):'<span class="crm-dim">— none —</span>'}${parseInt(ct.suppressed)?' <span class="crm-badge is-lost" title="Suppressed"><span class="dot"></span></span>':''}</td>
            <td>${esc(ct.category||'')||'<span class="crm-dim">—</span>'}</td>
            <td class="crm-dim">${esc([ct.city,ct.country].filter(Boolean).join(', '))||'—'}</td>
            <td class="num crm-dim">${ct.times_sent||0}</td>
            <td class="num crm-dim">${ct.lead_count||0}</td>
            <td class="num"><i class="fas fa-pen crm-dim" style="font-size:11px;" title="Edit"></i></td>
        </tr>`;
    }).join('');
    renderPager('cPager', c, 'contacts', loadContacts);
    renderBulkBar(); syncSelPageChk();
}

// ── Bulk: add selection to a pipeline ──
function openBulkPipeline(){
    const manual = S.pipelines.filter(p=>p.type==='manual');
    if(!manual.length){ toast('Create a pipeline first (Pipelines tab).',false); return; }
    const opts = manual.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    openModal(`Add ${selCount().toLocaleString()} contact(s) to a pipeline`, `
      <div class="crm-field"><label>Pipeline</label><select class="crm-select" id="bp_pipe" onchange="bpLoadStages()">${opts}</select></div>
      <div class="crm-field"><label>Stage</label><select class="crm-select" id="bp_stage"></select></div>
      <div class="crm-note">Each selected contact becomes a lead in this pipeline (duplicates are skipped).</div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="doBulkPipeline()">Add to pipeline</button>`);
    bpLoadStages();
}
async function bpLoadStages(){
    const pid = document.getElementById('bp_pipe').value;
    const r = await api('pipeline_board',{pipeline:pid});
    if(r.success) document.getElementById('bp_stage').innerHTML = r.data.stages.map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join('');
}
async function doBulkPipeline(){
    const data = Object.assign(selPayload(), { pipeline_id:document.getElementById('bp_pipe').value, stage_id:document.getElementById('bp_stage').value });
    const btn = event.target; btn.disabled = true; btn.textContent = 'Adding…';
    const r = await api('bulk_add_to_pipeline', data);
    if(!r.success){ toast(r.message,false); btn.disabled=false; btn.textContent='Add to pipeline'; return; }
    crmCloseModal(); clearSel();
    Swal.fire({icon:'success',title:'Added to pipeline',text:`${r.data.added} lead(s) created (${r.data.selected-r.data.added} already existed).`});
    loadPipelinesCache();
}

// ── Bulk: create / add to an email audience ──
async function openBulkAudience(){
    const r = await api('audiences_list');
    const auds = r.success ? r.data.audiences : [];
    const opts = ['<option value="0">— Create a new audience —</option>'].concat(auds.map(a=>`<option value="${a.id}">${esc(a.name)} (${a.members})</option>`)).join('');
    openModal(`Add ${selCount().toLocaleString()} contact(s) to an audience`, `
      <div class="crm-note">Creates or updates an audience in the Email Campaign Manager. Only contacts with an email and not suppressed are added.</div>
      <div class="crm-field" style="margin-top:14px;"><label>Audience</label><select class="crm-select" id="ba_aud" onchange="document.getElementById('ba_newwrap').style.display=this.value==='0'?'block':'none'">${opts}</select></div>
      <div class="crm-field" id="ba_newwrap"><label>New audience name</label><input class="crm-input" id="ba_name" placeholder="e.g. German brokers – no email yet excluded"></div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="doBulkAudience()">Add to audience</button>`);
}
async function doBulkAudience(){
    const data = Object.assign(selPayload(), { audience_id:document.getElementById('ba_aud').value, new_name:(document.getElementById('ba_name')||{}).value||'' });
    const btn = event.target; btn.disabled=true; btn.textContent='Adding…';
    const r = await api('push_to_audience', data);
    if(!r.success){ toast(r.message,false); btn.disabled=false; btn.textContent='Add to audience'; return; }
    crmCloseModal(); clearSel();
    Swal.fire({icon:'success',title:'Audience updated',html:`${r.data.added} contact(s) added · audience now has ${r.data.members}.<br><br><a class="crm-link" href="module-email-campaigns.php">Open Email Campaigns →</a>`});
}
function renderPager(id, st, key, reload){
    const from = (st.page-1)*st.per + 1, to = Math.min(st.total, st.page*st.per);
    const pages = Math.max(1, Math.ceil(st.total/st.per));
    const box = document.getElementById(id);
    box.innerHTML = `<div class="crm-pager">
        <span>${st.total? from+'–'+to : 0} of ${st.total.toLocaleString()}</span><div class="spacer"></div>
        <button class="crm-btn sm" ${st.page<=1?'disabled':''} data-dir="-1"><i class="fas fa-chevron-left"></i></button>
        <span>Page ${st.page} / ${pages}</span>
        <button class="crm-btn sm" ${st.page>=pages?'disabled':''} data-dir="1"><i class="fas fa-chevron-right"></i></button>
    </div>`;
    box.querySelectorAll('button[data-dir]').forEach(b=>b.addEventListener('click',()=>{
        const np = st.page + parseInt(b.dataset.dir);
        if(np>=1 && np<=pages){ st.page = np; reload(); }
    }));
}

// ════════════════════════════════════════════════════════════════════════
// CONTACT DRAWER
// ════════════════════════════════════════════════════════════════════════
async function openContact(id){
    S.drawerContactId = id;
    document.getElementById('crmBigTitle').textContent = 'Contact';
    document.getElementById('crmBigSub').textContent = '';
    document.getElementById('crmBigTabs').innerHTML = '';
    document.getElementById('crmBigFoot').innerHTML = '';
    document.getElementById('crmBigBody').innerHTML = '<div class="crm-spin" style="margin:60px;"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    document.getElementById('crmBigOv').classList.add('open');
    const r = await api('contact_get', { id });
    if(!r.success){ document.getElementById('crmBigBody').innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    renderContactModal(r.data);
}
function crmCloseBig(){ document.getElementById('crmBigOv').classList.remove('open'); }
function renderContactModal(d){
    const ct = d.contact;
    const name = ((ct.first_name||'')+' '+(ct.last_name||'')).trim() || '(no name)';
    document.getElementById('crmBigTitle').textContent = name;
    const sup = d.suppression ? ` · Suppressed (${d.suppression})` : '';
    document.getElementById('crmBigSub').textContent = [ct.company, ct.job_title].filter(Boolean).join(' · ') + (ct.category?(' · '+ct.category):'') + sup;
    document.getElementById('crmBigTabs').innerHTML = `
        <button class="crm-bigtab active" data-sub="overview" onclick="bigSub('overview')">Details</button>
        <button class="crm-bigtab" data-sub="timeline" onclick="bigSub('timeline')">Timeline</button>
        <button class="crm-bigtab" data-sub="activity" onclick="bigSub('activity')">Activity</button>`;
    document.getElementById('crmBig')._data = d;
    bigSub('overview');
}
function bigSub(sub){
    document.querySelectorAll('#crmBigTabs .crm-bigtab').forEach(b=>b.classList.toggle('active', b.dataset.sub===sub));
    const d = document.getElementById('crmBig')._data;
    const ct = d.contact;
    const body = document.getElementById('crmBigBody');
    const foot = document.getElementById('crmBigFoot');
    foot.innerHTML = CAN_MANAGE && sub==='overview'
        ? `<button class="crm-btn" onclick="crmCloseBig()">Cancel</button><button class="crm-btn primary" onclick="saveDrawerContact(${ct.id})"><i class="fas fa-check"></i> Save changes</button>`
        : `<button class="crm-btn" onclick="crmCloseBig()">Close</button>`;
    if(sub==='overview'){
        const leads = d.leads.length ? d.leads.map(l=>`<tr><td class="crm-strong">${esc(l.pipeline_name)}</td><td>${esc(l.stage_name||'—')}</td><td>${badgeStatus(l.status)}</td><td class="num crm-dim">${money(l.value)}</td></tr>`).join('') : `<tr><td class="crm-dim" colspan="4" style="padding:12px;">Not in any pipeline yet.</td></tr>`;
        const ro = CAN_MANAGE ? '' : 'disabled';
        const F = (fid,label,val,type='text',span)=>`<div class="crm-field${span?' span-2':''}"><label>${label}</label><input class="crm-input" id="${fid}" type="${type}" value="${esc(val||'')}" ${ro}></div>`;
        const catOpts = ['<option value=""></option>'].concat(S.vocab.categories.map(x=>`<option${ct.category===x?' selected':''}>${esc(x)}</option>`)).join('');
        const countryOpts = ['<option value=""></option>'].concat(S.vocab.countries.map(x=>`<option${ct.country===x?' selected':''}>${esc(x)}</option>`)).join('');
        body.innerHTML = `
          ${CAN_MANAGE?'<div class="crm-section-label" style="margin-top:4px;">Edit contact</div>':''}
          <div class="crm-form-grid">
            ${F('de_first_name','First name',ct.first_name)}
            ${F('de_last_name','Last name',ct.last_name)}
            ${F('de_email','Email',ct.email,'email')}
            ${F('de_phone','Phone',ct.phone)}
            ${F('de_company','Company',ct.company)}
            ${F('de_job_title','Job title',ct.job_title)}
            <div class="crm-field"><label>Category</label><input class="crm-input" id="de_category" list="de_catlist" value="${esc(ct.category||'')}" ${ro}><datalist id="de_catlist">${catOpts}</datalist></div>
            <div class="crm-field"><label>Country</label><input class="crm-input" id="de_country" list="de_countrylist" value="${esc(ct.country||'')}" ${ro}><datalist id="de_countrylist">${countryOpts}</datalist></div>
            ${F('de_city','City',ct.city)}
            ${F('de_region','Region',ct.region)}
            ${F('de_address','Address',ct.address,'text',true)}
            ${F('de_postcode','Postcode',ct.postcode)}
            ${F('de_website','Website',ct.website)}
            ${F('de_source','Source',ct.source,'text',true)}
          </div>
          <div class="crm-section-label">In these projects</div>
          <table class="crm-table" style="border:1px solid var(--line);border-radius:4px;"><tbody>${leads}</tbody></table>
          ${CAN_MANAGE?`<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="crm-btn" onclick="openAddToPipeline(${ct.id})"><i class="fas fa-diagram-project"></i> Add to pipeline</button>
            <button class="crm-btn" onclick="openPushAudience([${ct.id}])" ${ct.email?'':'disabled'}><i class="fas fa-paper-plane"></i> Add to email audience</button>
          </div>`:''}`;
    } else if(sub==='timeline'){
        body.innerHTML = renderTimeline(d.timeline, d.activities);
    } else if(sub==='activity'){
        const list = d.activities.length ? d.activities.map(a=>`
            <li class="crm-tl-item k-${esc(a.type)}">
              <div class="crm-tl-title">${esc(a.subject||a.type)} <span class="crm-dim" style="font-weight:400;">· ${esc(a.type)}</span></div>
              <div class="crm-tl-meta">${when(a.created_at)}${a.user_name?' · '+esc(a.user_name):''}${a.due_at?' · due '+dateOnly(a.due_at):''}</div>
              ${a.body?`<div class="crm-tl-body">${esc(a.body)}</div>`:''}
              ${CAN_MANAGE?`<div style="margin-top:4px;"><a class="crm-link" style="font-size:12px;" onclick="delActivity(${a.id})">Delete</a></div>`:''}
            </li>`).join('') : '<div class="crm-empty" style="padding:20px;">No activity logged yet.</div>';
        body.innerHTML = `${CAN_MANAGE?`<button class="crm-btn primary" style="margin-bottom:14px;" onclick="openActivityForm(${ct.id})"><i class="fas fa-plus"></i> Log activity</button>`:''}<ul class="crm-tl">${list}</ul>`;
    }
}
function badgeStatus(st){
    const map = {open:['is-open','Open'],won:['is-won','Won'],lost:['is-lost','Lost'],on_hold:['is-warn','On hold']};
    const m = map[st]||['is-muted',st];
    return `<span class="crm-badge ${m[0]}"><span class="dot"></span>${esc(m[1])}</span>`;
}
function renderTimeline(timeline, activities){
    const items = [];
    (timeline||[]).forEach(e=>items.push({at:e.at, kind:e.kind, title:e.title, meta:e.campaign?('Campaign: '+e.campaign):'', body:e.detail}));
    (activities||[]).forEach(a=>items.push({at:a.created_at, kind:a.type, title:(a.subject||a.type), meta:(a.user_name||''), body:a.body}));
    items.sort((a,b)=> (new Date(String(b.at||'').replace(' ','T'))) - (new Date(String(a.at||'').replace(' ','T'))) );
    if(!items.length) return '<div class="crm-empty" style="padding:24px;"><i class="far fa-clock"></i>No email history or activity yet.</div>';
    const labelKind = {sent:'Email sent',opened:'Email opened',clicked:'Link clicked',replied:'Replied',bounced:'Bounced',unsubscribed:'Unsubscribed',suppressed:'Suppressed'};
    return `<ul class="crm-tl">` + items.map(it=>`
        <li class="crm-tl-item k-${esc(it.kind)}">
          <div class="crm-tl-title">${esc(it.title||labelKind[it.kind]||it.kind)}</div>
          <div class="crm-tl-meta">${when(it.at)}${it.meta?' · '+esc(it.meta):''}</div>
          ${it.body?`<div class="crm-tl-body">${esc(it.body)}</div>`:''}
        </li>`).join('') + `</ul>`;
}
function openDrawer(){ document.getElementById('crmOverlay').classList.add('open'); document.getElementById('crmDrawer').classList.add('open'); }
function crmCloseDrawer(){ document.getElementById('crmOverlay').classList.remove('open'); document.getElementById('crmDrawer').classList.remove('open'); }
function drawerError(msg){ return `<div class="crm-drawer-head"><h2>Error</h2><div class="spacer"></div><button class="crm-x" onclick="crmCloseDrawer()"><i class="fas fa-times"></i></button></div><div class="crm-drawer-body"><div class="crm-empty">${esc(msg)}</div></div>`; }

// ════════════════════════════════════════════════════════════════════════
// LEADS
// ════════════════════════════════════════════════════════════════════════
function leadsToolbar(){
    const pipes = ['<option value="">All pipelines</option>'].concat(S.pipelines.filter(p=>p.type==='manual').map(p=>`<option value="${p.id}"${S.leads.pipeline_id==p.id?' selected':''}>${esc(p.name)}</option>`)).join('');
    const statuses = [['','Any status'],['open','Open'],['won','Won'],['lost','Lost'],['on_hold','On hold']].map(s=>`<option value="${s[0]}"${S.leads.status===s[0]?' selected':''}>${s[1]}</option>`).join('');
    return `<div class="crm-toolbar">
        <div class="crm-searchbox"><i class="fas fa-search"></i><input class="crm-input" id="lQ" placeholder="Search leads…" value="${esc(S.leads.q)}" onkeydown="if(event.key==='Enter')applyLeads()"></div>
        <select class="crm-select" id="lPipe" onchange="applyLeads()">${pipes}</select>
        <select class="crm-select" id="lStatus" onchange="applyLeads()">${statuses}</select>
        <div class="spacer"></div>
        ${CAN_MANAGE?`<button class="crm-btn" onclick="openPushAudience(null,'leads')"><i class="fas fa-paper-plane"></i> Push to audience</button>`:''}
    </div>`;
}
function applyLeads(){
    S.leads.q = document.getElementById('lQ').value.trim();
    S.leads.pipeline_id = document.getElementById('lPipe').value;
    S.leads.status = document.getElementById('lStatus').value;
    S.leads.page = 1;
    loadLeads();
}
async function loadLeads(){
    const el = document.getElementById('view-leads');
    if(!el.dataset.init){ el.innerHTML = leadsToolbar() + '<div class="crm-panel"><div class="crm-panel-body flush" id="lTableWrap"></div></div><div id="lPager"></div>'; el.dataset.init='1'; }
    document.getElementById('lTableWrap').innerHTML = '<div class="crm-spin"><i class="fas fa-spinner fa-spin"></i></div>';
    const l = S.leads;
    const r = await api('leads_list', { q:l.q, pipeline_id:l.pipeline_id, status:l.status, page:l.page, per_page:l.per });
    if(!r.success){ document.getElementById('lTableWrap').innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    l.total = r.data.total;
    const rows = r.data.rows;
    if(!rows.length){ document.getElementById('lTableWrap').innerHTML = '<div class="crm-empty"><i class="fas fa-user-tag"></i>No leads match. Add contacts to a pipeline to create leads.</div>'; document.getElementById('lPager').innerHTML=''; return; }
    let h = `<table class="crm-table"><thead><tr><th>Name</th><th>Company</th><th>Pipeline</th><th>Stage</th><th>Status</th><th>Owner</th><th class="num">Value</th><th>Updated</th></tr></thead><tbody>`;
    rows.forEach(ld=>{
        const name = ((ld.first_name||'')+' '+(ld.last_name||'')).trim()||'<span class="crm-dim">—</span>';
        h += `<tr class="crm-row-click" onclick="openContact(${ld.contact_id})">
            <td class="crm-strong">${name}</td>
            <td>${esc(ld.company||'')||'<span class="crm-dim">—</span>'}</td>
            <td>${esc(ld.pipeline_name)}</td>
            <td>${esc(ld.stage_name||'—')}</td>
            <td>${badgeStatus(ld.status)}</td>
            <td class="crm-dim">${esc(ld.owner_name||'')||'—'}</td>
            <td class="num">${money(ld.value)}</td>
            <td class="crm-dim">${dateOnly(ld.updated_at)}</td>
        </tr>`;
    });
    h += `</tbody></table>`;
    document.getElementById('lTableWrap').innerHTML = h;
    renderPager('lPager', l, 'leads', loadLeads);
}

// ════════════════════════════════════════════════════════════════════════
// PIPELINES / BOARD
// ════════════════════════════════════════════════════════════════════════
async function loadPipelines(){
    await loadPipelinesCache();
    if((S.currentPipeline==null || !S.pipelines.some(p=>p.id==S.currentPipeline)) && S.pipelines.length){
        // default to the pipeline with the most open items so the board isn't empty
        const best = [...S.pipelines].sort((a,b)=>(parseInt(b.open_count)||0)-(parseInt(a.open_count)||0))[0];
        S.currentPipeline = best.id;
    }
    renderPipelineShell();
    if(S.currentPipeline!=null) openBoard(S.currentPipeline);
}
function renderPipelineShell(){
    const el = document.getElementById('view-pipelines');
    const chips = S.pipelines.map(p=>`<button class="crm-tab ${S.currentPipeline==p.id?'active':''}" style="margin-right:16px;" onclick="openBoard('${p.id}')">${esc(p.name)} <span class="crm-dim" style="font-weight:400;">${p.open_count}</span></button>`).join('');
    el.innerHTML = `
      <div style="background:var(--panel);border-bottom:1px solid var(--line);padding:10px 24px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;flex-wrap:wrap;flex:1;">${chips||'<span class="crm-dim">No pipelines.</span>'}</div>
        <div id="boardActions"></div>
      </div>
      <div class="crm-board-wrap" id="boardWrap"><div class="crm-spin" style="margin-top:60px;"><i class="fas fa-spinner fa-spin fa-lg"></i></div></div>`;
}
async function openBoard(pid){
    S.currentPipeline = pid;
    if(S.tab!=='pipelines'){ crmTab('pipelines'); return; }
    renderPipelineShell();
    const wrap = document.getElementById('boardWrap');
    wrap.innerHTML = '<div class="crm-spin" style="margin-top:60px;"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    const r = await api('pipeline_board', { pipeline: pid });
    if(!r.success){ wrap.innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    S.board = r.data;
    const isPkv = r.data.type==='pkv';
    let actions = '';
    if(!isPkv && CAN_MANAGE){
        actions = `<button class="crm-btn" onclick="openAddLead()"><i class="fas fa-plus"></i> Add lead</button>
                   <button class="crm-btn" onclick="openPipelineStages(${r.data.pipeline.id})"><i class="fas fa-sliders"></i> Stages</button>
                   <button class="crm-btn icon" onclick="confirmDeletePipeline(${r.data.pipeline.id})" title="Delete pipeline"><i class="fas fa-trash crm-dim"></i></button>`;
    } else if(isPkv){
        actions = `<a class="crm-btn" href="module-pkv.php"><i class="fas fa-arrow-up-right-from-square"></i> Open PKV tool</a>`;
    }
    document.getElementById('boardActions').innerHTML = actions;
    renderBoard(r.data, isPkv);
}
function renderBoard(data, isPkv){
    const wrap = document.getElementById('boardWrap');
    const stages = data.stages;
    const byStage = {};
    stages.forEach(s=> byStage[isPkv?s.key:s.id] = []);
    (data.leads||[]).forEach(l=>{
        const key = isPkv ? l.stage_key : l.stage_id;
        if(byStage[key]===undefined){ (byStage['_un']=byStage['_un']||[]).push(l); }
        else byStage[key].push(l);
    });
    const cols = stages.map(s=>{
        const key = isPkv?s.key:s.id;
        const list = byStage[key]||[];
        const tick = s.is_won? 'won' : (s.is_lost?'lost':'');
        const cards = list.map(l=> isPkv?pkvCard(l):leadCard(l)).join('') || '<div class="crm-empty" style="padding:16px;font-size:12px;"><i class="far fa-folder-open" style="font-size:18px;"></i>Empty</div>';
        const dnd = (!isPkv && CAN_MANAGE) ? `ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="dropLead(event, ${s.id})"` : '';
        return `<div class="crm-col">
            <div class="crm-col-head">${tick?`<span class="tick ${tick}"></span>`:''}<span class="name">${esc(s.name)}</span><span class="count">${list.length}</span></div>
            <div class="crm-col-body" data-stage="${key}" ${dnd}>${cards}</div>
          </div>`;
    }).join('');
    wrap.innerHTML = `<div class="crm-board">${cols}</div>`;
}
function leadCard(l){
    const name = ((l.first_name||'')+' '+(l.last_name||'')).trim()||'(no name)';
    const drag = CAN_MANAGE ? `draggable="true" ondragstart="dragLead(event, ${l.id})"` : '';
    return `<div class="crm-card" ${drag} onclick="openContact(${l.contact_id})">
        <div class="name">${esc(name)}</div>
        ${l.company?`<div class="co">${esc(l.company)}</div>`:''}
        <div class="meta">
            ${l.value?`<span class="val">${money(l.value)}</span>`:''}
            ${l.owner_name?`<span><i class="far fa-user"></i> ${esc(l.owner_name)}</span>`:''}
            ${parseInt(l.suppressed)?`<span class="crm-badge is-lost" title="Suppressed"><span class="dot"></span></span>`:''}
        </div>
    </div>`;
}
function pkvCard(l){
    const name = ((l.first_name||'')+' '+(l.last_name||'')).trim()||'(no name)';
    return `<div class="crm-card" onclick="openPkvEnquiry(${l.enquiry_id})">
        <div class="name">${esc(name)}</div>
        ${l.broker_name?`<div class="co"><i class="far fa-building"></i> ${esc(l.broker_name)}</div>`:'<div class="co crm-dim">Unassigned</div>'}
        <div class="meta">
            ${l.email?`<span><i class="far fa-envelope"></i> ${esc(l.email)}</span>`:''}
            ${l.contact_id?`<span class="crm-badge is-open" title="Linked contact"><span class="dot"></span>CRM</span>`:''}
        </div>
    </div>`;
}
let _dragLead = null;
function dragLead(e, id){ _dragLead = id; e.dataTransfer.effectAllowed='move'; }
async function dropLead(e, stageId){
    e.preventDefault();
    e.currentTarget.classList.remove('dragover');
    if(!_dragLead) return;
    const id = _dragLead; _dragLead = null;
    const r = await api('lead_move', { lead_id:id, to_stage_id:stageId });
    if(r.success){ openBoard(S.currentPipeline); } else toast(r.message,false);
}

// ════════════════════════════════════════════════════════════════════════
// PKV ENQUIRY DRAWER
// ════════════════════════════════════════════════════════════════════════
async function openPkvEnquiry(eid){
    document.getElementById('crmBigTitle').textContent = 'PKV enquiry';
    document.getElementById('crmBigSub').textContent = '';
    document.getElementById('crmBigTabs').innerHTML = '';
    document.getElementById('crmBigFoot').innerHTML = '';
    document.getElementById('crmBigBody').innerHTML = '<div class="crm-spin" style="margin:60px;"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    document.getElementById('crmBigOv').classList.add('open');
    const r = await api('pkv_enquiry_get', { enquiry_id: eid });
    if(!r.success){ document.getElementById('crmBigBody').innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    const e = r.data.enquiry;
    const name = ((e.first_name||'')+' '+(e.last_name||'')).trim()||'Enquiry #'+e.id;
    const f = (dt,dd)=>dd?`<dt>${dt}</dt><dd>${dd}</dd>`:'';
    bigReadonly(name, 'PKV enquiry #'+e.id+' · '+esc((e.state||'').replace(/_/g,' ')), `
        <div class="crm-note">This enquiry lives in PhiCRM (the PKV broker tool) and is shown read-only. Manage it there.</div>
        <dl class="crm-dl" style="margin-top:16px;">
          ${f('Email', e.email?`<a class="crm-link" href="mailto:${esc(e.email)}">${esc(e.email)}</a>`:'<span class="crm-dim">—</span>')}
          ${f('Phone', esc(e.phone)||'')}
          ${f('Broker', esc(e.broker_name)||'<span class="crm-dim">Unassigned</span>')}
          ${f('Nationality', esc(e.nationality)||'')}
          ${f('Employment', esc(e.employment_status)||'')}
          ${f('Current insurer', esc(e.current_insurance)||'')}
          ${f('Source', esc(e.source)||'')}
          ${f('Created', dateOnly(e.created_at))}
        </dl>
        <div class="crm-section-label">Email history ${r.data.contact?'':'<span class="crm-dim" style="font-weight:400;text-transform:none;letter-spacing:0;">(no linked contact)</span>'}</div>
        ${r.data.contact ? renderTimeline(r.data.timeline, []) : '<div class="crm-empty" style="padding:18px;">This enquiry email is not in the contact database.</div>'}`,
        `<a class="crm-btn" href="https://phicrm.com" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open PhiCRM</a>`);
}

// ════════════════════════════════════════════════════════════════════════
// MODALS
// ════════════════════════════════════════════════════════════════════════
function openModal(title, bodyHtml, footHtml, lg){
    document.getElementById('crmModalTitle').textContent = title;
    document.getElementById('crmModalBody').innerHTML = bodyHtml;
    document.getElementById('crmModalFoot').innerHTML = footHtml||'';
    document.getElementById('crmModal').classList.toggle('lg', !!lg);
    document.getElementById('crmModalOv').classList.add('open');
}
function crmCloseModal(){ document.getElementById('crmModalOv').classList.remove('open'); }

// ── Contact form ──
let _editContact = null;
async function openContactForm(id){
    _editContact = null;
    if(id){
        const r = await api('contact_get',{id});
        if(!r.success){ toast(r.message,false); return; }
        _editContact = r.data.contact;
    }
    const c = _editContact||{};
    const catOpts = ['<option value=""></option>'].concat(S.vocab.categories.map(x=>`<option${c.category===x?' selected':''}>${esc(x)}</option>`)).join('');
    const countryOpts = ['<option value=""></option>'].concat(S.vocab.countries.map(x=>`<option${c.country===x?' selected':''}>${esc(x)}</option>`)).join('');
    const F = (fid,label,val,type='text',span)=>`<div class="crm-field${span?' span-2':''}"><label>${label}</label><input class="crm-input" id="${fid}" type="${type}" value="${esc(val||'')}"></div>`;
    openModal(id?'Edit contact':'New contact', `
      <div class="crm-form-grid">
        ${F('f_first_name','First name',c.first_name)}
        ${F('f_last_name','Last name',c.last_name)}
        ${F('f_email','Email',c.email,'email')}
        ${F('f_phone','Phone',c.phone)}
        ${F('f_company','Company',c.company)}
        ${F('f_job_title','Job title',c.job_title)}
        <div class="crm-field"><label>Category</label><input class="crm-input" id="f_category" list="f_catlist" value="${esc(c.category||'')}"><datalist id="f_catlist">${catOpts}</datalist></div>
        <div class="crm-field"><label>Country</label><input class="crm-input" id="f_country" list="f_countrylist" value="${esc(c.country||'')}"><datalist id="f_countrylist">${countryOpts}</datalist></div>
        ${F('f_city','City',c.city)}
        ${F('f_region','Region',c.region)}
        ${F('f_address','Address',c.address,'text',true)}
        ${F('f_postcode','Postcode',c.postcode)}
        ${F('f_website','Website',c.website)}
        ${F('f_source','Source',c.source,'text',true)}
      </div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="saveContact()">Save contact</button>`, true);
}
async function saveContact(){
    const g = fid=>document.getElementById(fid).value.trim();
    const data = { email:g('f_email'), first_name:g('f_first_name'), last_name:g('f_last_name'), phone:g('f_phone'),
        company:g('f_company'), job_title:g('f_job_title'), category:g('f_category'), country:g('f_country'),
        city:g('f_city'), region:g('f_region'), address:g('f_address'), postcode:g('f_postcode'),
        website:g('f_website'), source:g('f_source') };
    if(_editContact) data.id = _editContact.id;
    const r = await api('contact_save', data);
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast('Contact saved');
    if(data.category && S.vocab.categories.indexOf(data.category)<0){ S.vocab.categories.push(data.category); S.vocab.categories.sort(); }
    if(S.tab==='contacts') loadContacts();
    if(S.drawerContactId) openContact(r.data.id);
}

async function saveDrawerContact(id){
    const g = fid=>{ const el=document.getElementById(fid); return el?el.value.trim():''; };
    const data = { id, email:g('de_email'), first_name:g('de_first_name'), last_name:g('de_last_name'), phone:g('de_phone'),
        company:g('de_company'), job_title:g('de_job_title'), category:g('de_category'), country:g('de_country'),
        city:g('de_city'), region:g('de_region'), address:g('de_address'), postcode:g('de_postcode'),
        website:g('de_website'), source:g('de_source') };
    const r = await api('contact_save', data);
    if(!r.success){ toast(r.message,false); return; }
    toast('Contact saved');
    if(data.category && S.vocab.categories.indexOf(data.category)<0){ S.vocab.categories.push(data.category); S.vocab.categories.sort(); }
    if(S.tab==='contacts') loadContacts();
    openContact(id);
}

// ── Add to pipeline from a contact ──
async function openAddToPipeline(contactId){
    const manual = S.pipelines.filter(p=>p.type==='manual');
    if(!manual.length){ toast('Create a pipeline first.',false); return; }
    const opts = manual.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    openModal('Add to pipeline', `
      <div class="crm-field"><label>Pipeline</label><select class="crm-select" id="al_pipe" onchange="alLoadStages()">${opts}</select></div>
      <div class="crm-field"><label>Stage</label><select class="crm-select" id="al_stage"></select></div>
      <div class="crm-field"><label>Deal value (optional)</label><input class="crm-input" id="al_value" type="number" min="0" placeholder="e.g. 5000"></div>
      <input type="hidden" id="al_contact" value="${contactId}">`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="saveAddToPipeline()">Add lead</button>`);
    alLoadStages();
}
async function alLoadStages(){
    const pid = document.getElementById('al_pipe').value;
    const r = await api('pipeline_board',{pipeline:pid});
    if(r.success){ document.getElementById('al_stage').innerHTML = r.data.stages.map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join(''); }
}
async function saveAddToPipeline(){
    const r = await api('lead_create', { pipeline_id:document.getElementById('al_pipe').value, contact_id:document.getElementById('al_contact').value, stage_id:document.getElementById('al_stage').value, value:document.getElementById('al_value').value });
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast('Added to pipeline');
    if(S.drawerContactId) openContact(S.drawerContactId);
    loadPipelinesCache();
}

// ── Add lead to current board (pick a contact) ──
function openAddLead(){
    openModal('Add lead', `
      <div class="crm-field"><label>Find a contact</label>
        <div class="crm-searchbox"><i class="fas fa-search"></i><input class="crm-input" id="pick_q" placeholder="Search contacts…" oninput="pickSearch()"></div>
      </div>
      <div id="pick_results" style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:3px;"></div>
      <div class="crm-field" style="margin-top:14px;"><label>Deal value (optional)</label><input class="crm-input" id="pick_value" type="number" min="0"></div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button>`);
    pickSearch();
}
let _pickTimer=null;
function pickSearch(){
    clearTimeout(_pickTimer);
    _pickTimer = setTimeout(async ()=>{
        const q = document.getElementById('pick_q').value.trim();
        const r = await api('contact_pick', { q });
        const box = document.getElementById('pick_results');
        if(!r.success||!r.data.rows.length){ box.innerHTML='<div class="crm-empty" style="padding:18px;">No matches.</div>'; return; }
        box.innerHTML = r.data.rows.map(c=>{
            const name=((c.first_name||'')+' '+(c.last_name||'')).trim()||'(no name)';
            return `<div class="crm-row-click" style="padding:9px 12px;border-bottom:1px solid var(--line-2);display:flex;gap:10px;align-items:center;" onclick="pickContact(${c.id})">
                <div style="flex:1;"><div class="crm-strong">${esc(name)}</div><div class="crm-dim" style="font-size:12px;">${esc(c.company||'')}${c.email?' · '+esc(c.email):''}</div></div>
                <i class="fas fa-plus crm-sbtn"></i></div>`;
        }).join('');
    }, 220);
}
async function pickContact(cid){
    const r = await api('lead_create', { pipeline_id:S.currentPipeline, contact_id:cid, value:document.getElementById('pick_value').value });
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast('Lead added'); openBoard(S.currentPipeline); loadPipelinesCache();
}

// ── Pipeline form ──
const PROJECT_TYPES = ['General','Promotions','Venues outreach','Events outreach','Broker outreach','Advertiser outreach','Restaurant outreach','Sponsorships'];
let _pubs = null, _pfNameEdited = false;
async function ensurePubs(){ if(_pubs) return _pubs; const r = await api('publications_list'); _pubs = r.success ? r.data.publications : []; return _pubs; }
async function openPipelineForm(parentId){
    const pubs = await ensurePubs();
    const pubChecks = pubs.map(p=>`<label class="crm-chk" style="display:flex;margin:3px 0;"><input type="checkbox" class="pf_pubc" value="${esc(p)}" onchange="pfPubChange()"> ${esc(p)}</label>`).join('');
    openModal(parentId?'New sub-project':'New project', `
      <div class="crm-field"><label>Type</label><select class="crm-select" id="pf_type" onchange="pfTypeChange()">${PROJECT_TYPES.map(t=>`<option>${t}</option>`).join('')}</select></div>
      <div class="crm-field" id="pf_pubwrap"><label>Publications</label>
        <label class="crm-chk" style="margin-bottom:6px;"><input type="checkbox" id="pf_pub_all" onchange="pfPubAll()"> <strong>All TEN publications</strong> (catch-all)</label>
        <div id="pf_pub_list" style="max-height:150px;overflow:auto;border:1px solid var(--line);border-radius:3px;padding:8px;">${pubChecks||'<span class="crm-dim">No publications found.</span>'}</div>
        <div class="hint">Choose one, several, or tick “All TEN publications”.</div>
      </div>
      <div class="crm-field"><label>Project name</label><input class="crm-input" id="pf_name" oninput="_pfNameEdited=true" placeholder="auto-filled from type / publication"></div>
      <div class="crm-field"><label>Description (optional)</label><input class="crm-input" id="pf_desc"></div>
      <input type="hidden" id="pf_parent" value="${parentId||''}">
      <div class="crm-note">Pick a type — for <strong>Promotions</strong> (and other per-publication work) choose one or more publications. Starts with stages Prospect → Contacted → In discussion → Proposal sent → Agreed → Declined.</div>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="savePipeline()">Create project</button>`);
    _pfNameEdited = false; pfTypeChange();
}
function openSubProjectForm(parentId){ openPipelineForm(parentId); }
function pfTypeChange(){
    const t = document.getElementById('pf_type').value;
    document.getElementById('pf_pubwrap').style.display = (t==='General') ? 'none' : 'block';
    pfAutoName();
}
function pfPubAll(){ const all=document.getElementById('pf_pub_all').checked; document.querySelectorAll('.pf_pubc').forEach(cb=>{ cb.checked=false; cb.disabled=all; }); pfAutoName(); }
function pfPubChange(){ const a=document.getElementById('pf_pub_all'); if(a){ a.checked=false; } document.querySelectorAll('.pf_pubc').forEach(cb=>cb.disabled=false); pfAutoName(); }
function getSelectedPubs(){
    const a=document.getElementById('pf_pub_all');
    if(a && a.checked) return {all:true, list:['All publications']};
    return {all:false, list:[...document.querySelectorAll('.pf_pubc:checked')].map(cb=>cb.value)};
}
function pfAutoName(){
    if(_pfNameEdited) return;
    const t = document.getElementById('pf_type').value;
    const {all,list} = getSelectedPubs();
    let pubPart = all ? 'All publications' : (list.length===1 ? list[0] : (list.length>1 ? list.slice(0,2).join(', ')+(list.length>2?` +${list.length-2}`:'') : ''));
    let nm = (t==='General') ? '' : t;
    if(pubPart) nm = (t==='General' ? pubPart : (t + ' – ' + pubPart));
    document.getElementById('pf_name').value = nm;
}
async function savePipeline(){
    const type = document.getElementById('pf_type').value;
    const sel = (type==='General') ? {all:false,list:[]} : getSelectedPubs();
    const name = document.getElementById('pf_name').value.trim();
    if(type==='Promotions' && !sel.all && sel.list.length===0){ toast('Choose at least one publication (or “All TEN publications”).',false); return; }
    if(!name){ toast('Enter a project name',false); return; }
    const publication = sel.all ? 'All publications' : sel.list.join(', ');
    const parent = document.getElementById('pf_parent').value;
    const r = await api('pipeline_save', { name, description:document.getElementById('pf_desc').value, parent_id:parent||'',
        category: type==='General'?'':type, publication });
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast('Project created');
    PROJ.current = 'native:'+r.data.id; PROJ.view='detail';
    await loadPipelinesCache();
    if(S.tab==='projects') openProject('native:'+r.data.id); else crmTab('projects');
}
async function confirmDeletePipeline(id){
    const res = await Swal.fire({title:'Delete this pipeline?',text:'All its leads and history will be removed. Contacts are not deleted.',icon:'warning',showCancelButton:true,confirmButtonText:'Delete',cancelButtonText:'Cancel'});
    if(!res.isConfirmed) return;
    const r = await api('pipeline_delete',{id});
    if(!r.success){ toast(r.message,false); return; }
    S.currentPipeline = null; toast('Pipeline deleted'); await loadPipelinesCache(); loadPipelines();
}

// ── Pipeline stages editor ──
async function openPipelineStages(pid){
    const r = await api('pipeline_board',{pipeline:pid});
    if(!r.success){ toast(r.message,false); return; }
    renderStagesModal(pid, r.data.stages);
}
function renderStagesModal(pid, stages){
    const rows = stages.map(s=>`<tr>
        <td class="crm-strong">${esc(s.name)}</td>
        <td>${s.is_won==1?'<span class="crm-badge is-won"><span class="dot"></span>Won</span>':(s.is_lost==1?'<span class="crm-badge is-lost"><span class="dot"></span>Lost</span>':'<span class="crm-dim">—</span>')}</td>
        <td class="num"><button class="crm-btn sm" onclick="editStage(${pid},${s.id},${JSON.stringify(s.name)},${s.is_won},${s.is_lost})"><i class="fas fa-pen"></i></button>
            <button class="crm-btn sm danger" onclick="deleteStage(${pid},${s.id})"><i class="fas fa-trash"></i></button></td>
      </tr>`).join('');
    openModal('Pipeline stages', `
      <table class="crm-table" style="border:1px solid var(--line);border-radius:4px;margin-bottom:14px;"><thead><tr><th>Stage</th><th>Type</th><th></th></tr></thead><tbody>${rows}</tbody></table>
      <div id="stageForm"></div>
      <button class="crm-btn" onclick="editStage(${pid},0,'',0,0)"><i class="fas fa-plus"></i> Add stage</button>`,
      `<button class="crm-btn" onclick="crmCloseModal()">Done</button>`);
}
function editStage(pid, id, name, won, lost){
    document.getElementById('stageForm').innerHTML = `
      <div class="crm-panel" style="margin-bottom:14px;"><div class="crm-panel-body">
        <div class="crm-field"><label>Stage name</label><input class="crm-input" id="sf_name" value="${esc(name)}"></div>
        <div style="display:flex;gap:18px;">
          <label class="crm-chk"><input type="checkbox" id="sf_won" ${won==1?'checked':''}> Won stage</label>
          <label class="crm-chk"><input type="checkbox" id="sf_lost" ${lost==1?'checked':''}> Lost stage</label>
        </div>
        <div style="margin-top:12px;"><button class="crm-btn primary sm" onclick="saveStage(${pid},${id})">Save stage</button></div>
      </div></div>`;
}
async function saveStage(pid, id){
    const name = document.getElementById('sf_name').value.trim();
    if(!name){ toast('Enter a name',false); return; }
    const r = await api('stage_save', { id, pipeline_id:pid, name, is_won:document.getElementById('sf_won').checked?1:'', is_lost:document.getElementById('sf_lost').checked?1:'' });
    if(!r.success){ toast(r.message,false); return; }
    toast('Stage saved'); openPipelineStages(pid); if(PROJ.current==='native:'+pid) openProject(PROJ.current);
}
async function deleteStage(pid, id){
    const res = await Swal.fire({title:'Delete status?',text:'Contacts in it move to the first remaining status.',icon:'warning',showCancelButton:true,confirmButtonText:'Delete'});
    if(!res.isConfirmed) return;
    const r = await api('stage_delete',{id});
    if(!r.success){ toast(r.message,false); return; }
    toast('Status deleted'); openPipelineStages(pid); if(PROJ.current==='native:'+pid) openProject(PROJ.current);
}
async function confirmDeleteProject(key){
    const id = key.split(':')[1];
    const res = await Swal.fire({title:'Delete this project?',text:'Its contact links and history are removed. The contacts themselves stay in the database.',icon:'warning',showCancelButton:true,confirmButtonText:'Delete'});
    if(!res.isConfirmed) return;
    const r = await api('pipeline_delete',{id});
    if(!r.success){ toast(r.message,false); return; }
    toast('Project deleted'); PROJ.current=null; await loadProjects();
}

// ── Activity form ──
function openActivityForm(contactId){
    openModal('Log activity', `
      <div class="crm-field"><label>Type</label><select class="crm-select" id="ac_type">
        ${['note','call','email','meeting','task'].map(t=>`<option value="${t}">${t[0].toUpperCase()+t.slice(1)}</option>`).join('')}
      </select></div>
      <div class="crm-field"><label>Subject</label><input class="crm-input" id="ac_subject" placeholder="e.g. Called about proposal"></div>
      <div class="crm-field"><label>Notes</label><textarea class="crm-textarea" id="ac_body"></textarea></div>
      <div class="crm-field"><label>Follow-up date (optional)</label><input class="crm-input" id="ac_due" type="datetime-local"></div>
      <input type="hidden" id="ac_contact" value="${contactId}">`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="saveActivity()">Save</button>`);
}
async function saveActivity(){
    const due = document.getElementById('ac_due').value;
    const r = await api('activity_add', { contact_id:document.getElementById('ac_contact').value, type:document.getElementById('ac_type').value, subject:document.getElementById('ac_subject').value, body:document.getElementById('ac_body').value, due_at: due?due.replace('T',' ')+':00':'' });
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal(); toast('Activity logged');
    if(S.drawerContactId) openContact(S.drawerContactId);
}
async function delActivity(id){
    const res = await Swal.fire({title:'Delete activity?',icon:'warning',showCancelButton:true,confirmButtonText:'Delete'});
    if(!res.isConfirmed) return;
    const r = await api('activity_delete',{id});
    if(r.success && S.drawerContactId){ openContact(S.drawerContactId); }
}

// ── Push to audience ──
async function openPushAudience(contactIds, mode){
    const r = await api('audiences_list');
    const auds = r.success ? r.data.audiences : [];
    const opts = ['<option value="0">— Create a new audience —</option>'].concat(auds.map(a=>`<option value="${a.id}">${esc(a.name)} (${a.members})</option>`)).join('');
    let scopeNote = '';
    if(mode==='leads'){
        const pname = S.leads.pipeline_id ? (S.pipelines.find(p=>p.id==S.leads.pipeline_id)||{}).name : 'all pipelines';
        scopeNote = `<div class="crm-note">Adds the contacts of the leads in <strong>${esc(pname||'the selected pipeline')}</strong>. Only contacts with an email and not suppressed are added.</div>`;
    }
    openModal('Add to email audience', `
      ${scopeNote}
      <div class="crm-field"><label>Audience</label><select class="crm-select" id="pa_aud" onchange="document.getElementById('pa_newwrap').style.display=this.value==='0'?'block':'none'">${opts}</select></div>
      <div class="crm-field" id="pa_newwrap"><label>New audience name</label><input class="crm-input" id="pa_name" placeholder="e.g. Brokers – warm leads"></div>
      <input type="hidden" id="pa_ids" value="${contactIds?contactIds.join(','):''}">
      <input type="hidden" id="pa_mode" value="${mode||''}">`,
      `<button class="crm-btn" onclick="crmCloseModal()">Cancel</button><button class="crm-btn primary" onclick="doPushAudience()">Add to audience</button>`);
}
async function doPushAudience(){
    const aid = document.getElementById('pa_aud').value;
    const data = { audience_id:aid, new_name:document.getElementById('pa_name')?document.getElementById('pa_name').value:'' };
    const ids = document.getElementById('pa_ids').value;
    const mode = document.getElementById('pa_mode').value;
    if(ids) data.contact_ids = ids;
    else if(mode==='leads' && S.leads.pipeline_id) data.pipeline_id = S.leads.pipeline_id;
    else if(mode==='leads'){ toast('Choose a pipeline in the Leads filter first.',false); return; }
    const r = await api('push_to_audience', data);
    if(!r.success){ toast(r.message,false); return; }
    crmCloseModal();
    Swal.fire({icon:'success',title:'Added to audience',html:`${r.data.added} contact(s) added.<br>${r.data.selected-r.data.sendable} skipped (no email or suppressed).<br><br><a class="crm-link" href="module-email-campaigns.php">Open Email Campaigns →</a>`,confirmButtonText:'Done'});
}

// ════════════════════════════════════════════════════════════════════════
// SETTINGS  (choose which projects & sub-projects are shown)
// ════════════════════════════════════════════════════════════════════════
async function loadSettings(){
    const el = document.getElementById('view-settings');
    el.innerHTML = '<div class="crm-spin"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    const r = await api('project_prefs');
    if(!r.success){ el.innerHTML = `<div class="crm-empty">${esc(r.message)}</div>`; return; }
    const items = r.data.projects;
    const srcLabel = {pkv:'Health insurance',wne:'Recruitment',email:'Email',native:'CRM project'};
    let html = `<div class="crm-panel" style="max-width:760px;">
        <div class="crm-panel-head"><h3>Visible projects</h3><div class="spacer"></div>
          ${CAN_MANAGE?'<button class="crm-btn primary" onclick="openPipelineForm()"><i class="fas fa-plus"></i> New project</button>':''}</div>
        <div class="crm-panel-body flush"><table class="crm-table"><thead><tr><th>Project</th><th>Type</th><th class="num">Shown</th></tr></thead><tbody>`;
    items.forEach(p=>{
        html += `<tr><td class="crm-strong">${esc(p.name)}</td><td class="crm-dim">${esc(srcLabel[p.source]||p.source)}</td>
          <td class="num"><label class="crm-switch"><input type="checkbox" ${p.hidden?'':'checked'} ${CAN_MANAGE?'':'disabled'} onchange="setProjShown('${p.key}', this.checked)"><span></span></label></td></tr>`;
    });
    html += `</tbody></table></div></div>
      <p class="crm-dim" style="max-width:760px;font-size:12.5px;margin-top:10px;">Hidden projects stay in their own tool and in the data — they're just removed from the Projects tab and Overview for you. Turn one back on any time.</p>`;
    el.innerHTML = html;
}
async function setProjShown(key, shown){
    const r = await api('project_pref_set', { key, hidden: shown?'0':'1' });
    if(!r.success){ toast(r.message,false); return; }
    toast(shown?'Shown':'Hidden');
    loadPipelinesCache();
}

// ── Init ──
function crmInit(){
    crmTab('overview');                 // render immediately — don't wait on caches
    bootstrap().catch(()=>{});          // fill vocab/users/pipelines for the other tabs
    document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ crmCloseModal(); crmCloseBig(); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', crmInit);
else crmInit();
</script>
</body>
</html>
