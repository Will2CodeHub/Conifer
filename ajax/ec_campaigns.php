<?php
/** Email Campaign Manager — campaigns: build, materialise, control, progress, reports. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
$resp = ['success'=>false,'message'=>''];

/** Save variants for a campaign from a JSON array [{label,template_id,subject_override,weight}]. */
function ec_save_variants(mysqli $c, int $campaignId, array $variants): void {
    $c->query("DELETE FROM ten_ec_campaign_variants WHERE campaign_id=$campaignId");
    $st=$c->prepare("INSERT INTO ten_ec_campaign_variants (campaign_id,label,template_id,subject_override,weight) VALUES (?,?,?,?,?)");
    foreach ($variants as $i=>$v){
        $label = $v['label'] ?? chr(65+$i);
        $tpl = (int)($v['template_id'] ?? 0);
        $sub = trim($v['subject_override'] ?? '');
        $w = max(1,(int)($v['weight'] ?? 1));
        $st->bind_param('isssi',$campaignId,$label,$tpl,$sub,$w);
        $st->execute();
    }
    $st->close();
}

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            $res=$c->query("SELECT ca.*, a.name AS audience_name,
                (SELECT COUNT(*) FROM ten_ec_recipients r WHERE r.campaign_id=ca.id) AS total,
                (SELECT COUNT(*) FROM ten_ec_recipients r WHERE r.campaign_id=ca.id AND r.status='sent') AS sent
                FROM ten_ec_campaigns ca LEFT JOIN ten_ec_audiences a ON a.id=ca.audience_id
                ORDER BY ca.id DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $camp=$c->query("SELECT * FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            $vars=[]; $vr=$c->query("SELECT * FROM ten_ec_campaign_variants WHERE campaign_id=$id ORDER BY id");
            while($vr && $x=$vr->fetch_assoc()) $vars[]=$x;
            $resp=['success'=>(bool)$camp,'campaign'=>$camp,'variants'=>$vars];
            break;
        }
        case 'save': {
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??''); if($name==='') throw new Exception('Name required');
            $audience=(int)($_POST['audience_id']??0);
            $fromName=trim($_POST['from_name']??''); $fromEmail=trim($_POST['from_email']??''); $replyTo=trim($_POST['reply_to']??'');
            $batch=max(1,(int)($_POST['batch_size']??50)); $interval=max(0,(int)($_POST['batch_interval_min']??10));
            $perDomain=max(0,(int)($_POST['per_domain_limit']??0)); $dailyCap=max(0,(int)($_POST['daily_cap']??0));
            $warmup=(int)(!empty($_POST['warmup_enabled'])); $ab=(int)(!empty($_POST['ab_enabled']));
            $sched=trim($_POST['scheduled_at']??''); $schedSql = $sched!=='' ? "'".$c->real_escape_string(date('Y-m-d H:i:s',strtotime($sched)))."'" : "NULL";
            $variants = json_decode($_POST['variants']??'[]', true);
            if(!is_array($variants) || !$variants) throw new Exception('At least one variant (template) is required');
            foreach($variants as $v){ if((int)($v['template_id']??0)<=0) throw new Exception('Each variant needs a template'); }

            if($id>0){
                $st=$c->prepare("UPDATE ten_ec_campaigns SET name=?,audience_id=?,from_name=?,from_email=?,reply_to=?,batch_size=?,batch_interval_min=?,per_domain_limit=?,daily_cap=?,warmup_enabled=?,ab_enabled=?,scheduled_at=".$schedSql." WHERE id=?");
                $st->bind_param('sisssiiiiiii',$name,$audience,$fromName,$fromEmail,$replyTo,$batch,$interval,$perDomain,$dailyCap,$warmup,$ab,$id);
                $st->execute(); $st->close();
            } else {
                $uid=(int)($_SESSION['ten_user_id']??0);
                $st=$c->prepare("INSERT INTO ten_ec_campaigns (name,audience_id,from_name,from_email,reply_to,batch_size,batch_interval_min,per_domain_limit,daily_cap,warmup_enabled,ab_enabled,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,".$schedSql.",?)");
                $st->bind_param('sisssiiiiiii',$name,$audience,$fromName,$fromEmail,$replyTo,$batch,$interval,$perDomain,$dailyCap,$warmup,$ab,$uid);
                $st->execute(); $id=(int)$c->insert_id; $st->close();
            }
            ec_save_variants($c,$id,$variants);
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'preview_count': {
            $id=(int)($_POST['id']??0);
            $camp=$c->query("SELECT audience_id FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            if(!$camp) throw new Exception('Campaign not found');
            $aid=(int)$camp['audience_id'];
            $n=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_audience_members m
                JOIN ten_ec_contacts ct ON ct.id=m.contact_id
                LEFT JOIN ten_ec_suppression s ON s.email=ct.email
                LEFT JOIN ten_ec_recipients r ON r.campaign_id=$id AND r.contact_id=ct.id
                WHERE m.audience_id=$aid AND s.id IS NULL AND r.id IS NULL")->fetch_assoc()['n'];
            $already=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id")->fetch_assoc()['n'];
            $resp=['success'=>true,'will_send'=>$n,'already_materialised'=>$already];
            break;
        }
        case 'materialise': {
            $id=(int)($_POST['id']??0);
            $camp=$c->query("SELECT * FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            if(!$camp) throw new Exception('Campaign not found');
            $aid=(int)$camp['audience_id'];
            $vars=[]; $vr=$c->query("SELECT id,weight FROM ten_ec_campaign_variants WHERE campaign_id=$id ORDER BY id");
            while($vr && $x=$vr->fetch_assoc()) $vars[]=$x;
            if(!$vars) throw new Exception('No variants/templates configured');
            // weighted assignment pool
            $pool=[]; foreach($vars as $v){ for($i=0;$i<max(1,(int)$v['weight']);$i++) $pool[]=(int)$v['id']; }
            $batch=max(1,(int)$camp['batch_size']); $interval=max(0,(int)$camp['batch_interval_min']);
            $start = !empty($camp['scheduled_at']) ? strtotime($camp['scheduled_at']) : time();

            // eligible contacts: in audience, not suppressed, not already a recipient
            $res=$c->query("SELECT ct.id FROM ten_ec_audience_members m
                JOIN ten_ec_contacts ct ON ct.id=m.contact_id
                LEFT JOIN ten_ec_suppression s ON s.email=ct.email
                LEFT JOIN ten_ec_recipients r ON r.campaign_id=$id AND r.contact_id=ct.id
                WHERE m.audience_id=$aid AND s.id IS NULL AND r.id IS NULL");
            $ins=$c->prepare("INSERT IGNORE INTO ten_ec_recipients (campaign_id,contact_id,variant_id,token,status,send_after) VALUES (?,?,?,?, 'queued', ?)");
            $n=0;
            while($x=$res->fetch_assoc()){
                $cid=(int)$x['id'];
                $vid=$pool[$n % count($pool)];
                $tok=ec_token(24);
                $sendAfter=date('Y-m-d H:i:s', $start + intdiv($n,$batch)*$interval*60);
                $ins->bind_param('iiiss',$id,$cid,$vid,$tok,$sendAfter);
                $ins->execute();
                if($c->affected_rows===1) $n++;
            }
            $ins->close();
            $resp=['success'=>true,'materialised'=>$n];
            break;
        }
        case 'launch': {
            $id=(int)($_POST['id']??0);
            $camp=$c->query("SELECT scheduled_at FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            if(!$camp) throw new Exception('Campaign not found');
            // ensure materialised
            $cnt=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id")->fetch_assoc()['n'];
            if($cnt===0) throw new Exception('Nothing to send — materialise the campaign first');
            $status = (!empty($camp['scheduled_at']) && strtotime($camp['scheduled_at'])>time()) ? 'scheduled' : 'sending';
            $c->query("UPDATE ten_ec_campaigns SET status='$status', started_at=COALESCE(started_at,NOW()) WHERE id=$id");
            $resp=['success'=>true,'status'=>$status];
            break;
        }
        case 'pause':  { $id=(int)($_POST['id']??0); $c->query("UPDATE ten_ec_campaigns SET status='paused' WHERE id=$id AND status IN('sending','scheduled')"); $resp=['success'=>true]; break; }
        case 'resume': { $id=(int)($_POST['id']??0); $c->query("UPDATE ten_ec_campaigns SET status='sending' WHERE id=$id AND status='paused'"); $resp=['success'=>true]; break; }
        case 'cancel': { $id=(int)($_POST['id']??0); $c->query("UPDATE ten_ec_campaigns SET status='cancelled' WHERE id=$id"); $resp=['success'=>true]; break; }

        case 'progress': {
            $id=(int)($_POST['id']??0);
            $s=[]; $res=$c->query("SELECT status, COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id GROUP BY status");
            while($res && $x=$res->fetch_assoc()) $s[$x['status']]=(int)$x['n'];
            $ev=[]; $res=$c->query("SELECT type, COUNT(DISTINCT recipient_id) n FROM ten_ec_events e JOIN ten_ec_recipients r ON r.id=e.recipient_id WHERE r.campaign_id=$id GROUP BY type");
            while($res && $x=$res->fetch_assoc()) $ev[$x['type']]=(int)$x['n'];
            $total=array_sum($s);
            $resp=['success'=>true,'total'=>$total,'by_status'=>$s,'events'=>$ev,
                'campaign'=>$c->query("SELECT status FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc()];
            break;
        }
        case 'report': {
            $id=(int)($_POST['id']??0);
            $sent=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id AND status='sent'")->fetch_assoc()['n'];
            $funnel=['sent'=>$sent];
            foreach(['open','click','reply','unsubscribe','bounce','visit'] as $t){
                $funnel[$t]=(int)$c->query("SELECT COUNT(DISTINCT e.recipient_id) n FROM ten_ec_events e JOIN ten_ec_recipients r ON r.id=e.recipient_id WHERE r.campaign_id=$id AND e.type='$t'")->fetch_assoc()['n'];
            }
            // per-variant
            $variants=[];
            $vr=$c->query("SELECT v.id,v.label,COUNT(r.id) recips,
                SUM(r.status='sent') sent,
                SUM(r.opened_at IS NOT NULL) opened,
                SUM(r.first_click_at IS NOT NULL) clicked,
                SUM(r.replied_at IS NOT NULL) replied
                FROM ten_ec_campaign_variants v LEFT JOIN ten_ec_recipients r ON r.variant_id=v.id
                WHERE v.campaign_id=$id GROUP BY v.id,v.label ORDER BY v.id");
            while($vr && $x=$vr->fetch_assoc()) $variants[]=$x;
            $resp=['success'=>true,'funnel'=>$funnel,'variants'=>$variants];
            break;
        }
        case 'recipient_timeline': {
            $id=(int)($_POST['recipient_id']??0);
            $rows=[]; $res=$c->query("SELECT type,url,created_at FROM ten_ec_events WHERE recipient_id=$id ORDER BY id");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'recipients': {
            $id=(int)($_POST['id']??0);
            $limit=min(200,max(10,(int)($_POST['limit']??50))); $offset=max(0,(int)($_POST['offset']??0));
            $rows=[]; $res=$c->query("SELECT r.id,ct.email,ct.first_name,ct.last_name,r.status,r.sent_at,r.opened_at,r.first_click_at,r.replied_at,r.unsubscribed_at,r.bounce_type
                FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.campaign_id=$id ORDER BY r.id DESC LIMIT $limit OFFSET $offset");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
