<?php
/**
 * CRM backend (rebuilt).
 *
 * Serves the Contacts / Leads / Pipelines CRM that sits over the shared
 * ten_ec_contacts universe. Email history is joined live from the ec_* tables;
 * the PKV broker tool is surfaced as a live read-only pipeline. See lib/crm_core.php
 * and docs/superpowers/specs/2026-10-03-crm-overhaul-design.md.
 */
require_once '../config.php';
require_once '../lib/crm_core.php';
require_once '../lib/ec_core.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

$canView   = hasModulePermission('crm.view')   || isAdmin();
$canManage = hasModulePermission('crm.manage') || isAdmin();
if (!$canView) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$conn = crm_db();
crm_ensure_schema($conn);
$userId = (int)($_SESSION['ten_user_id'] ?? 0);

function ok($data = []) { echo json_encode(['success' => true, 'data' => $data]); exit; }
function fail($msg) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
function need_manage($canManage) { if (!$canManage) fail('You do not have permission to make changes.'); }

/**
 * Build the contacts WHERE clause from the shared filter params. Used by the
 * list AND by every bulk action, so "select all matching the filter" operates on
 * exactly the set the user is looking at. Alias is ct.
 */
function crm_contacts_where(mysqli $conn, array $P): string {
    $conds = ['1=1'];
    $q = trim($P['q'] ?? '');
    if ($q !== '') { $qe = '%' . $conn->real_escape_string($q) . '%';
        $conds[] = "(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe' OR ct.phone LIKE '$qe')"; }
    foreach (['name'=>"CONCAT_WS(' ',ct.first_name,ct.last_name)",'company'=>'ct.company','email'=>'ct.email'] as $fk=>$col) {
        $fv = trim($P['f_'.$fk] ?? '');
        if ($fv !== '') $conds[] = "$col LIKE '%" . $conn->real_escape_string($fv) . "%'";
    }
    if (trim($P['category'] ?? '') !== '') $conds[] = "ct.category='" . $conn->real_escape_string(trim($P['category'])) . "'";
    if (trim($P['country'] ?? '')  !== '') $conds[] = "ct.country='"  . $conn->real_escape_string(trim($P['country']))  . "'";
    if (($P['has_email'] ?? '') === '1')       $conds[] = "ct.email IS NOT NULL AND ct.email<>''";
    if (($P['hide_suppressed'] ?? '') === '1') $conds[] = "NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
    return 'WHERE ' . implode(' AND ', $conds);
}

/**
 * Resolve the set of contact ids a bulk action targets:
 *  - select_all=1  → every contact matching the current filter (the whole query);
 *  - contact_ids=a,b,c → an explicit list (a manual page selection).
 * $sendableOnly restricts to contacts with an email and not suppressed (audiences).
 */
function crm_resolve_contact_ids(mysqli $conn, array $P, bool $sendableOnly = false): array {
    $ids = [];
    if (!empty($P['from_pipeline'])) {
        // Everyone already in another CRM project (native pipeline).
        $sp = (int)$P['from_pipeline'];
        $extra = $sendableOnly ? " AND ct.email IS NOT NULL AND ct.email<>'' AND NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)" : '';
        $r = $conn->query("SELECT DISTINCT l.contact_id FROM ten_crm_leads l JOIN ten_ec_contacts ct ON ct.id=l.contact_id WHERE l.pipeline_id=$sp$extra");
        if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
        return $ids;
    }
    if (($P['select_all'] ?? '') === '1') {
        $where = crm_contacts_where($conn, $P);
        if ($sendableOnly) $where .= " AND ct.email IS NOT NULL AND ct.email<>'' AND NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
        $r = $conn->query("SELECT ct.id FROM ten_ec_contacts ct $where");
        if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
    } elseif (!empty($P['contact_ids'])) {
        $raw = is_array($P['contact_ids']) ? $P['contact_ids'] : explode(',', $P['contact_ids']);
        $want = [];
        foreach ($raw as $v) { $v = (int)$v; if ($v) $want[$v] = 1; }
        if ($want) {
            $list = implode(',', array_keys($want));
            $extra = $sendableOnly ? " AND ct.email IS NOT NULL AND ct.email<>'' AND NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)" : '';
            $r = $conn->query("SELECT ct.id FROM ten_ec_contacts ct WHERE ct.id IN ($list)$extra");
            if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
        }
    }
    return $ids;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$P = $_POST + $_GET;

try {
switch ($action) {

// ─────────────────────────────────────────────────────────── OVERVIEW ──
case 'overview': {
    $totalContacts = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_contacts")->fetch_assoc()['n'];
    $withEmail     = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_contacts WHERE email IS NOT NULL AND email<>''")->fetch_assoc()['n'];
    $suppressed    = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_suppression")->fetch_assoc()['n'];
    $openLeads     = (int)$conn->query("SELECT COUNT(*) n FROM ten_crm_leads WHERE status='open'")->fetch_assoc()['n'];
    $wonMonth      = (int)$conn->query("SELECT COUNT(*) n FROM ten_crm_leads WHERE status='won' AND MONTH(updated_at)=MONTH(CURDATE()) AND YEAR(updated_at)=YEAR(CURDATE())")->fetch_assoc()['n'];
    $sent30        = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE status='sent' AND sent_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch_assoc()['n'];

    // Leads per manual pipeline
    $byPipeline = [];
    $r = $conn->query("SELECT p.id, p.name,
                         (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.pipeline_id=p.id) total,
                         (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.pipeline_id=p.id AND l.status='open') open_cnt
                       FROM ten_crm_pipelines p WHERE p.is_active=1 ORDER BY p.display_order, p.id");
    if ($r) while ($row = $r->fetch_assoc()) $byPipeline[] = $row;

    // PKV live counts from PhiCRM/GPHI (gphi.gphi_cms_clients)
    $pkv = null;
    if (crm_pkv_visible()) {
        $gw = "(is_dummy=0 OR is_dummy IS NULL) AND (client_email IS NULL OR client_email NOT LIKE '%example.com')";
        try {
            $total = (int)$conn->query("SELECT COUNT(*) n FROM gphi.gphi_quote_requests WHERE $gw")->fetch_assoc()['n'];
            $open  = (int)$conn->query("SELECT COUNT(*) n FROM gphi.gphi_quote_requests WHERE $gw AND (status IS NULL OR status NOT IN('closed','won','lost','signed','declined','cancelled','closed_won','closed_lost','accepted'))")->fetch_assoc()['n'];
            $pkv = ['total' => $total, 'open' => $open];
        } catch (Throwable $e) { $pkv = null; }
    }

    // My tasks due
    $tasks = [];
    $r = $conn->query("SELECT a.id, a.subject, a.due_at, a.type, a.contact_id,
                        CONCAT(COALESCE(ct.first_name,''),' ',COALESCE(ct.last_name,'')) cname, ct.company
                       FROM ten_crm_activities a JOIN ten_ec_contacts ct ON ct.id=a.contact_id
                       WHERE a.done=0 AND a.due_at IS NOT NULL" . ($userId ? " AND (a.user_id=$userId OR a.user_id IS NULL)" : "") . "
                       ORDER BY a.due_at ASC LIMIT 8");
    if ($r) while ($row = $r->fetch_assoc()) $tasks[] = $row;

    // Cross-module business areas (live counts, tolerant of missing tables).
    $areas = crm_business_areas($conn);

    // Recent email sends (who, campaign, when) — the shared send log.
    $recentEmail = [];
    $r = $conn->query("SELECT sl.subject, sl.campaign_name, sl.sent_at, sl.email,
                         CONCAT(COALESCE(ct.first_name,''),' ',COALESCE(ct.last_name,'')) cname, ct.id contact_id
                       FROM ten_ec_sent_log sl LEFT JOIN ten_ec_contacts ct ON ct.id=sl.contact_id
                       ORDER BY sl.sent_at DESC, sl.id DESC LIMIT 8");
    if ($r) while ($row = $r->fetch_assoc()) $recentEmail[] = $row;

    ok([
        'total_contacts' => $totalContacts, 'with_email' => $withEmail, 'suppressed' => $suppressed,
        'open_leads' => $openLeads, 'won_this_month' => $wonMonth, 'sent_30d' => $sent30,
        'by_pipeline' => $byPipeline, 'pkv' => $pkv, 'tasks' => $tasks,
        'areas' => $areas, 'recent_email' => $recentEmail,
    ]);
}

// ═══════════════════════════════════════════════ PROJECTS (unified) ══
// A "project" is a body of work with contacts and a per-contact status. Sources:
//   pkv       → one external project (ten_pkv_enquiries), fed by PhiCRM/GPHI, READ-ONLY.
//   wne:<id>  → one per WNE recruitment project (ten_wne_projects), READ-ONLY (ATS owns it).
//   native:<id> → a CRM-owned project (ten_crm_pipelines) you can fully edit.
// The CRM is the one place to pick a project, see who is in it and their status,
// and (for native projects) add/remove/move contacts.

case 'projects_list': {
    $showAll = ($P['all'] ?? '') === '1';           // Settings view wants hidden ones too
    $hidden = $showAll ? [] : crm_hidden_keys($conn, $userId);
    $projects = [];

    // PKV — single external project, live from PhiCRM/GPHI (gphi.gphi_cms_clients)
    if (crm_pkv_visible()) {
        $total = 0; $states = [];
        $gphiWhere = "(is_dummy=0 OR is_dummy IS NULL) AND (client_email IS NULL OR client_email NOT LIKE '%example.com')";
        try {
            $total = (int)$conn->query("SELECT COUNT(*) n FROM gphi.gphi_quote_requests WHERE $gphiWhere")->fetch_assoc()['n'];
            $r = $conn->query("SELECT COALESCE(NULLIF(status,''),'—') st, COUNT(*) n
                               FROM gphi.gphi_quote_requests WHERE $gphiWhere GROUP BY st ORDER BY n DESC");
            if ($r) while ($row = $r->fetch_assoc()) $states[] = ['label'=>$row['st'] ?: '—', 'count'=>(int)$row['n'], 'won'=>0, 'lost'=>0];
        } catch (Throwable $e) {}
        $projects[] = ['key'=>'pkv', 'name'=>'PKV · PhiCRM / GPHI', 'source'=>'pkv', 'editable'=>false, 'parent'=>null,
                       'url'=>'https://phicrm.com', 'count'=>$total, 'states'=>array_slice($states, 0, 6),
                       'sub'=>'Live enquiries from PhiCRM & GPHI'];
    }

    // Email Campaigns — connected (audiences are the CRM-relevant unit)
    try {
        $acnt = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_audiences")->fetch_assoc()['n'];
        $projects[] = ['key'=>'email', 'name'=>'Email Campaigns', 'source'=>'email', 'editable'=>false, 'parent'=>null,
                       'url'=>'module-email-campaigns.php', 'count'=>$acnt, 'states'=>[],
                       'sub'=>'Audiences & campaigns in the Email Campaign Manager'];
    } catch (Throwable $e) {}

    // WNE recruitment projects
    try {
        $r = $conn->query("SELECT p.id, p.project_name, p.position_title, p.employer_company,
                             (SELECT COUNT(*) FROM ten_wne_project_candidates pc WHERE pc.project_id=p.id AND pc.is_active=1) cnt
                           FROM ten_wne_projects p ORDER BY p.id DESC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $projects[] = ['key'=>'wne:'.$row['id'], 'name'=>$row['project_name'], 'source'=>'wne', 'editable'=>false, 'parent'=>null,
                           'url'=>'module-wne.php', 'count'=>(int)$row['cnt'], 'states'=>[],
                           'sub'=>trim(($row['position_title'] ?: '') . ($row['employer_company'] ? ' · '.$row['employer_company'] : ''))];
        }
    } catch (Throwable $e) { /* WNE not present */ }

    // Native CRM projects (with sub-projects + state breakdown)
    $r = $conn->query("SELECT p.id, p.name, p.parent_id, p.category, p.publication,
                         (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.pipeline_id=p.id) cnt
                       FROM ten_crm_pipelines p WHERE p.is_active=1 ORDER BY (p.parent_id IS NOT NULL), p.display_order, p.id");
    $native = [];
    if ($r) while ($row = $r->fetch_assoc()) $native[] = $row;
    foreach ($native as $row) {
        $pid = (int)$row['id'];
        $states = [];
        $sr = $conn->query("SELECT s.name, s.is_won, s.is_lost, COUNT(l.id) n
                            FROM ten_crm_stages s LEFT JOIN ten_crm_leads l ON l.stage_id=s.id
                            WHERE s.pipeline_id=$pid GROUP BY s.id ORDER BY s.display_order, s.id");
        if ($sr) while ($srow = $sr->fetch_assoc()) if ((int)$srow['n'] > 0) $states[] = ['label'=>$srow['name'], 'count'=>(int)$srow['n'], 'won'=>(int)$srow['is_won'], 'lost'=>(int)$srow['is_lost']];
        $sub = trim(implode(' · ', array_filter([$row['category'] ?: '', $row['publication'] ?: ''])));
        $projects[] = ['key'=>'native:'.$pid, 'name'=>$row['name'], 'source'=>'native', 'editable'=>true,
                       'parent'=>$row['parent_id'] ? 'native:'.(int)$row['parent_id'] : null,
                       'category'=>$row['category'] ?: '', 'publication'=>$row['publication'] ?: '',
                       'url'=>null, 'count'=>(int)$row['cnt'], 'states'=>$states, 'sub'=>$sub];
    }

    if ($hidden) $projects = array_values(array_filter($projects, fn($p) => empty($hidden[$p['key']])));
    ok(['projects'=>$projects]);
}

case 'project_prefs': {
    // All projects (including hidden) + each one's hidden flag, for the Settings tab.
    $hidden = crm_hidden_keys($conn, $userId);
    // reuse projects_list logic by calling it inline would re-echo; instead rebuild a light list
    $_GET['all'] = '1'; $P['all'] = '1';
    $items = [];
    // PKV
    if (crm_pkv_visible()) $items[] = ['key'=>'pkv','name'=>'PKV · PhiCRM / GPHI','source'=>'pkv'];
    $items[] = ['key'=>'email','name'=>'Email Campaigns','source'=>'email'];
    try { $r=$conn->query("SELECT id,project_name FROM ten_wne_projects ORDER BY id DESC");
        if($r) while($row=$r->fetch_assoc()) $items[]=['key'=>'wne:'.$row['id'],'name'=>$row['project_name'],'source'=>'wne']; } catch(Throwable $e){}
    $r=$conn->query("SELECT p.id,p.name,p.parent_id,(SELECT q.name FROM ten_crm_pipelines q WHERE q.id=p.parent_id) pname FROM ten_crm_pipelines p WHERE p.is_active=1 ORDER BY (p.parent_id IS NOT NULL),p.display_order,p.id");
    if($r) while($row=$r->fetch_assoc()) $items[]=['key'=>'native:'.$row['id'],'name'=>($row['pname']?($row['pname'].' › '):'').$row['name'],'source'=>'native'];
    foreach ($items as &$it) $it['hidden'] = !empty($hidden[$it['key']]);
    ok(['projects'=>$items]);
}

case 'project_pref_set': {
    need_manage($canManage);
    $key = trim($P['key'] ?? '');
    $hide = ($P['hidden'] ?? '') === '1' ? 1 : 0;
    if ($key === '') fail('Missing key.');
    $k = $conn->real_escape_string($key);
    $conn->query("INSERT INTO ten_crm_project_prefs (user_id,project_key,hidden) VALUES ($userId,'$k',$hide)
                  ON DUPLICATE KEY UPDATE hidden=$hide");
    ok([]);
}

case 'project_view': {
    $key = $P['key'] ?? '';

    if ($key === 'pkv') {
        if (!crm_pkv_visible()) fail('No access to PKV.');
        // Live read-only from the PhiCRM/GPHI database (gphi.gphi_cms_clients) on this
        // server. NO health fields are read (no enc_health/enc_notes/dob/conditions);
        // writes are never performed. Authorised by William 2026-10-03.
        $contacts = []; $seen = []; $sites = []; $insurances = [];
        // German product codes/names → English labels (the data is German; the tool is EU-wide).
        $insEN = [
            'private'=>'Private Health Insurance (PKV)', 'pkv'=>'Private Health Insurance (PKV)',
            'pflege'=>'Long-term Care Insurance', 'zahn'=>'Dental Insurance',
            'bu'=>'Disability Insurance', 'leben'=>'Term Life Insurance',
            'rente'=>'Private Pension / Retirement', 'haftpflicht'=>'Personal Liability Insurance',
            'hausrat'=>'Home Contents Insurance', 'wohngebaeude'=>'Buildings Insurance',
            'rechtsschutz'=>'Legal Expenses Insurance', 'kfz'=>'Car / Motor Insurance',
            'unfall'=>'Accident Insurance', 'reise'=>'Travel Health Insurance', 'gewerbe'=>'Business Insurance',
            'private krankenversicherung (pkv)'=>'Private Health Insurance (PKV)',
            'pflegezusatzversicherung'=>'Long-term Care Insurance', 'zahnzusatzversicherung'=>'Dental Insurance',
            'berufsunfähigkeitsversicherung'=>'Disability Insurance', 'risikolebensversicherung'=>'Term Life Insurance',
            'private altersvorsorge / rente'=>'Private Pension / Retirement', 'privathaftpflicht'=>'Personal Liability Insurance',
            'hausratversicherung'=>'Home Contents Insurance', 'wohngebäudeversicherung'=>'Buildings Insurance',
            'rechtsschutzversicherung'=>'Legal Expenses Insurance', 'kfz-versicherung'=>'Car / Motor Insurance',
            'unfallversicherung'=>'Accident Insurance', 'reisekrankenversicherung'=>'Travel Health Insurance',
            'gewerbeversicherung'=>'Business Insurance',
        ];
        $toEN = function($v) use ($insEN) { $v = trim((string)$v); return $insEN[mb_strtolower($v)] ?? $v; };
        try {
            // Enquiries = gphi_quote_requests (the form submissions). Non-health fields only.
            // Exclude test/dummy enquiries and example.com test emails.
            $r = $conn->query("SELECT q.id, q.client_first_name, q.client_last_name, q.client_email, q.client_phone,
                                 q.insurance_type, q.status, q.source, q.created_at, q.updated_at,
                                 COALESCE(NULLIF(b.company_name,''), b.name) AS broker
                               FROM gphi.gphi_quote_requests q
                               LEFT JOIN gphi.gphi_brokers b ON b.id = q.broker_id
                               WHERE (q.is_dummy = 0 OR q.is_dummy IS NULL)
                                 AND (q.client_email IS NULL OR q.client_email NOT LIKE '%example.com')
                               ORDER BY q.created_at DESC, q.id DESC");
            if ($r) while ($row = $r->fetch_assoc()) {
                $st = $row['status'] ?: '—'; $seen[$st] = 1;
                // A blank source = a direct GPHI enquiry (no TEN-site referrer).
                $siteDisp = (trim((string)($row['source'] ?? '')) !== '') ? $row['source'] : 'GPHI';
                $sites[$siteDisp] = 1;
                if (($row['insurance_type'] ?? '') !== '') $insurances[$row['insurance_type']] = 1;
                $contacts[] = ['row_id'=>'g'.$row['id'], 'kind'=>'gphi', 'contact_id'=>null,
                    'name'=>trim(($row['client_first_name'] ?? '').' '.($row['client_last_name'] ?? '')),
                    'source_site'=>$siteDisp, 'broker'=>$row['broker'] ?: 'Unassigned',
                    'email'=>$row['client_email'], 'phone'=>$row['client_phone'], 'insurance'=>$toEN($row['insurance_type']),
                    'enq_date'=>$row['created_at'], 'last_action'=>$row['updated_at'],
                    'status'=>$st, 'status_label'=>$st, 'sub'=>$toEN($row['insurance_type'])];
            }
        } catch (Throwable $e) { $contacts = []; }
        $statuses = [];
        foreach (array_keys($seen) as $s) {
            $won = preg_match('/signed|won|success|closed_success|active/i', $s) ? 1 : 0;
            $lost = preg_match('/lost|fail|declin|cancel/i', $s) ? 1 : 0;
            $statuses[] = ['key'=>$s, 'label'=>$s, 'is_won'=>$won, 'is_lost'=>$lost];
        }
        // Full broker list from the PhiCRM broker table (for the broker dropdown filter).
        $brokerOpts = ['Unassigned'];
        try {
            $br = $conn->query("SELECT COALESCE(NULLIF(company_name,''), name) nm FROM gphi.gphi_brokers ORDER BY nm");
            if ($br) while ($row = $br->fetch_row()) { $n = trim((string)$row[0]); if ($n !== '' && !in_array($n, $brokerOpts, true)) $brokerOpts[] = $n; }
        } catch (Throwable $e) {}
        // Site options = GPHI, PhiCRM and EVERY live TEN publication (always shown, even
        // with no enquiries yet), merged with any source values already present.
        $siteKnown = ['GPHI', 'PhiCRM'];
        $pa = crm_admin_db();
        if ($pa) {
            try { $pr = $pa->query("SELECT title FROM publications WHERE pub_live='1' ORDER BY title");
                if ($pr) while ($prow = $pr->fetch_row()) { $tt = trim((string)$prow[0]); if ($tt !== '') $siteKnown[] = $tt; } }
            catch (Throwable $e) {}
            @$pa->close();
        }
        $siteSeen = []; $siteOpts = [];
        foreach (array_merge(array_keys($sites), $siteKnown) as $v) {
            $v = trim((string)$v); if ($v === '') continue;
            $lk = mb_strtolower($v); if (isset($siteSeen[$lk])) continue;
            $siteSeen[$lk] = 1; $siteOpts[] = $v;
        }
        sort($siteOpts, SORT_NATURAL | SORT_FLAG_CASE);
        // Insurance options = the GPHI product catalogue in ENGLISH + any present (translated).
        $insOpts = [];
        foreach (array_keys($insurances) as $v) { $en = $toEN($v); if ($en !== '' && !in_array($en, $insOpts, true)) $insOpts[] = $en; }
        try {
            $pr = $conn->query("SELECT DISTINCT code FROM gphi.gphi_cms_products WHERE code IS NOT NULL AND code<>''");
            if ($pr) while ($prow = $pr->fetch_row()) { $en = $toEN($prow[0]); if ($en !== '' && !in_array($en, $insOpts, true)) $insOpts[] = $en; }
        } catch (Throwable $e) {}
        sort($insOpts, SORT_NATURAL | SORT_FLAG_CASE);
        // Status options = the full enum (every possible status) + any present.
        $statusOpts = array_keys($seen);
        try {
            $sc = $conn->query("SHOW COLUMNS FROM gphi.gphi_quote_requests LIKE 'status'");
            if ($sc && ($scr = $sc->fetch_assoc()) && preg_match_all("/'([^']+)'/", $scr['Type'], $mm)) {
                foreach ($mm[1] as $ev) if (!in_array($ev, $statusOpts, true)) $statusOpts[] = $ev;
            }
        } catch (Throwable $e) {}
        sort($statusOpts, SORT_NATURAL | SORT_FLAG_CASE);
        $fields = [
            ['key'=>'name','label'=>'Name','filter'=>true,'sort'=>true],
            ['key'=>'source_site','label'=>'Site','filter'=>true,'sort'=>true,'options'=>$siteOpts],
            ['key'=>'broker','label'=>'Broker','filter'=>true,'sort'=>true,'options'=>$brokerOpts],
            ['key'=>'email','label'=>'Email','filter'=>true,'sort'=>true],
            ['key'=>'insurance','label'=>'Insurance','filter'=>true,'sort'=>true,'options'=>$insOpts],
            ['key'=>'enq_date','label'=>'Date of enquiry','sort'=>true,'kind'=>'date'],
            ['key'=>'last_action','label'=>'Last action','sort'=>true,'kind'=>'date'],
            ['key'=>'status','label'=>'Status','filter'=>true,'sort'=>true,'kind'=>'status','options'=>$statusOpts],
        ];
        ok(['meta'=>['key'=>'pkv', 'name'=>'PKV · PhiCRM / GPHI', 'source'=>'pkv', 'editable'=>false,
            'url'=>'https://phicrm.com', 'open_label'=>'Open PhiCRM', 'fields'=>$fields,
            'sub'=>'Live read-only from PhiCRM / GPHI — filter by broker or site'],
            'statuses'=>$statuses, 'contacts'=>$contacts]);
    }

    if (strpos($key, 'wne:') === 0) {
        $pid = (int)substr($key, 4);
        $proj = $conn->query("SELECT project_name, position_title, employer_company FROM ten_wne_projects WHERE id=$pid")->fetch_assoc();
        if (!$proj) fail('Recruitment project not found.');
        $contacts = []; $seen = [];
        $r = $conn->query("SELECT pc.id pcid, pc.current_stage, pc.submitted_at, c.id cand_id, c.full_name, c.email, c.phone, c.city, c.country
                           FROM ten_wne_project_candidates pc JOIN ten_cv_candidates c ON c.id=pc.candidate_id
                           WHERE pc.project_id=$pid AND pc.is_active=1 ORDER BY pc.submitted_at DESC, pc.id DESC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $st = $row['current_stage'] ?: 'Unassigned';
            $seen[$st] = 1;
            $loc = trim(($row['city'] ?? '').($row['country'] ? ', '.$row['country'] : ''));
            $contacts[] = ['row_id'=>'pc'.$row['pcid'], 'cand_id'=>(int)$row['cand_id'], 'kind'=>'wne',
                'contact_id'=>null, 'name'=>$row['full_name'], 'email'=>$row['email'], 'phone'=>$row['phone'],
                'detail'=>$loc, 'applied'=>$row['submitted_at'],
                'status'=>$st, 'status_label'=>$st, 'sub'=>$loc];
        }
        $statuses = []; foreach (array_keys($seen) as $s) $statuses[] = ['key'=>$s, 'label'=>$s, 'is_won'=>0, 'is_lost'=>0];
        $fields = [
            ['key'=>'name','label'=>'Candidate','filter'=>true,'sort'=>true],
            ['key'=>'detail','label'=>'Location','filter'=>true,'sort'=>true],
            ['key'=>'email','label'=>'Email','filter'=>true,'sort'=>true],
            ['key'=>'phone','label'=>'Phone','filter'=>true,'sort'=>true],
            ['key'=>'applied','label'=>'Submitted','sort'=>true,'kind'=>'date'],
            ['key'=>'status','label'=>'Stage','filter'=>true,'sort'=>true,'kind'=>'status'],
        ];
        ok(['meta'=>['key'=>$key, 'name'=>$proj['project_name'], 'source'=>'wne', 'editable'=>false, 'url'=>'module-wne.php',
            'open_label'=>'Open ATS', 'fields'=>$fields,
            'sub'=>trim(($proj['position_title'] ?: '').($proj['employer_company'] ? ' · '.$proj['employer_company'] : '')).' — managed in the WNE ATS'],
            'statuses'=>$statuses, 'contacts'=>$contacts]);
    }

    if (strpos($key, 'native:') === 0) {
        $pid = (int)substr($key, 7);
        $proj = $conn->query("SELECT * FROM ten_crm_pipelines WHERE id=$pid")->fetch_assoc();
        if (!$proj) fail('Project not found.');
        $statuses = [];
        $r = $conn->query("SELECT id, name, is_won, is_lost FROM ten_crm_stages WHERE pipeline_id=$pid ORDER BY display_order, id");
        if ($r) while ($row = $r->fetch_assoc()) $statuses[] = ['key'=>(string)$row['id'], 'label'=>$row['name'], 'is_won'=>(int)$row['is_won'], 'is_lost'=>(int)$row['is_lost']];
        $contacts = [];
        $r = $conn->query("SELECT l.id lead_id, l.stage_id, l.status, l.value, ct.id contact_id,
                             ct.first_name, ct.last_name, ct.company, ct.email, ct.phone, ct.city, ct.country,
                             s.name stage_name
                           FROM ten_crm_leads l JOIN ten_ec_contacts ct ON ct.id=l.contact_id
                           LEFT JOIN ten_crm_stages s ON s.id=l.stage_id
                           WHERE l.pipeline_id=$pid ORDER BY l.updated_at DESC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $contacts[] = ['row_id'=>'l'.$row['lead_id'], 'lead_id'=>(int)$row['lead_id'], 'kind'=>'native',
                'contact_id'=>(int)$row['contact_id'],
                'name'=>trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
                'company'=>$row['company'] ?: '', 'email'=>$row['email'], 'phone'=>$row['phone'],
                'status'=>$row['stage_id'] !== null ? (string)$row['stage_id'] : '', 'status_label'=>$row['stage_name'] ?: '—',
                'sub'=>trim(($row['city'] ?? '').($row['country'] ? ', '.$row['country'] : ''))];
        }
        $fields = [
            ['key'=>'name','label'=>'Name','filter'=>true,'sort'=>true],
            ['key'=>'company','label'=>'Company','filter'=>true,'sort'=>true],
            ['key'=>'email','label'=>'Email','filter'=>true,'sort'=>true],
            ['key'=>'phone','label'=>'Phone','filter'=>true,'sort'=>true],
            ['key'=>'status','label'=>'Status','filter'=>true,'sort'=>true,'kind'=>'status'],
        ];
        ok(['meta'=>['key'=>$key, 'name'=>$proj['name'], 'source'=>'native', 'editable'=>true, 'url'=>null, 'fields'=>$fields,
            'sub'=>$proj['description'] ?: 'CRM project — add, remove and move contacts freely'],
            'statuses'=>$statuses, 'contacts'=>$contacts]);
    }

    if ($key === 'email') {
        // Audiences are the CRM-relevant unit of the Email Campaign Manager.
        $contacts = [];
        $r = $conn->query("SELECT a.id, a.name, a.description,
                             (SELECT COUNT(*) FROM ten_ec_audience_members m WHERE m.audience_id=a.id) members,
                             (SELECT COUNT(*) FROM ten_ec_audience_members m JOIN ten_ec_contacts ct ON ct.id=m.contact_id
                               WHERE m.audience_id=a.id AND ct.email IS NOT NULL AND ct.email<>''
                               AND NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)) sendable
                           FROM ten_ec_audiences a ORDER BY a.name");
        if ($r) while ($row = $r->fetch_assoc()) {
            $contacts[] = ['row_id'=>'a'.$row['id'], 'kind'=>'email', 'contact_id'=>null,
                'name'=>$row['name'], 'detail'=>$row['description'] ?: '', 'email'=>'', 'phone'=>'',
                'members'=>(int)$row['members'], 'sendable'=>(int)$row['sendable'],
                'status'=>'audience', 'status_label'=>$row['members'].' members · '.$row['sendable'].' sendable',
                'sub'=>$row['description'] ?: ''];
        }
        $fields = [
            ['key'=>'name','label'=>'Audience','filter'=>true,'sort'=>true],
            ['key'=>'detail','label'=>'Description','filter'=>true,'sort'=>true],
            ['key'=>'status','label'=>'Size','sort'=>false,'kind'=>'status'],
        ];
        ok(['meta'=>['key'=>'email', 'name'=>'Email Campaigns', 'source'=>'email', 'editable'=>false,
            'url'=>'module-email-campaigns.php', 'open_label'=>'Open Email tool', 'fields'=>$fields,
            'sub'=>'Audiences in the Email Campaign Manager — build & send from the email tool'],
            'statuses'=>[['key'=>'audience', 'label'=>'Audience', 'is_won'=>0, 'is_lost'=>0]], 'contacts'=>$contacts]);
    }

    fail('Unknown project.');
}

// ─────────────────────────────────────────────────────── PROJECTS / TOOLS ──
case 'projects_overview': {
    // Real projects from the project-management module (tolerant).
    $projects = [];
    try {
        $r = $conn->query("SELECT p.id, p.project_name AS name, p.status FROM ten_projects p
                           WHERE (p.status IS NULL OR p.status NOT IN('archived','deleted','completed','cancelled','closed'))
                           ORDER BY p.id DESC LIMIT 40");
        if ($r) while ($row = $r->fetch_assoc()) {
            $pid = (int)$row['id'];
            $row['tasks']      = crm_safe_scalar($conn, "SELECT COUNT(*) FROM ten_project_tasks WHERE project_id=$pid") ?? 0;
            $row['tasks_done'] = crm_safe_scalar($conn, "SELECT COUNT(*) FROM ten_project_tasks WHERE project_id=$pid AND status IN('done','completed')") ?? 0;
            $projects[] = $row;
        }
    } catch (Throwable $e) { $projects = []; }

    // Every tool this user can open (same permission gate as the sidebar).
    $tools = [];
    try {
    $gr = $conn->query("SELECT * FROM ten_module_groups ORDER BY display_order");
    if ($gr) while ($g = $gr->fetch_assoc()) {
        $gk = $g['group_key'];
        $ms = [];
        $st = $conn->prepare("SELECT m.module_name, m.module_icon, m.module_url FROM ten_modules m
                               WHERE m.module_group=? AND m.is_enabled=1
                               AND (m.required_permission IS NULL OR m.required_permission IN (
                                   SELECT p.permission_key FROM ten_user_roles ur
                                   JOIN ten_role_permissions rp ON ur.role_id=rp.role_id
                                   JOIN ten_permissions p ON rp.permission_id=p.id
                                   WHERE ur.user_id=?))
                               ORDER BY m.display_order");
        if ($st) { $st->bind_param('si', $gk, $userId); $st->execute(); $res = $st->get_result();
            while ($mm = $res->fetch_assoc()) $ms[] = $mm; $st->close(); }
        if ($ms) $tools[] = ['group' => $g['group_name'], 'icon' => $g['group_icon'], 'modules' => $ms];
    }
    } catch (Throwable $e) { /* leave tools as-is */ }

    ok(['projects' => $projects, 'tools' => $tools, 'areas' => crm_business_areas($conn)]);
}

// ─────────────────────────────────────────────────────────── VOCAB ──
case 'vocab': {
    // Union the managed pick-lists with the DISTINCT values actually present on
    // contacts, so the filter dropdowns are always complete (nothing in use is
    // missing). Case-insensitive de-dup, sorted.
    $merge = function(string $sqlVocab, string $sqlDistinct) use ($conn) {
        $seen = []; $out = [];
        foreach ([$sqlVocab, $sqlDistinct] as $sql) {
            $r = @$conn->query($sql);
            if ($r) while ($row = $r->fetch_row()) {
                $v = trim((string)$row[0]); if ($v === '') continue;
                $k = mb_strtolower($v); if (isset($seen[$k])) continue;
                $seen[$k] = 1; $out[] = $v;
            }
        }
        natcasesort($out);
        return array_values($out);
    };
    $cats = $merge("SELECT name FROM ten_ec_vocab WHERE kind='category'",
                   "SELECT DISTINCT category FROM ten_ec_contacts WHERE category IS NOT NULL AND category<>''");
    $countries = $merge("SELECT name FROM ten_ec_vocab WHERE kind='country'",
                        "SELECT DISTINCT country FROM ten_ec_contacts WHERE country IS NOT NULL AND country<>''");
    // Guarantee the full set of countries is always offered, regardless of what
    // has been seeded into ten_ec_vocab or is present on contacts.
    $world = ['Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo (Brazzaville)','Congo (Kinshasa)','Costa Rica','Croatia','Cuba','Cyprus','Czechia','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Ivory Coast','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kiribati','Kosovo','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Korea','North Macedonia','Norway','Oman','Pakistan','Palau','Palestine','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Vatican City','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
    $seenC = []; foreach ($countries as $v) $seenC[mb_strtolower($v)] = 1;
    foreach ($world as $w) { if (!isset($seenC[mb_strtolower($w)])) { $seenC[mb_strtolower($w)] = 1; $countries[] = $w; } }
    natcasesort($countries); $countries = array_values($countries);
    ok(['categories' => $cats, 'countries' => $countries]);
}

// ─────────────────────────────────────────────────────────── CONTACTS ──
case 'contacts_list': {
    $q        = trim($P['q'] ?? '');
    $category = trim($P['category'] ?? '');
    $country  = trim($P['country'] ?? '');
    $hasEmail = ($P['has_email'] ?? '') === '1';
    $hideSup  = ($P['hide_suppressed'] ?? '') === '1';
    $page     = max(1, (int)($P['page'] ?? 1));
    $per      = min(200, max(10, (int)($P['per_page'] ?? 50)));
    $offset   = ($page - 1) * $per;

    $sortMap = ['name'=>'ct.first_name','email'=>'ct.email','company'=>'ct.company',
                'category'=>'ct.category','location'=>'ct.country','emails'=>'times_sent',
                'pipelines'=>'lead_count','created'=>'ct.id'];
    $sort = $sortMap[$P['sort'] ?? ''] ?? 'ct.id';
    $dir  = strtolower($P['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $where = crm_contacts_where($conn, $P);

    $total = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_contacts ct $where")->fetch_assoc()['n'];

    $rows = [];
    $sql = "SELECT ct.id, ct.first_name, ct.last_name, ct.email, ct.company, ct.phone,
                   ct.category, ct.country, ct.city,
                   EXISTS(SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email) AS suppressed,
                   (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.contact_id=ct.id) AS lead_count,
                   (SELECT COUNT(*) FROM ten_ec_sent_log sl WHERE sl.contact_id=ct.id) AS times_sent
            FROM ten_ec_contacts ct $where
            ORDER BY $sort $dir LIMIT $per OFFSET $offset";
    $r = $conn->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;

    ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
}

case 'contact_get': {
    $id = (int)($P['id'] ?? 0);
    if (!$id) fail('Missing contact id.');
    $ct = $conn->query("SELECT * FROM ten_ec_contacts WHERE id=$id")->fetch_assoc();
    if (!$ct) fail('Contact not found.');

    // Lead memberships
    $leads = [];
    $r = $conn->query("SELECT l.id, l.stage_id, l.status, l.value, l.pipeline_id,
                         p.name pipeline_name, s.name stage_name
                       FROM ten_crm_leads l
                       JOIN ten_crm_pipelines p ON p.id=l.pipeline_id
                       LEFT JOIN ten_crm_stages s ON s.id=l.stage_id
                       WHERE l.contact_id=$id ORDER BY l.updated_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $leads[] = $row;

    // CRM activities
    $acts = [];
    $r = $conn->query("SELECT a.*, u.full_name AS user_name FROM ten_crm_activities a
                       LEFT JOIN ten_users u ON u.id=a.user_id
                       WHERE a.contact_id=$id ORDER BY a.created_at DESC LIMIT 100");
    if ($r) while ($row = $r->fetch_assoc()) $acts[] = $row;

    $timeline = crm_email_timeline($conn, $id, (string)($ct['email'] ?? ''));
    $suppression = crm_contact_suppression($conn, (string)($ct['email'] ?? ''));

    ok(['contact' => $ct, 'leads' => $leads, 'activities' => $acts,
        'timeline' => $timeline, 'suppression' => $suppression]);
}

case 'contact_save': {
    need_manage($canManage);
    $id    = (int)($P['id'] ?? 0);
    $email = strtolower(trim($P['email'] ?? ''));
    $cols  = ec_contact_columns();

    if ($id) {
        // Edit existing by id. Guard email collision against a different row.
        if ($email !== '') {
            $e = $conn->real_escape_string($email);
            $dup = $conn->query("SELECT id FROM ten_ec_contacts WHERE email='$e' AND id<>$id LIMIT 1");
            if ($dup && $dup->num_rows) fail('Another contact already uses that email address.');
        }
        $set = ["email=" . ($email === '' ? "NULL" : "'" . $conn->real_escape_string($email) . "'")];
        foreach ($cols as $k) {
            $v = trim((string)($P[$k] ?? ''));
            $set[] = "$k='" . $conn->real_escape_string($v) . "'";
        }
        $set[] = "updated_at=NOW()";
        $conn->query("UPDATE ten_ec_contacts SET " . implode(',', $set) . " WHERE id=$id");
        if (($P['category'] ?? '') !== '') ec_vocab_absorb($conn, 'category', $P['category']);
        if (($P['country'] ?? '') !== '')  ec_vocab_absorb($conn, 'country', $P['country']);
        ok(['id' => $id]);
    } else {
        $d = ['email' => $email];
        foreach ($cols as $k) $d[$k] = trim((string)($P[$k] ?? ''));
        [$nid, $ins] = ec_upsert_contact($conn, $d);
        if (($P['category'] ?? '') !== '') ec_vocab_absorb($conn, 'category', $P['category']);
        if (($P['country'] ?? '') !== '')  ec_vocab_absorb($conn, 'country', $P['country']);
        ok(['id' => $nid, 'inserted' => $ins]);
    }
}

// ─────────────────────────────────────────────────────────── PIPELINES ──
case 'pipelines_list': {
    $rows = [];
    $r = $conn->query("SELECT p.id, p.name, p.description, p.accent,
                        (SELECT COUNT(*) FROM ten_crm_stages s WHERE s.pipeline_id=p.id) stage_count,
                        (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.pipeline_id=p.id) lead_count,
                        (SELECT COUNT(*) FROM ten_crm_leads l WHERE l.pipeline_id=p.id AND l.status='open') open_count
                       FROM ten_crm_pipelines p WHERE p.is_active=1 ORDER BY p.display_order, p.id");
    if ($r) while ($row = $r->fetch_assoc()) { $row['type'] = 'manual'; $rows[] = $row; }
    if (crm_pkv_visible()) {
        $board = crm_pkv_board($conn);
        $open = 0; foreach ($board['leads'] as $l) { if (!in_array($l['stage_key'], ['closed_success','closed_failed'], true)) $open++; }
        $rows[] = ['id' => 'pkv', 'name' => 'PKV · PhiCRM / GPHI', 'description' => 'Live from the PKV broker tool',
                   'accent' => '#1f4e79', 'type' => 'pkv', 'stage_count' => count($board['stages']),
                   'lead_count' => count($board['leads']), 'open_count' => $open];
    }
    ok(['pipelines' => $rows]);
}

case 'pipeline_board': {
    $pid = $P['pipeline'] ?? '';
    if ($pid === 'pkv') {
        if (!crm_pkv_visible()) fail('No access to PKV.');
        $board = crm_pkv_board($conn);
        ok(['type' => 'pkv', 'pipeline' => ['id' => 'pkv', 'name' => 'PKV · PhiCRM / GPHI'],
            'stages' => $board['stages'], 'leads' => $board['leads']]);
    }
    $pid = (int)$pid;
    $p = $conn->query("SELECT * FROM ten_crm_pipelines WHERE id=$pid")->fetch_assoc();
    if (!$p) fail('Pipeline not found.');
    $stages = [];
    $r = $conn->query("SELECT * FROM ten_crm_stages WHERE pipeline_id=$pid ORDER BY display_order, id");
    if ($r) while ($row = $r->fetch_assoc()) $stages[] = $row;
    $leads = [];
    $r = $conn->query("SELECT l.id, l.stage_id, l.status, l.value, l.owner_id, l.note, l.last_activity_at, l.contact_id,
                         ct.first_name, ct.last_name, ct.company, ct.email, ct.city, ct.country,
                         EXISTS(SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email) suppressed,
                         u.full_name owner_name
                       FROM ten_crm_leads l
                       JOIN ten_ec_contacts ct ON ct.id=l.contact_id
                       LEFT JOIN ten_users u ON u.id=l.owner_id
                       WHERE l.pipeline_id=$pid ORDER BY l.updated_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $leads[] = $row;
    ok(['type' => 'manual', 'pipeline' => $p, 'stages' => $stages, 'leads' => $leads]);
}

case 'pipeline_save': {
    need_manage($canManage);
    $id   = (int)($P['id'] ?? 0);
    $name = trim($P['name'] ?? '');
    $desc = trim($P['description'] ?? '');
    $accent = trim($P['accent'] ?? '#1f4e79');
    if ($name === '') fail('Pipeline name is required.');
    if ($id) {
        $st = $conn->prepare("UPDATE ten_crm_pipelines SET name=?, description=?, accent=? WHERE id=?");
        $st->bind_param('sssi', $name, $desc, $accent, $id); $st->execute(); $st->close();
        ok(['id' => $id]);
    } else {
        $slug = ec_slug($name);
        $parent = ($P['parent_id'] ?? '') !== '' ? (int)$P['parent_id'] : null;
        $cat = trim($P['category'] ?? '');
        $pub = trim($P['publication'] ?? '');
        $ord  = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 n FROM ten_crm_pipelines")->fetch_assoc()['n'];
        $st = $conn->prepare("INSERT INTO ten_crm_pipelines (name,slug,description,accent,display_order,parent_id,category,publication,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->bind_param('ssssiissi', $name, $slug, $desc, $accent, $ord, $parent, $cat, $pub, $userId); $st->execute();
        $nid = (int)$conn->insert_id; $st->close();
        // default stages
        $stages = [['New',0,0],['Contacted',0,0],['Qualified',0,0],['Won',1,0],['Lost',0,1]];
        $ss = $conn->prepare("INSERT INTO ten_crm_stages (pipeline_id,name,display_order,is_won,is_lost) VALUES (?,?,?,?,?)");
        foreach ($stages as $i => $s) { $ss->bind_param('isiii', $nid, $s[0], $i, $s[1], $s[2]); $ss->execute(); }
        $ss->close();
        ok(['id' => $nid]);
    }
}

case 'pipeline_delete': {
    need_manage($canManage);
    $id = (int)($P['id'] ?? 0);
    if (!$id) fail('Missing pipeline id.');
    $conn->query("DELETE h FROM ten_crm_lead_history h JOIN ten_crm_leads l ON l.id=h.lead_id WHERE l.pipeline_id=$id");
    $conn->query("DELETE FROM ten_crm_leads WHERE pipeline_id=$id");
    $conn->query("DELETE FROM ten_crm_stages WHERE pipeline_id=$id");
    $conn->query("DELETE FROM ten_crm_pipelines WHERE id=$id");
    ok([]);
}

case 'stage_save': {
    need_manage($canManage);
    $id   = (int)($P['id'] ?? 0);
    $pid  = (int)($P['pipeline_id'] ?? 0);
    $name = trim($P['name'] ?? '');
    $isWon  = ($P['is_won'] ?? '') === '1' ? 1 : 0;
    $isLost = ($P['is_lost'] ?? '') === '1' ? 1 : 0;
    if ($name === '') fail('Stage name is required.');
    if ($id) {
        $st = $conn->prepare("UPDATE ten_crm_stages SET name=?, is_won=?, is_lost=? WHERE id=?");
        $st->bind_param('siii', $name, $isWon, $isLost, $id); $st->execute(); $st->close();
        ok(['id' => $id]);
    } else {
        if (!$pid) fail('Missing pipeline id.');
        $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 n FROM ten_crm_stages WHERE pipeline_id=$pid")->fetch_assoc()['n'];
        $st = $conn->prepare("INSERT INTO ten_crm_stages (pipeline_id,name,display_order,is_won,is_lost) VALUES (?,?,?,?,?)");
        $st->bind_param('isiii', $pid, $name, $ord, $isWon, $isLost); $st->execute();
        $nid = (int)$conn->insert_id; $st->close();
        ok(['id' => $nid]);
    }
}

case 'stage_delete': {
    need_manage($canManage);
    $id = (int)($P['id'] ?? 0);
    if (!$id) fail('Missing stage id.');
    $srow = $conn->query("SELECT pipeline_id FROM ten_crm_stages WHERE id=$id")->fetch_assoc();
    if (!$srow) fail('Stage not found.');
    $pid = (int)$srow['pipeline_id'];
    // Move any leads in this stage to the first other stage of the pipeline.
    $other = $conn->query("SELECT id FROM ten_crm_stages WHERE pipeline_id=$pid AND id<>$id ORDER BY display_order, id LIMIT 1")->fetch_assoc();
    if ($other) $conn->query("UPDATE ten_crm_leads SET stage_id=" . (int)$other['id'] . " WHERE stage_id=$id");
    else        $conn->query("UPDATE ten_crm_leads SET stage_id=NULL WHERE stage_id=$id");
    $conn->query("DELETE FROM ten_crm_stages WHERE id=$id");
    ok([]);
}

case 'stages_reorder': {
    need_manage($canManage);
    $ids = $P['order'] ?? '';
    $arr = is_array($ids) ? $ids : array_filter(explode(',', $ids));
    $i = 0;
    foreach ($arr as $sid) { $sid = (int)$sid; $conn->query("UPDATE ten_crm_stages SET display_order=$i WHERE id=$sid"); $i++; }
    ok([]);
}

// ─────────────────────────────────────────────────────────── LEADS ──
case 'leads_list': {
    $q      = trim($P['q'] ?? '');
    $pid    = (int)($P['pipeline_id'] ?? 0);
    $status = trim($P['status'] ?? '');
    $page   = max(1, (int)($P['page'] ?? 1));
    $per    = min(200, max(10, (int)($P['per_page'] ?? 50)));
    $offset = ($page - 1) * $per;

    $conds = ['1=1'];
    if ($pid) $conds[] = "l.pipeline_id=$pid";
    if ($status !== '' && in_array($status, ['open','won','lost','on_hold'], true)) $conds[] = "l.status='$status'";
    if ($q !== '') { $qe = '%' . $conn->real_escape_string($q) . '%';
        $conds[] = "(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe')"; }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $total = (int)$conn->query("SELECT COUNT(*) n FROM ten_crm_leads l JOIN ten_ec_contacts ct ON ct.id=l.contact_id $where")->fetch_assoc()['n'];
    $rows = [];
    $sql = "SELECT l.id, l.contact_id, l.status, l.value, l.stage_id, l.pipeline_id, l.updated_at,
                   ct.first_name, ct.last_name, ct.company, ct.email,
                   p.name pipeline_name, s.name stage_name, u.full_name owner_name
            FROM ten_crm_leads l
            JOIN ten_ec_contacts ct ON ct.id=l.contact_id
            JOIN ten_crm_pipelines p ON p.id=l.pipeline_id
            LEFT JOIN ten_crm_stages s ON s.id=l.stage_id
            LEFT JOIN ten_users u ON u.id=l.owner_id
            $where ORDER BY l.updated_at DESC LIMIT $per OFFSET $offset";
    $r = $conn->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
}

case 'lead_create': {
    need_manage($canManage);
    $pid = (int)($P['pipeline_id'] ?? 0);
    $cid = (int)($P['contact_id'] ?? 0);
    if (!$pid || !$cid) fail('Pick a pipeline and a contact.');
    $exists = $conn->query("SELECT id FROM ten_crm_leads WHERE pipeline_id=$pid AND contact_id=$cid LIMIT 1");
    if ($exists && $exists->num_rows) fail('That contact is already a lead in this pipeline.');
    $stageId = (int)($P['stage_id'] ?? 0);
    if (!$stageId) {
        $s = $conn->query("SELECT id FROM ten_crm_stages WHERE pipeline_id=$pid ORDER BY display_order, id LIMIT 1")->fetch_assoc();
        $stageId = $s ? (int)$s['id'] : 0;
    }
    $owner = (int)($P['owner_id'] ?? $userId) ?: null;
    $value = ($P['value'] ?? '') !== '' ? (float)$P['value'] : null;
    $st = $conn->prepare("INSERT INTO ten_crm_leads (pipeline_id,contact_id,stage_id,owner_id,value,created_by,last_activity_at) VALUES (?,?,?,?,?,?,NOW())");
    $sid = $stageId ?: null;
    $st->bind_param('iiiidi', $pid, $cid, $sid, $owner, $value, $userId);
    $st->execute(); $nid = (int)$conn->insert_id; $st->close();
    $conn->query("INSERT INTO ten_crm_lead_history (lead_id,to_stage_id,user_id,note) VALUES ($nid," . ($sid ? $sid : 'NULL') . ",$userId,'Lead created')");
    ok(['id' => $nid]);
}

case 'lead_move': {
    need_manage($canManage);
    $lid  = (int)($P['lead_id'] ?? 0);
    $to   = (int)($P['to_stage_id'] ?? 0);
    $note = trim($P['note'] ?? '');
    if (!$lid || !$to) fail('Missing lead or stage.');
    $lead = $conn->query("SELECT stage_id, pipeline_id FROM ten_crm_leads WHERE id=$lid")->fetch_assoc();
    if (!$lead) fail('Lead not found.');
    $stage = $conn->query("SELECT is_won, is_lost FROM ten_crm_stages WHERE id=$to")->fetch_assoc();
    $status = 'open';
    if ($stage && $stage['is_won']) $status = 'won';
    elseif ($stage && $stage['is_lost']) $status = 'lost';
    $from = $lead['stage_id'] !== null ? (int)$lead['stage_id'] : null;
    $st = $conn->prepare("UPDATE ten_crm_leads SET stage_id=?, status=?, last_activity_at=NOW() WHERE id=?");
    $st->bind_param('isi', $to, $status, $lid); $st->execute(); $st->close();
    $fromSql = $from !== null ? $from : 'NULL';
    $nst = $conn->prepare("INSERT INTO ten_crm_lead_history (lead_id,from_stage_id,to_stage_id,user_id,note) VALUES (?,?,?,?,?)");
    $nst->bind_param('iiiis', $lid, $from, $to, $userId, $note); $nst->execute(); $nst->close();
    ok(['status' => $status]);
}

case 'lead_update': {
    need_manage($canManage);
    $lid = (int)($P['lead_id'] ?? 0);
    if (!$lid) fail('Missing lead.');
    $owner = ($P['owner_id'] ?? '') !== '' ? (int)$P['owner_id'] : null;
    $value = ($P['value'] ?? '') !== '' ? (float)$P['value'] : null;
    $note  = trim($P['note'] ?? '');
    $status = in_array($P['status'] ?? '', ['open','won','lost','on_hold'], true) ? $P['status'] : null;
    $set = ['last_activity_at=NOW()'];
    $set[] = 'owner_id=' . ($owner !== null ? $owner : 'NULL');
    $set[] = 'value=' . ($value !== null ? $value : 'NULL');
    $set[] = "note='" . $conn->real_escape_string($note) . "'";
    if ($status) $set[] = "status='$status'";
    $conn->query("UPDATE ten_crm_leads SET " . implode(',', $set) . " WHERE id=$lid");
    ok([]);
}

case 'lead_delete': {
    need_manage($canManage);
    $lid = (int)($P['lead_id'] ?? 0);
    if (!$lid) fail('Missing lead.');
    $conn->query("DELETE FROM ten_crm_lead_history WHERE lead_id=$lid");
    $conn->query("DELETE FROM ten_crm_leads WHERE id=$lid");
    ok([]);
}

// ─────────────────────────────────────────────────────────── ACTIVITIES ──
case 'activity_add': {
    need_manage($canManage);
    $cid = (int)($P['contact_id'] ?? 0);
    if (!$cid) fail('Missing contact.');
    $lid  = ($P['lead_id'] ?? '') !== '' ? (int)$P['lead_id'] : null;
    $type = in_array($P['type'] ?? '', ['note','call','email','meeting','task'], true) ? $P['type'] : 'note';
    $subj = trim($P['subject'] ?? '');
    $body = trim($P['body'] ?? '');
    $due  = trim($P['due_at'] ?? '');
    $dueSql = $due !== '' ? "'" . $conn->real_escape_string($due) . "'" : 'NULL';
    $st = $conn->prepare("INSERT INTO ten_crm_activities (contact_id,lead_id,user_id,type,subject,body,due_at) VALUES (?,?,?,?,?,?," . ($due !== '' ? '?' : 'NULL') . ")");
    if ($due !== '') $st->bind_param('iiissss', $cid, $lid, $userId, $type, $subj, $body, $due);
    else             $st->bind_param('iiisss',  $cid, $lid, $userId, $type, $subj, $body);
    $st->execute(); $nid = (int)$conn->insert_id; $st->close();
    if ($lid) $conn->query("UPDATE ten_crm_leads SET last_activity_at=NOW() WHERE id=" . (int)$lid);
    ok(['id' => $nid]);
}

case 'activity_done': {
    need_manage($canManage);
    $id = (int)($P['id'] ?? 0);
    $done = ($P['done'] ?? '1') === '0' ? 0 : 1;
    $conn->query("UPDATE ten_crm_activities SET done=$done WHERE id=$id");
    ok([]);
}

case 'activity_delete': {
    need_manage($canManage);
    $id = (int)($P['id'] ?? 0);
    $conn->query("DELETE FROM ten_crm_activities WHERE id=$id");
    ok([]);
}

// ─────────────────────────────────────────────────────── PKV ENQUIRY ──
case 'pkv_enquiry_get': {
    if (!crm_pkv_visible()) fail('No access to PKV.');
    $eid = (int)($P['enquiry_id'] ?? 0);
    if (!$eid) fail('Missing enquiry id.');
    $where = "e.id=$eid";
    if (crm_pkv_external_broker()) {
        $b = $conn->query("SELECT id FROM ten_pkv_brokers WHERE user_id=$userId LIMIT 1");
        $brow = $b ? $b->fetch_assoc() : null;
        if (!$brow) fail('No access.');
        $where .= ' AND e.assigned_broker_id=' . (int)$brow['id'];
    }
    $e = $conn->query("SELECT e.*, b.company_name broker_name FROM ten_pkv_enquiries e
                       LEFT JOIN ten_pkv_brokers b ON b.id=e.assigned_broker_id WHERE $where")->fetch_assoc();
    if (!$e) fail('Enquiry not found.');
    $contact = null; $timeline = [];
    if (!empty($e['email'])) {
        $ec = $conn->query("SELECT id FROM ten_ec_contacts WHERE email='" . $conn->real_escape_string($e['email']) . "' LIMIT 1")->fetch_assoc();
        if ($ec) { $cid = (int)$ec['id']; $contact = ['id' => $cid]; $timeline = crm_email_timeline($conn, $cid, (string)$e['email']); }
    }
    ok(['enquiry' => $e, 'contact' => $contact, 'timeline' => $timeline]);
}

// ─────────────────────────────────────────────────── AUDIENCES / PUSH ──
case 'audiences_list': {
    $rows = [];
    $r = $conn->query("SELECT a.id, a.name,
                        (SELECT COUNT(*) FROM ten_ec_audience_members m WHERE m.audience_id=a.id) members
                       FROM ten_ec_audiences a ORDER BY a.name");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    ok(['audiences' => $rows]);
}

case 'push_to_audience': {
    need_manage($canManage);
    @set_time_limit(0);
    $aid     = (int)($P['audience_id'] ?? 0);
    $newName = trim($P['new_name'] ?? '');

    // Resolve the target set. Supports: select_all + filter, explicit contact_ids,
    // or all contacts of a pipeline (optionally one stage). Sendable only.
    if (!empty($P['pipeline_id']) && ($P['select_all'] ?? '') !== '1' && empty($P['contact_ids'])) {
        $pid = (int)$P['pipeline_id'];
        $stageClause = ($P['stage_id'] ?? '') !== '' ? ' AND l.stage_id=' . (int)$P['stage_id'] : '';
        $ids = [];
        $r = $conn->query("SELECT DISTINCT l.contact_id FROM ten_crm_leads l JOIN ten_ec_contacts ct ON ct.id=l.contact_id
                           WHERE l.pipeline_id=$pid$stageClause AND ct.email IS NOT NULL AND ct.email<>''
                           AND NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)");
        if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
        $selectedTotal = count($ids);
    } else {
        $ids = crm_resolve_contact_ids($conn, $P, true);
        $selectedTotal = count($ids);
    }
    if (!$ids) fail('No sendable contacts in the selection (need an email and not suppressed).');

    if (!$aid) {
        if ($newName === '') fail('Enter a name for the new audience.');
        $st = $conn->prepare("INSERT INTO ten_ec_audiences (name,description,filter_json,created_by) VALUES (?,?,?,?)");
        $desc = 'Created from CRM'; $fj = null;
        $st->bind_param('sssi', $newName, $desc, $fj, $userId); $st->execute();
        $aid = (int)$conn->insert_id; $st->close();
    }

    $added = 0;
    $ins = $conn->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)");
    foreach ($ids as $cid) { $ins->bind_param('ii', $aid, $cid); $ins->execute(); if ($conn->affected_rows > 0) $added++; }
    $ins->close();

    $members = (int)$conn->query("SELECT COUNT(*) n FROM ten_ec_audience_members WHERE audience_id=$aid")->fetch_assoc()['n'];
    ok(['audience_id' => $aid, 'sendable' => $selectedTotal, 'added' => $added, 'members' => $members]);
}

case 'bulk_add_to_pipeline': {
    need_manage($canManage);
    @set_time_limit(0);
    $pid = (int)($P['pipeline_id'] ?? 0);
    if (!$pid) fail('Pick a pipeline.');
    $p = $conn->query("SELECT id FROM ten_crm_pipelines WHERE id=$pid")->fetch_assoc();
    if (!$p) fail('Pipeline not found.');
    $stageId = (int)($P['stage_id'] ?? 0);
    if (!$stageId) {
        $s = $conn->query("SELECT id FROM ten_crm_stages WHERE pipeline_id=$pid ORDER BY display_order, id LIMIT 1")->fetch_assoc();
        $stageId = $s ? (int)$s['id'] : 0;
    }
    $ids = crm_resolve_contact_ids($conn, $P, false); // pipeline leads may be emailless
    if (!$ids) fail('No contacts selected.');

    $added = 0; $owner = $userId ?: null; $sid = $stageId ?: null;
    $ins = $conn->prepare("INSERT IGNORE INTO ten_crm_leads (pipeline_id,contact_id,stage_id,owner_id,created_by,last_activity_at) VALUES (?,?,?,?,?,NOW())");
    foreach ($ids as $cid) { $ins->bind_param('iiiii', $pid, $cid, $sid, $owner, $userId); $ins->execute(); if ($conn->affected_rows > 0) $added++; }
    $ins->close();
    ok(['added' => $added, 'selected' => count($ids)]);
}

// ─────────────────────────────────────────────────────── CONTACT PICKER ──
case 'contact_pick': {
    // Lightweight searchable list for pickers (lead-create, push targets).
    $q = trim($P['q'] ?? '');
    $onlyEmail = ($P['has_email'] ?? '') === '1';
    $conds = ['1=1'];
    if ($q !== '') { $qe = '%' . $conn->real_escape_string($q) . '%';
        $conds[] = "(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe')"; }
    if ($onlyEmail) $conds[] = "ct.email IS NOT NULL AND ct.email<>''";
    $where = 'WHERE ' . implode(' AND ', $conds);
    $rows = [];
    $r = $conn->query("SELECT ct.id, ct.first_name, ct.last_name, ct.company, ct.email, ct.city, ct.country
                       FROM ten_ec_contacts ct $where ORDER BY ct.company, ct.last_name LIMIT 100");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    ok(['rows' => $rows]);
}

// ─────────────────────────────────────────────────────── USERS (owners) ──
case 'users_list': {
    $rows = [];
    $r = $conn->query("SELECT id, full_name FROM ten_users WHERE status='active' ORDER BY full_name");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    ok(['users' => $rows]);
}

case 'pkv_enquiry_detail': {
    // Read-only enquiry context + status history (who changed it, when, why). Non-health only.
    if (!crm_pkv_visible()) fail('No access to PKV.');
    $id = (int)($P['id'] ?? 0);
    if (!$id) fail('Missing enquiry id.');
    $enq = null; $history = [];
    try {
        $r = $conn->query("SELECT q.id, q.status, q.insurance_type, q.source, q.client_message, q.callback_requested,
                             q.meeting_channel, q.close_reason_code, q.close_reason_text, q.created_at, q.updated_at,
                             COALESCE(NULLIF(b.company_name,''), b.name) AS broker
                           FROM gphi.gphi_quote_requests q
                           LEFT JOIN gphi.gphi_brokers b ON b.id = q.broker_id
                           WHERE q.id = $id AND (q.is_dummy = 0 OR q.is_dummy IS NULL)");
        $enq = $r ? $r->fetch_assoc() : null;
        $hr = $conn->query("SELECT l.old_status, l.new_status, l.note, l.created_at, l.changed_by,
                              COALESCE(NULLIF(hb.company_name,''), hb.name) AS broker_name
                            FROM gphi.gphi_quote_status_log l
                            LEFT JOIN gphi.gphi_brokers hb ON hb.user_id = l.changed_by
                            WHERE l.quote_request_id = $id ORDER BY l.created_at ASC, l.id ASC");
        if ($hr) while ($row = $hr->fetch_assoc()) {
            $who = $row['broker_name'] ?: ($row['changed_by'] ? ('User #'.$row['changed_by']) : 'System / client');
            $history[] = ['from'=>$row['old_status'], 'to'=>$row['new_status'], 'note'=>$row['note'],
                          'at'=>$row['created_at'], 'who'=>$who];
        }
    } catch (Throwable $e) {}
    ok(['enquiry'=>$enq, 'history'=>$history]);
}

case 'cleanup_example_emails': {
    // Remove example.com test contacts from OUR contact database (ten_ec_contacts) + their audience links.
    need_manage($canManage);
    $ids = [];
    $r = $conn->query("SELECT id FROM ten_ec_contacts WHERE email LIKE '%example.com'");
    if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
    $removed = 0;
    if ($ids) {
        $list = implode(',', $ids);
        @$conn->query("DELETE FROM ten_ec_audience_members WHERE contact_id IN ($list)");
        @$conn->query("DELETE FROM ten_crm_leads WHERE contact_id IN ($list)");
        $conn->query("DELETE FROM ten_ec_contacts WHERE id IN ($list)");
        $removed = $conn->affected_rows;
    }
    ok(['removed' => $removed]);
}

case 'vocab_add': {
    need_manage($canManage);
    $kind = in_array($P['kind'] ?? '', ['category','country'], true) ? $P['kind'] : 'category';
    $name = trim($P['name'] ?? '');
    if ($name === '') fail('Enter a name.');
    ec_vocab_absorb($conn, $kind, $name);  // writes ten_ec_vocab — shared with the Email Campaign module
    ok(['name' => $name, 'kind' => $kind]);
}

case 'publications_list': {
    $out = [];
    $a = crm_admin_db();
    if ($a) {
        try {
            $r = $a->query("SELECT title, publication FROM publications WHERE pub_live='1' ORDER BY title");
            if ($r) while ($row = $r->fetch_assoc()) { $t = trim(($row['title'] ?: '') ?: ($row['publication'] ?: '')); if ($t !== '') $out[] = $t; }
        } catch (Throwable $e) {}
        @$a->close();
    }
    ok(['publications' => $out]);
}

default:
    fail('Unknown action: ' . $action);
}
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'CRM error: ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
    exit;
}
