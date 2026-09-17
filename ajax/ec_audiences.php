<?php
/** Email Campaign Manager — audiences (lists). */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
$resp = ['success'=>false,'message'=>''];

// ec_filter_where() now lives in lib/ec_core.php (shared with campaign re-run).

/** The subset of POST keys that make up a reusable build filter. */
function ec_filter_fields(array $p): array {
    return [
        'q' => trim($p['q'] ?? ''),
        'type' => trim($p['type'] ?? ''),
        'country' => trim($p['country'] ?? ''),
        'industry' => trim($p['industry'] ?? ''),
        'category' => trim($p['category'] ?? ''),
        'exclude_contacted' => !empty($p['exclude_contacted']) ? 1 : 0,
    ];
}

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            $res=$c->query("SELECT a.id,a.name,a.description,a.created_at,
                (SELECT COUNT(*) FROM ten_ec_audience_members m WHERE m.audience_id=a.id) AS members,
                (SELECT COUNT(*) FROM ten_ec_audience_members m JOIN ten_ec_contacts ct ON ct.id=m.contact_id
                   LEFT JOIN ten_ec_suppression s ON s.email=ct.email
                   WHERE m.audience_id=a.id AND s.id IS NULL) AS sendable
                FROM ten_ec_audiences a ORDER BY a.id DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'create': {
            $name=trim($_POST['name']??''); if($name==='') throw new Exception('Name required');
            $desc=trim($_POST['description']??'');
            // If this audience was built from a filter, remember it so it can be
            // re-applied later to pull in newly-scraped contacts.
            $filterJson = null;
            if (!empty($_POST['save_filter'])) $filterJson = json_encode(ec_filter_fields($_POST));
            $uid=(int)($_SESSION['ten_user_id']??0);
            $st=$c->prepare("INSERT INTO ten_ec_audiences (name,description,filter_json,created_by) VALUES (?,?,?,?)");
            $st->bind_param('sssi',$name,$desc,$filterJson,$uid); $st->execute(); $id=(int)$c->insert_id; $st->close();
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'refresh': {
            // Re-apply the audience's saved build filter to add newly-matching
            // contacts (e.g. contacts the scraper added since it was built).
            $aid=(int)($_POST['audience_id']??0); if($aid<=0) throw new Exception('audience_id required');
            $row=$c->query("SELECT filter_json FROM ten_ec_audiences WHERE id=$aid")->fetch_assoc();
            if(!$row || empty($row['filter_json'])) throw new Exception('This audience was not built from a filter, so it cannot be auto-refreshed. Add contacts manually.');
            $flt=json_decode($row['filter_json'],true) ?: [];
            $where = ec_filter_where($c, $flt);
            $ins=$c->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)");
            $added=0; $res=$c->query("SELECT ct.id FROM ten_ec_contacts ct $where");
            while($res && $x=$res->fetch_assoc()){ $cid=(int)$x['id']; $ins->bind_param('ii',$aid,$cid); $ins->execute(); $added+=$c->affected_rows; }
            $ins->close();
            $resp=['success'=>true,'added'=>$added];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_audiences WHERE id=$id");
            $c->query("DELETE FROM ten_ec_audience_members WHERE audience_id=$id");
            $resp=['success'=>true];
            break;
        }
        case 'count_filter': {
            // preview how many contacts match a filter (with exclusions)
            $where = ec_filter_where($c, $_POST);
            $n=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_contacts ct $where")->fetch_assoc()['n'];
            $resp=['success'=>true,'count'=>$n];
            break;
        }
        case 'add_members': {
            // by explicit contact ids, or by a filter (type/country/search + exclusions)
            $aid=(int)($_POST['audience_id']??0); if($aid<=0) throw new Exception('audience_id required');
            $ids = $_POST['contact_ids'] ?? [];
            if (!is_array($ids)) $ids = array_filter(array_map('intval', explode(',', (string)$ids)));
            $added=0;
            $ins=$c->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)");
            if (!$ids) {
                $where = ec_filter_where($c, $_POST);
                $res=$c->query("SELECT ct.id FROM ten_ec_contacts ct $where");
                while($res && $x=$res->fetch_assoc()){ $cid=(int)$x['id']; $ins->bind_param('ii',$aid,$cid); $ins->execute(); $added+=$c->affected_rows; }
            } else {
                foreach ($ids as $cid){ $cid=(int)$cid; if($cid<=0) continue; $ins->bind_param('ii',$aid,$cid); $ins->execute(); $added+=$c->affected_rows; }
            }
            $ins->close();
            $resp=['success'=>true,'added'=>$added];
            break;
        }
        case 'members': {
            $aid=(int)($_POST['audience_id']??0);
            $limit=min(200,max(10,(int)($_POST['limit']??50))); $offset=max(0,(int)($_POST['offset']??0));
            $rows=[];
            $res=$c->query("SELECT ct.id,ct.email,ct.first_name,ct.last_name,ct.company,ct.status
                            FROM ten_ec_audience_members m JOIN ten_ec_contacts ct ON ct.id=m.contact_id
                            WHERE m.audience_id=$aid ORDER BY ct.id DESC LIMIT $limit OFFSET $offset");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'remove_member': {
            $aid=(int)($_POST['audience_id']??0); $cid=(int)($_POST['contact_id']??0);
            $c->query("DELETE FROM ten_ec_audience_members WHERE audience_id=$aid AND contact_id=$cid");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
