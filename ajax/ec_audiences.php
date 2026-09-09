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
            $uid=(int)($_SESSION['ten_user_id']??0);
            $st=$c->prepare("INSERT INTO ten_ec_audiences (name,description,created_by) VALUES (?,?,?)");
            $st->bind_param('ssi',$name,$desc,$uid); $st->execute(); $id=(int)$c->insert_id; $st->close();
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_audiences WHERE id=$id");
            $c->query("DELETE FROM ten_ec_audience_members WHERE audience_id=$id");
            $resp=['success'=>true];
            break;
        }
        case 'add_members': {
            // by explicit contact ids, or by a search filter, or all
            $aid=(int)($_POST['audience_id']??0); if($aid<=0) throw new Exception('audience_id required');
            $ids = $_POST['contact_ids'] ?? [];
            if (!is_array($ids)) $ids = array_filter(array_map('intval', explode(',', (string)$ids)));
            $q = trim($_POST['q'] ?? '');
            $added=0;
            $ins=$c->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)");
            if ($q !== '' && !$ids) {
                $qe='%'.$c->real_escape_string($q).'%';
                $res=$c->query("SELECT id FROM ten_ec_contacts WHERE (email LIKE '$qe' OR company LIKE '$qe' OR city LIKE '$qe') AND status<>'suppressed'");
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
