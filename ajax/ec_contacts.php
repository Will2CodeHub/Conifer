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

try {
    switch ($action) {

        case 'list': {
            $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
            $limit = min(200, max(10, (int)($_POST['limit'] ?? 50)));
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $where = '';
            if ($q !== '') {
                $qe = '%' . $c->real_escape_string($q) . '%';
                $where = "WHERE email LIKE '$qe' OR first_name LIKE '$qe' OR last_name LIKE '$qe' OR company LIKE '$qe'";
            }
            $total = (int)$c->query("SELECT COUNT(*) n FROM ten_ec_contacts $where")->fetch_assoc()['n'];
            $rows = [];
            $res = $c->query("SELECT id,email,first_name,last_name,company,city,country,source,status,created_at FROM ten_ec_contacts $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
            while ($x = $res->fetch_assoc()) $rows[] = $x;
            $resp = ['success'=>true,'total'=>$total,'rows'=>$rows];
            break;
        }

        case 'import': {
            $text = $_POST['data'] ?? '';
            $source = preg_replace('/[^a-z_]/','', strtolower($_POST['source'] ?? 'csv')) ?: 'csv';
            $consent = trim($_POST['consent_basis'] ?? '');
            $rows = ec_parse_csv($text);
            if (!$rows) throw new Exception('No rows with an email column found');
            $new=0;$updated=0;$skipped=0;$invalid=0;
            $ins = $c->prepare("INSERT INTO ten_ec_contacts (email,first_name,last_name,company,phone,city,country,source,consent_basis)
                                VALUES (?,?,?,?,?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE
                                  first_name=IF(VALUES(first_name)<>'',VALUES(first_name),first_name),
                                  last_name=IF(VALUES(last_name)<>'',VALUES(last_name),last_name),
                                  company=IF(VALUES(company)<>'',VALUES(company),company),
                                  phone=IF(VALUES(phone)<>'',VALUES(phone),phone),
                                  city=IF(VALUES(city)<>'',VALUES(city),city),
                                  country=IF(VALUES(country)<>'',VALUES(country),country),
                                  updated_at=NOW()");
            foreach ($rows as $r) {
                $email = strtolower(trim($r['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
                if (ec_is_suppressed($email)) { $skipped++; continue; }
                $fn=$r['first_name']??'';$ln=$r['last_name']??'';$co=$r['company']??'';$ph=$r['phone']??'';$ci=$r['city']??'';$cy=$r['country']??'';
                $ins->bind_param('sssssssss',$email,$fn,$ln,$co,$ph,$ci,$cy,$source,$consent);
                $ins->execute();
                if ($c->affected_rows === 1) $new++; else $updated++;
            }
            $ins->close();
            $resp = ['success'=>true,'new'=>$new,'updated'=>$updated,'skipped_suppressed'=>$skipped,'invalid'=>$invalid,'total_rows'=>count($rows)];
            break;
        }

        case 'add': {
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email required');
            if (ec_is_suppressed($email)) throw new Exception('That email is on the suppression list');
            $fn=trim($_POST['first_name']??'');$ln=trim($_POST['last_name']??'');$co=trim($_POST['company']??'');
            $ph=trim($_POST['phone']??'');$ci=trim($_POST['city']??'');$cy=trim($_POST['country']??'');
            $st=$c->prepare("INSERT INTO ten_ec_contacts (email,first_name,last_name,company,phone,city,country,source) VALUES (?,?,?,?,?,?,?, 'manual')
                             ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),company=VALUES(company),phone=VALUES(phone),city=VALUES(city),country=VALUES(country),updated_at=NOW()");
            $st->bind_param('sssssss',$email,$fn,$ln,$co,$ph,$ci,$cy);
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

        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) {
    $resp = ['success'=>false,'message'=>$e->getMessage()];
}
echo json_encode($resp);
