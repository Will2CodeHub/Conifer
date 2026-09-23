<?php
/** Email Campaign Manager — campaigns: build, materialise, control, progress, reports. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
ec_ensure_schema($c);
$resp = ['success'=>false,'message'=>''];

/** Expand the campaign's audience into recipients, skipping suppressed and
 *  anyone already a recipient of THIS campaign (so re-runs only add new people).
 *  Returns the number of new recipients created. */
function ec_materialise(mysqli $c, int $id): int {
    $camp=$c->query("SELECT * FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
    if(!$camp) throw new Exception('Campaign not found');
    $aid=(int)$camp['audience_id'];
    $vars=[]; $vr=$c->query("SELECT id,weight FROM ten_ec_campaign_variants WHERE campaign_id=$id ORDER BY id");
    while($vr && $x=$vr->fetch_assoc()) $vars[]=$x;
    if(!$vars) throw new Exception('No variants/templates configured');
    $pool=[]; foreach($vars as $v){ for($i=0;$i<max(1,(int)$v['weight']);$i++) $pool[]=(int)$v['id']; }
    $batch=max(1,(int)$camp['batch_size']); $interval=max(0,(int)$camp['batch_interval_min']);
    $start = !empty($camp['scheduled_at']) && strtotime($camp['scheduled_at'])>time() ? strtotime($camp['scheduled_at']) : time();
    $res=$c->query("SELECT ct.id FROM ten_ec_audience_members m
        JOIN ten_ec_contacts ct ON ct.id=m.contact_id
        LEFT JOIN ten_ec_suppression s ON s.email=ct.email
        LEFT JOIN ten_ec_recipients r ON r.campaign_id=$id AND r.contact_id=ct.id
        WHERE m.audience_id=$aid AND s.id IS NULL AND r.id IS NULL
          AND ct.email IS NOT NULL AND ct.email<>''");
    $ins=$c->prepare("INSERT IGNORE INTO ten_ec_recipients (campaign_id,contact_id,variant_id,token,status,send_after) VALUES (?,?,?,?, 'queued', ?)");
    $n=0;
    while($x=$res->fetch_assoc()){
        $cid=(int)$x['id']; $vid=$pool[$n % count($pool)]; $tok=ec_token(24);
        $sendAfter=date('Y-m-d H:i:s', $start + intdiv($n,$batch)*$interval*60);
        $ins->bind_param('iiiss',$id,$cid,$vid,$tok,$sendAfter); $ins->execute();
        if($c->affected_rows===1) $n++;
    }
    $ins->close();
    return $n;
}

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
    // Variants were just deleted+recreated with NEW ids. Re-point any existing recipients
    // whose variant no longer exists to a current variant, so they don't become orphaned
    // ("no template" at send time) after a campaign edit.
    $cur=[]; $vr=$c->query("SELECT id FROM ten_ec_campaign_variants WHERE campaign_id=$campaignId ORDER BY id");
    while($vr && $x=$vr->fetch_assoc()) $cur[]=(int)$x['id'];
    if($cur){
        $first=$cur[0]; $list=implode(',', $cur);
        $c->query("UPDATE ten_ec_recipients SET variant_id=$first WHERE campaign_id=$campaignId AND variant_id NOT IN ($list)");
    }
}

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            $where = !empty($_POST['include_archived']) ? '' : 'WHERE ca.archived=0';
            $res=$c->query("SELECT ca.*, a.name AS audience_name,
                (SELECT COUNT(*) FROM ten_ec_recipients r WHERE r.campaign_id=ca.id) AS total,
                (SELECT COUNT(*) FROM ten_ec_recipients r WHERE r.campaign_id=ca.id AND r.status='sent') AS sent
                FROM ten_ec_campaigns ca LEFT JOIN ten_ec_audiences a ON a.id=ca.audience_id
                $where
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
            $profile=(int)($_POST['sending_profile_id']??0);
            $fromName=trim($_POST['from_name']??''); $fromEmail=trim($_POST['from_email']??''); $replyTo=trim($_POST['reply_to']??'');
            $batch=max(1,(int)($_POST['batch_size']??50)); $interval=max(0,(int)($_POST['batch_interval_min']??10));
            $perDomain=max(0,(int)($_POST['per_domain_limit']??0)); $dailyCap=max(0,(int)($_POST['daily_cap']??0));
            $warmup=(int)(!empty($_POST['warmup_enabled'])); $ab=(int)(!empty($_POST['ab_enabled']));
            $sched=trim($_POST['scheduled_at']??''); $schedSql = $sched!=='' ? "'".$c->real_escape_string(date('Y-m-d H:i:s',strtotime($sched)))."'" : "NULL";
            $variants = json_decode($_POST['variants']??'[]', true);
            if(!is_array($variants) || !$variants) throw new Exception('At least one variant (template) is required');
            foreach($variants as $v){ if((int)($v['template_id']??0)<=0) throw new Exception('Each variant needs a template'); }
            // Format is decided by the TEMPLATE now (no per-campaign plain-text toggle). Mirror
            // the first variant's template is_plain onto the campaign so the sender + reports stay
            // consistent. Plain text forces tracking off (can't carry a pixel or rewritten links).
            $firstTpl=(int)($variants[0]['template_id']??0);
            $plainText=0;
            if($firstTpl>0){ $tr=$c->query("SELECT is_plain FROM ten_ec_templates WHERE id=$firstTpl")->fetch_assoc(); $plainText=(int)($tr['is_plain']??0); }
            $trackOpens=$plainText?0:(int)(!empty($_POST['track_opens']));
            $trackClicks=$plainText?0:(int)(!empty($_POST['track_clicks']));

            if($id>0){
                $st=$c->prepare("UPDATE ten_ec_campaigns SET name=?,audience_id=?,sending_profile_id=?,from_name=?,from_email=?,reply_to=?,batch_size=?,batch_interval_min=?,per_domain_limit=?,daily_cap=?,warmup_enabled=?,ab_enabled=?,track_opens=?,track_clicks=?,plain_text=?,scheduled_at=".$schedSql." WHERE id=?");
                $st->bind_param('siisssiiiiiiiiii',$name,$audience,$profile,$fromName,$fromEmail,$replyTo,$batch,$interval,$perDomain,$dailyCap,$warmup,$ab,$trackOpens,$trackClicks,$plainText,$id);
                $st->execute(); $st->close();
            } else {
                $uid=(int)($_SESSION['ten_user_id']??0);
                $st=$c->prepare("INSERT INTO ten_ec_campaigns (name,audience_id,sending_profile_id,from_name,from_email,reply_to,batch_size,batch_interval_min,per_domain_limit,daily_cap,warmup_enabled,ab_enabled,track_opens,track_clicks,plain_text,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,".$schedSql.",?)");
                $st->bind_param('siisssiiiiiiiiii',$name,$audience,$profile,$fromName,$fromEmail,$replyTo,$batch,$interval,$perDomain,$dailyCap,$warmup,$ab,$trackOpens,$trackClicks,$plainText,$uid);
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
                WHERE m.audience_id=$aid AND s.id IS NULL AND r.id IS NULL
                  AND ct.email IS NOT NULL AND ct.email<>''")->fetch_assoc()['n'];
            $already=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id")->fetch_assoc()['n'];
            $resp=['success'=>true,'will_send'=>$n,'already_materialised'=>$already];
            break;
        }
        case 'materialise': {
            $id=(int)($_POST['id']??0);
            $n=ec_materialise($c,$id);
            $resp=['success'=>true,'materialised'=>$n];
            break;
        }
        case 'rerun': {
            // Add newly-found contacts to a finished/live campaign WITHOUT re-emailing
            // anyone already contacted in it. Optionally refresh the audience from its
            // saved build filter first (to pull in contacts the scraper added since).
            $id=(int)($_POST['id']??0);
            $camp=$c->query("SELECT * FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            if(!$camp) throw new Exception('Campaign not found');
            $aid=(int)$camp['audience_id'];
            $refreshed=0;
            if (!empty($_POST['refresh_from_filter']) && $aid>0) {
                $arow=$c->query("SELECT filter_json FROM ten_ec_audiences WHERE id=$aid")->fetch_assoc();
                if($arow && !empty($arow['filter_json'])){
                    $flt=json_decode($arow['filter_json'],true) ?: [];
                    $where = ec_filter_where($c,$flt);
                    $ins=$c->prepare("INSERT IGNORE INTO ten_ec_audience_members (audience_id,contact_id) VALUES (?,?)");
                    $res=$c->query("SELECT ct.id FROM ten_ec_contacts ct $where");
                    while($res && $x=$res->fetch_assoc()){ $cid=(int)$x['id']; $ins->bind_param('ii',$aid,$cid); $ins->execute(); $refreshed+=$c->affected_rows; }
                    $ins->close();
                }
            }
            $n=ec_materialise($c,$id);
            // resume/keep sending if there is anything queued
            $queued=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id AND status='queued'")->fetch_assoc()['n'];
            if ($queued>0) $c->query("UPDATE ten_ec_campaigns SET status='sending', started_at=COALESCE(started_at,NOW()), completed_at=NULL WHERE id=$id");
            $resp=['success'=>true,'audience_added'=>$refreshed,'materialised'=>$n,'now_queued'=>$queued];
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
        case 'archive':   { $id=(int)($_POST['id']??0); $c->query("UPDATE ten_ec_campaigns SET archived=1 WHERE id=$id"); $resp=['success'=>true]; break; }
        case 'unarchive': { $id=(int)($_POST['id']??0); $c->query("UPDATE ten_ec_campaigns SET archived=0 WHERE id=$id"); $resp=['success'=>true]; break; }
        case 'delete': {
            $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('id required');
            $row=$c->query("SELECT status FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            if(!$row) throw new Exception('Campaign not found');
            if($row['status']==='sending') throw new Exception('Pause or cancel the campaign before deleting it.');
            // Preserve the permanent send history: keep every ten_ec_sent_log row
            // (what was sent to whom) and just detach it from the now-deleted campaign
            // — the campaign_name snapshot stays, so history & never-email-twice survive.
            $c->query("UPDATE ten_ec_sent_log SET campaign_id=NULL WHERE campaign_id=$id");
            // Cascade the live working data: tracking events -> recipients -> A/B variants -> campaign.
            $c->query("DELETE FROM ten_ec_events WHERE recipient_id IN (SELECT id FROM (SELECT id FROM ten_ec_recipients WHERE campaign_id=$id) t)");
            $c->query("DELETE FROM ten_ec_recipients WHERE campaign_id=$id");
            $c->query("DELETE FROM ten_ec_campaign_variants WHERE campaign_id=$id");
            $c->query("DELETE FROM ten_ec_campaigns WHERE id=$id");
            $resp=['success'=>true];
            break;
        }

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
            // delivery status breakdown (sent/queued/failed/skipped/…) so a campaign that
            // sent nothing still reports WHY instead of showing a blank report.
            $byStatus=[];
            $sr=$c->query("SELECT status,COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id GROUP BY status");
            while($sr && $x=$sr->fetch_assoc()) $byStatus[$x['status']]=(int)$x['n'];
            // a sample of the most recent failure/skip reasons
            $errors=[];
            $er=$c->query("SELECT error,COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id AND status IN('failed','skipped') AND error IS NOT NULL AND error<>'' GROUP BY error ORDER BY n DESC LIMIT 5");
            while($er && $x=$er->fetch_assoc()) $errors[]=$x;
            $meta=$c->query("SELECT name,status,created_at,started_at,completed_at,scheduled_at,track_opens,track_clicks,plain_text FROM ten_ec_campaigns WHERE id=$id")->fetch_assoc();
            $resp=['success'=>true,'campaign'=>$meta,'funnel'=>$funnel,'variants'=>$variants,'by_status'=>$byStatus,'errors'=>$errors];
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
            $limit=min(1000,max(10,(int)($_POST['limit']??50))); $offset=max(0,(int)($_POST['offset']??0));
            $total=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$id")->fetch_assoc()['n'];
            $rows=[]; $res=$c->query("SELECT r.id,r.contact_id,ct.email,ct.first_name,ct.last_name,r.status,r.error,r.sent_at,r.opened_at,r.first_click_at,r.replied_at,r.unsubscribed_at,r.bounce_type
                FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.campaign_id=$id ORDER BY r.id DESC LIMIT $limit OFFSET $offset");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'total'=>$total,'rows'=>$rows];
            break;
        }
        case 'resend': {
            // Re-queue recipients of this campaign so they are SENT AGAIN (overrides the
            // never-email-twice default). Empty contact_ids = everyone in the campaign.
            // Suppressed/unsubscribed contacts are still re-checked and skipped at send time.
            $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('id required');
            $ids=trim((string)($_POST['contact_ids']??''));
            $where="campaign_id=$id";
            if($ids!==''){ $list=array_filter(array_map('intval', explode(',', $ids))); if(!$list) throw new Exception('No valid recipients selected'); $where.=" AND contact_id IN (".implode(',', $list).")"; }
            $c->query("UPDATE ten_ec_recipients SET status='queued', sent_at=NULL, error=NULL, send_after=NULL WHERE $where");
            $n=(int)$c->affected_rows;
            if($n>0) $c->query("UPDATE ten_ec_campaigns SET status='sending', started_at=COALESCE(started_at,NOW()), completed_at=NULL WHERE id=$id");
            $resp=['success'=>true,'requeued'=>$n];
            break;
        }
        /* ---- Permanent send history (per campaign) ----
         * Reads ten_ec_sent_log, which is kept even after a campaign is deleted.
         * 'sent_history' lists every campaign that has ever sent (live or deleted);
         * 'sent_list' returns the per-recipient sends for one of them. Deleted
         * campaigns have campaign_id=NULL, so they are grouped/looked up by name. */
        case 'sent_history': {
            $rows=[];
            $res=$c->query("SELECT sl.campaign_id, sl.campaign_name,
                                   COUNT(*) AS sent,
                                   COUNT(DISTINCT sl.contact_id) AS contacts,
                                   COUNT(sl.unsubscribed_at) AS unsubscribed,
                                   MIN(sl.sent_at) AS first_sent, MAX(sl.sent_at) AS last_sent,
                                   (sl.campaign_id IS NOT NULL AND EXISTS(SELECT 1 FROM ten_ec_campaigns k WHERE k.id=sl.campaign_id)) AS live
                            FROM ten_ec_sent_log sl
                            GROUP BY sl.campaign_id, sl.campaign_name
                            ORDER BY last_sent DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'sent_list': {
            $cid=(int)($_POST['campaign_id']??0);
            $name=trim($_POST['campaign_name']??'');
            $limit=min(500,max(10,(int)($_POST['limit']??200))); $offset=max(0,(int)($_POST['offset']??0));
            if ($cid>0) { $where="sl.campaign_id=$cid"; }
            elseif ($name!=='') { $where="sl.campaign_id IS NULL AND sl.campaign_name='".$c->real_escape_string($name)."'"; }
            else throw new Exception('campaign_id or campaign_name required');
            $total=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_sent_log sl WHERE $where")->fetch_assoc()['n'];
            $rows=[];
            $res=$c->query("SELECT sl.id, sl.contact_id, sl.email, sl.subject, sl.is_plain, sl.sent_at, sl.unsubscribed_at,
                                   ct.first_name, ct.last_name, ct.company
                            FROM ten_ec_sent_log sl LEFT JOIN ten_ec_contacts ct ON ct.id=sl.contact_id
                            WHERE $where ORDER BY sl.sent_at DESC, sl.id DESC LIMIT $limit OFFSET $offset");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'total'=>$total,'rows'=>$rows];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
