<?php
/** Email Campaign Manager — captured replies + bounces (content) collated for viewing. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$c = ec_db();
ec_ensure_schema($c);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$resp = ['success'=>false,'message'=>''];

try {
    switch ($action) {
        case 'counts': {
            $out=['reply'=>0,'bounce'=>0];
            $res=$c->query("SELECT type,COUNT(*) n FROM ten_ec_responses GROUP BY type");
            while($res && $x=$res->fetch_assoc()) $out[$x['type']]=(int)$x['n'];
            $resp=['success'=>true,'counts'=>$out];
            break;
        }
        case 'list': {
            $type = in_array($_POST['type']??'', ['reply','bounce'], true) ? $_POST['type'] : '';
            $limit=min(200,max(10,(int)($_POST['limit']??50))); $offset=max(0,(int)($_POST['offset']??0));
            $w = $type!=='' ? ("WHERE rp.type='".$c->real_escape_string($type)."'") : '';
            $total=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_responses rp $w")->fetch_assoc()['n'];
            $rows=[]; $res=$c->query("SELECT rp.id,rp.type,rp.contact_email,rp.subject,rp.bounce_type,rp.received_at,ca.name AS campaign,
                LEFT(rp.body,180) AS snippet
                FROM ten_ec_responses rp LEFT JOIN ten_ec_campaigns ca ON ca.id=rp.campaign_id $w
                ORDER BY rp.id DESC LIMIT $limit OFFSET $offset");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'total'=>$total,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $row=$c->query("SELECT rp.*, ca.name AS campaign FROM ten_ec_responses rp LEFT JOIN ten_ec_campaigns ca ON ca.id=rp.campaign_id WHERE rp.id=$id")->fetch_assoc();
            $resp=['success'=>(bool)$row,'response'=>$row,'message'=>$row?'':'Not found'];
            break;
        }
        case 'poll': {
            // Trigger the IMAP poller now (same token-guarded worker the cron runs).
            $out=@file_get_contents(ec_track_base().'/cron/ec_imap_poll.php?t=ecimap_9x2k7Q');
            $resp=['success'=>true,'log'=>trim((string)$out) ?: 'No output'];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_responses WHERE id=$id");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
