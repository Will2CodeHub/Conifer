<?php
/** Email Campaign Manager — contacts: import, list, add, suppress, history. */
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
ec_ensure_schema($c);
$resp = ['success' => false, 'message' => ''];

/** Parse pasted/CSV text into rows of assoc arrays keyed by header. */
function ec_parse_csv(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $rows = [];
    $header = null;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cells = str_getcsv($line);
        $cells = array_map('trim', $cells);
        if ($header === null) {
            // if the first line looks like a header (contains 'email'), use it; else assume email-only
            $lc = array_map('strtolower', $cells);
            if (in_array('email', $lc, true)) { $header = $lc; continue; }
            $header = ['email','first_name','last_name','company','phone','city','country'];
        }
        $row = [];
        foreach ($header as $i => $h) { $row[$h] = $cells[$i] ?? ''; }
        if (!empty($row['email'])) $rows[] = $row;
    }
    return $rows;
}

/** pr_contacts roles -> friendly contact_type label used on import. */
function ec_legacy_pr_roles(): array {
    return [
        'it_pr_contact' => 'IT/PR Contacts',
        'contact'       => 'Press Contacts',
        'advertiser'    => 'Advertisers',
        'hr'            => 'HR Contacts',
        'college'       => 'Colleges',
        'newsletter'    => 'Newsletter Subscribers',
    ];
}

/** WHERE clause for CONTACTABLE pr_contacts of a role (mirrors campaign_run.php).
 *  Newsletter is double-opt-in: it additionally requires confirmed=1. */
function ec_legacy_pr_contactable_where(mysqli $a, string $role): string {
    $r = $a->real_escape_string($role);
    $w = "role='$r' AND active=1 AND unsubscribed=0 AND do_not_contact=0 AND invalid_email=0 AND email IS NOT NULL AND email<>''";
    if ($role === 'newsletter') $w .= " AND confirmed=1";
    return $w;
}

/** Build the paged SELECT for one legacy source. Returns [sql, contact_type, consent_note]. */
function ec_legacy_select(mysqli $a, string $source, string $role, int $batch, int $offset): array {
    $lim = " LIMIT $batch OFFSET $offset";
    if ($source === 'pr_contacts') {
        $roles = ec_legacy_pr_roles();
        if (!isset($roles[$role])) throw new Exception('Unknown pr_contacts role');
        $where = ec_legacy_pr_contactable_where($a, $role);
        $sql = "SELECT email, firstname AS first_name, surname AS last_name, company,
                       '' AS phone, '' AS city, '' AS country
                FROM pr_contacts WHERE $where ORDER BY id$lim";
        return [$sql, $roles[$role], 'TEN PR list (public/online sources)'];
    }
    if ($source === 'venue_contacts') {
        $sql = "SELECT vc.email, vc.name AS first_name, '' AS last_name,
                       COALESCE(v.name,'') AS company, vc.phone,
                       COALESCE(v.city,'') AS city, COALESCE(v.country,'') AS country
                FROM venue_contacts vc LEFT JOIN venues v ON v.id=vc.venue_id
                WHERE vc.active=1 AND vc.unsubscribed=0 AND vc.do_not_contact=0
                  AND vc.email IS NOT NULL AND vc.email<>''
                ORDER BY vc.id$lim";
        return [$sql, 'Venues', 'TEN venue contacts (public sources)'];
    }
    if ($source === 'clinics') {
        $sql = "SELECT email, '' AS first_name, '' AS last_name, clinic_name AS company,
                       phone, city, country
                FROM clinics WHERE email IS NOT NULL AND email<>'' ORDER BY id$lim";
        return [$sql, 'Clinics', 'TEN clinics directory (public sources)'];
    }
    throw new Exception('Unknown source');
}

try {
    switch ($action) {

        case 'types': {
            $rows=[]; $res=$c->query("SELECT contact_type, COUNT(*) n FROM ten_ec_contacts WHERE contact_type IS NOT NULL AND contact_type<>'' GROUP BY contact_type ORDER BY contact_type");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'industries': {
            $rows=[]; $res=$c->query("SELECT industry, COUNT(*) n FROM ten_ec_contacts WHERE industry IS NOT NULL AND industry<>'' GROUP BY industry ORDER BY industry");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $row=$c->query("SELECT * FROM ten_ec_contacts WHERE id=$id")->fetch_assoc();
            $resp=['success'=>(bool)$row,'contact'=>$row,'message'=>$row?'':'Contact not found'];
            break;
        }
        case 'list': {
            // Columns that may be filtered (f_<col>) and sorted (sort=<col>).
            $filterable = ['email','first_name','last_name','company','job_title','contact_type',
                           'industry','category','website','address','city','postcode','region',
                           'country','phone','source','status'];
            $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
            $type = trim($_POST['type'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $industry = trim($_POST['industry'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $excludeContacted = !empty($_POST['exclude_contacted']);
            $excludeSuppressed = !empty($_POST['exclude_suppressed']);
            $limit = min(500, max(10, (int)($_POST['limit'] ?? 50)));
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $conds = [];
            if ($q !== '') { $qe='%'.$c->real_escape_string($q).'%'; $conds[]="(ct.email LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe' OR ct.company LIKE '$qe' OR ct.city LIKE '$qe' OR ct.industry LIKE '$qe' OR ct.category LIKE '$qe' OR ct.job_title LIKE '$qe')"; }
            if ($type !== '') $conds[]="ct.contact_type='".$c->real_escape_string($type)."'";
            if ($country !== '') $conds[]="ct.country LIKE '%".$c->real_escape_string($country)."%'";
            if ($industry !== '') $conds[]="ct.industry='".$c->real_escape_string($industry)."'";
            if ($category !== '') $conds[]="ct.category='".$c->real_escape_string($category)."'";
            // Per-column contains-filters (f_email, f_company, …).
            foreach ($filterable as $col) {
                $v = trim($_POST['f_'.$col] ?? '');
                if ($v !== '') $conds[] = "ct.$col LIKE '%".$c->real_escape_string($v)."%'";
            }
            if ($excludeSuppressed) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
            if ($excludeContacted) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_recipients r WHERE r.contact_id=ct.id AND r.status IN('sent','bounced'))";
            $where = $conds ? ('WHERE '.implode(' AND ',$conds)) : '';
            // Sorting (whitelisted column + direction).
            $sortCol = in_array($_POST['sort'] ?? '', $filterable, true) ? $_POST['sort'] : '';
            $dir = (strtolower($_POST['dir'] ?? '') === 'desc') ? 'DESC' : 'ASC';
            $orderBy = $sortCol ? "ct.$sortCol $dir, ct.id DESC" : "ct.id DESC";
            $total = (int)$c->query("SELECT COUNT(*) n FROM ten_ec_contacts ct $where")->fetch_assoc()['n'];
            $rows = [];
            $res = $c->query("SELECT ct.id,ct.email,ct.first_name,ct.last_name,ct.company,ct.job_title,ct.contact_type,
                ct.industry,ct.category,ct.website,ct.address,ct.city,ct.postcode,ct.region,ct.country,ct.phone,ct.source,ct.status,
                (SELECT COUNT(*) FROM ten_ec_recipients r WHERE r.contact_id=ct.id AND r.status='sent') AS times_sent,
                EXISTS(SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email) AS suppressed
                FROM ten_ec_contacts ct $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
            while ($x = $res->fetch_assoc()) $rows[] = $x;
            $resp = ['success'=>true,'total'=>$total,'rows'=>$rows];
            break;
        }

        case 'import': {
            $text = $_POST['data'] ?? '';
            $source = preg_replace('/[^a-z_]/','', strtolower($_POST['source'] ?? 'csv')) ?: 'csv';
            $consent = trim($_POST['consent_basis'] ?? '');
            $type = trim($_POST['contact_type'] ?? '');
            $aid = (int)($_POST['audience_id'] ?? 0); // optionally add imported contacts to an audience
            $rows = ec_parse_csv($text);
            if (!$rows) throw new Exception('No rows with an email column found');
            $new=0;$updated=0;$skipped=0;$invalid=0;$added=0;
            $link = $aid>0 ? $c->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)") : null;
            foreach ($rows as $r) {
                $email = strtolower(trim($r['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
                if (ec_is_suppressed($email)) { $skipped++; continue; }
                $r['email']        = $email;
                $r['contact_type'] = trim($r['contact_type'] ?? '') ?: $type;
                $r['source']       = $source;
                $r['consent_basis']= $consent;
                $res = ec_upsert_contact($c, $r);
                if ($res['inserted']) $new++; else $updated++;
                if ($link && $res['id']>0) { $link->bind_param('ii',$aid,$res['id']); $link->execute(); $added += $c->affected_rows; }
            }
            if ($link) $link->close();
            $resp = ['success'=>true,'new'=>$new,'updated'=>$updated,'skipped_suppressed'=>$skipped,'invalid'=>$invalid,'total_rows'=>count($rows),'added_to_audience'=>$added];
            break;
        }

        case 'add': {
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email required');
            if (ec_is_suppressed($email)) throw new Exception('That email is on the suppression list');
            $d = $_POST;
            $d['email']  = $email;
            $d['source'] = trim($_POST['source'] ?? '') ?: 'manual';
            $res = ec_upsert_contact($c, $d);
            $aid = (int)($_POST['audience_id'] ?? 0);
            if ($aid>0 && $res['id']>0) $c->query("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES ($aid,".$res['id'].")");
            $resp=['success'=>true,'id'=>$res['id']];
            break;
        }

        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('id required');
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email required');
            // Guard against colliding with a different contact's email.
            $clash = $c->query("SELECT id FROM ten_ec_contacts WHERE email='".$c->real_escape_string($email)."' AND id<>$id LIMIT 1");
            if ($clash && $clash->num_rows) throw new Exception('Another contact already uses that email');
            $cols = ec_contact_columns(); // excludes email + source handled below
            $set = ['email=?']; $vals=[$email];
            foreach ($cols as $k) {
                if ($k === 'source' || $k === 'consent_basis') continue; // preserve provenance on edit
                $set[] = "$k=?"; $vals[] = trim((string)($_POST[$k] ?? ''));
            }
            $set[] = "updated_at=NOW()";
            $sql = "UPDATE ten_ec_contacts SET ".implode(',', $set)." WHERE id=?";
            $vals[] = $id;
            $st = $c->prepare($sql);
            $st->bind_param(str_repeat('s', count($vals)-1).'i', ...$vals);
            $st->execute(); $st->close();
            $resp=['success'=>true];
            break;
        }

        case 'suppress': {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $reason = in_array($_POST['reason'] ?? '', ['unsubscribe','do_not_contact','hard_bounce','complaint'], true) ? $_POST['reason'] : 'do_not_contact';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email required');
            ec_suppress($email, $reason);
            $resp=['success'=>true];
            break;
        }

        case 'unsuppress': {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $c->query("DELETE FROM ten_ec_suppression WHERE email='".$c->real_escape_string($email)."'");
            $c->query("UPDATE ten_ec_contacts SET status='active' WHERE email='".$c->real_escape_string($email)."'");
            $resp=['success'=>true];
            break;
        }

        case 'history': {
            $id=(int)($_POST['id']??0);
            $rows=[];
            $res=$c->query("SELECT e.type,e.url,e.created_at,r.campaign_id FROM ten_ec_events e
                            JOIN ten_ec_recipients r ON r.id=e.recipient_id
                            WHERE r.contact_id=$id ORDER BY e.id DESC LIMIT 100");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }

        /* ---- Import from existing (legacy) admin_ten lists ----
         * Sources: pr_contacts (PR/press/advertisers/HR/colleges/newsletter),
         * venue_contacts (event venues), clinics. We ONLY import contactable rows
         * and additionally push anyone who opted out (unsubscribed / do-not-contact)
         * into the EC suppression list, so the old opt-outs are always honoured.
         * "Who not to contact" mirrors the old campaign_run.php exactly. */
        case 'legacy_preview': {
            $a = getDBConnection_TENAdmin();
            $out = [];
            // pr_contacts by role
            $roles = ec_legacy_pr_roles();
            $prRows = [];
            foreach ($roles as $role => $label) {
                $cond = ec_legacy_pr_contactable_where($a, $role);
                $n = (int)$a->query("SELECT COUNT(*) n FROM pr_contacts WHERE $cond")->fetch_assoc()['n'];
                if ($n > 0) $prRows[] = ['role'=>$role,'label'=>$label,'contactable'=>$n];
            }
            $prOptOut = (int)$a->query("SELECT COUNT(*) n FROM pr_contacts WHERE (unsubscribed=1 OR do_not_contact=1) AND email IS NOT NULL AND email<>''")->fetch_assoc()['n'];
            // venue_contacts
            $venContact = (int)$a->query("SELECT COUNT(*) n FROM venue_contacts WHERE active=1 AND unsubscribed=0 AND do_not_contact=0 AND email IS NOT NULL AND email<>''")->fetch_assoc()['n'];
            $venOptOut  = (int)$a->query("SELECT COUNT(*) n FROM venue_contacts WHERE (unsubscribed=1 OR do_not_contact=1) AND email IS NOT NULL AND email<>''")->fetch_assoc()['n'];
            // clinics (no opt-out flags)
            $clinics = (int)$a->query("SELECT COUNT(*) n FROM clinics WHERE email IS NOT NULL AND email<>''")->fetch_assoc()['n'];
            $a->close();
            $resp = ['success'=>true,
                'pr_contacts'=>['roles'=>$prRows,'opt_out'=>$prOptOut],
                'venue_contacts'=>['contactable'=>$venContact,'opt_out'=>$venOptOut],
                'clinics'=>['contactable'=>$clinics],
            ];
            break;
        }

        case 'legacy_import': {
            @set_time_limit(0);
            $source = $_POST['source'] ?? '';
            $role   = $_POST['role'] ?? '';
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $batch  = min(5000, max(200, (int)($_POST['batch'] ?? 2000)));
            $a = getDBConnection_TENAdmin();

            $ins = $c->prepare("INSERT INTO ten_ec_contacts (email,first_name,last_name,company,contact_type,phone,city,country,source,consent_basis)
                                VALUES (?,?,?,?,?,?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE
                                  first_name=IF(VALUES(first_name)<>'',VALUES(first_name),first_name),
                                  last_name=IF(VALUES(last_name)<>'',VALUES(last_name),last_name),
                                  company=IF(VALUES(company)<>'',VALUES(company),company),
                                  contact_type=IF(VALUES(contact_type)<>'',VALUES(contact_type),contact_type),
                                  phone=IF(VALUES(phone)<>'',VALUES(phone),phone),
                                  city=IF(VALUES(city)<>'',VALUES(city),city),
                                  country=IF(VALUES(country)<>'',VALUES(country),country),
                                  updated_at=NOW()");

            $new=0;$updated=0;$invalid=0;$skipped=0;$suppressed=0;

            // On the first page, push opt-outs from the source into EC suppression.
            if ($offset === 0 && $source !== 'clinics') {
                $optSql = $source === 'venue_contacts'
                    ? "SELECT email, (unsubscribed=1) AS uns FROM venue_contacts WHERE (unsubscribed=1 OR do_not_contact=1) AND email IS NOT NULL AND email<>''"
                    : "SELECT email, (unsubscribed=1) AS uns FROM pr_contacts WHERE (unsubscribed=1 OR do_not_contact=1) AND email IS NOT NULL AND email<>''";
                $r = $a->query($optSql);
                while ($r && $x = $r->fetch_assoc()) {
                    $em = strtolower(trim($x['email']));
                    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
                    ec_suppress($em, $x['uns'] ? 'unsubscribe' : 'do_not_contact');
                    $suppressed++;
                }
            }

            [$sel, $type, $consent] = ec_legacy_select($a, $source, $role, $batch, $offset);
            $res = $a->query($sel);
            $count = 0;
            while ($res && $row = $res->fetch_assoc()) {
                $count++;
                $email = strtolower(trim($row['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
                if (ec_is_suppressed($email)) { $skipped++; continue; }
                $fn=$row['first_name']??''; $ln=$row['last_name']??''; $co=$row['company']??'';
                $ph=$row['phone']??''; $ci=$row['city']??''; $cy=$row['country']??'';
                $ins->bind_param('ssssssssss',$email,$fn,$ln,$co,$type,$ph,$ci,$cy,$source,$consent);
                $ins->execute();
                if ($c->affected_rows === 1) $new++; else $updated++;
            }
            $ins->close();
            $a->close();
            $done = $count < $batch;
            $resp = ['success'=>true,'new'=>$new,'updated'=>$updated,'invalid'=>$invalid,
                     'skipped_suppressed'=>$skipped,'suppressed_added'=>$suppressed,
                     'processed'=>$count,'next_offset'=>$done?null:($offset+$batch),'done'=>$done];
            break;
        }

        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) {
    $resp = ['success'=>false,'message'=>$e->getMessage()];
}
echo json_encode($resp);
