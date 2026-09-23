<?php
/** Email Campaign Manager — templates. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
require_once '../lib/ec_mailer.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
ec_ensure_schema($c); // make sure ten_ec_templates.is_plain exists
$resp = ['success'=>false,'message'=>''];

/** A sample contact for previews/tests. */
function ec_sample_contact(): array {
    return ['first_name'=>'Alex','last_name'=>'Muster','company'=>'Muster Versicherung','email'=>'sample@example.com',
            'city'=>'Berlin','country'=>'Germany','job_title'=>'Managing Director','phone'=>'+49 89 123456',
            'website'=>'www.muster-versicherung.example','industry'=>'Insurance','address'=>'Musterstraße 1',
            'postcode'=>'80331','region'=>'Bavaria','category'=>'Broker'];
}

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            // is_plain drives the campaign form's tracking defaults (HTML on, plain off).
            $res=$c->query("SELECT id,name,subject,from_name,from_email,reply_to,is_plain,updated_at FROM ten_ec_templates ORDER BY id DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $row=$c->query("SELECT * FROM ten_ec_templates WHERE id=$id")->fetch_assoc();
            $resp=['success'=>(bool)$row,'template'=>$row];
            break;
        }
        case 'save': {
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??''); $subject=trim($_POST['subject']??'');
            $isPlain=(int)(!empty($_POST['is_plain']));
            $html=$_POST['html_body']??''; $text=trim($_POST['text_body']??'');
            $fromName=trim($_POST['from_name']??''); $fromEmail=trim($_POST['from_email']??''); $replyTo=trim($_POST['reply_to']??'');
            if($name===''||$subject==='') throw new Exception('Name and subject are required');
            if($isPlain){
                // Plain-text template: the plain body is the email; no HTML is stored/sent.
                // The unsubscribe link must live in the plain text.
                if($text==='') throw new Exception('Enter the plain-text message');
                if(strpos($text,'{{unsubscribe_url}}')===false) throw new Exception('The message must include an unsubscribe link using {{unsubscribe_url}}');
                $html='';
            } else {
                if(strpos($html,'{{unsubscribe_url}}')===false) throw new Exception('The HTML must include an unsubscribe link using {{unsubscribe_url}}');
                if($text==='') $text = trim(preg_replace('/\s+/',' ', strip_tags($html)));
            }
            if($fromEmail!=='' && !filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new Exception('From email is invalid');
            if($id>0){
                $st=$c->prepare("UPDATE ten_ec_templates SET name=?,subject=?,from_name=?,from_email=?,reply_to=?,html_body=?,text_body=?,is_plain=? WHERE id=?");
                $st->bind_param('sssssssii',$name,$subject,$fromName,$fromEmail,$replyTo,$html,$text,$isPlain,$id); $st->execute(); $st->close();
            } else {
                $uid=(int)($_SESSION['ten_user_id']??0);
                $st=$c->prepare("INSERT INTO ten_ec_templates (name,subject,from_name,from_email,reply_to,html_body,text_body,is_plain,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $st->bind_param('sssssssii',$name,$subject,$fromName,$fromEmail,$replyTo,$html,$text,$isPlain,$uid); $st->execute(); $id=(int)$c->insert_id; $st->close();
            }
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_templates WHERE id=$id");
            $resp=['success'=>true];
            break;
        }
        case 'preview': {
            $html=$_POST['html_body']??''; $subject=$_POST['subject']??''; $text=$_POST['text_body']??'';
            $isPlain=(int)(!empty($_POST['is_plain']));
            $sample=ec_sample_contact();
            $unsub=ec_track_base().'/u.php?r=SAMPLE';
            // Plain-text templates preview as the actual monospace text they will send.
            $rendered = $isPlain
                ? '<pre style="white-space:pre-wrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px">'.htmlspecialchars(ec_render($text,$sample,$unsub)).'</pre>'
                : ec_render($html,$sample,$unsub);
            $resp=['success'=>true,'subject'=>ec_render($subject,$sample,$unsub),'html'=>$rendered];
            break;
        }
        case 'test_send': {
            // send the rendered template to a chosen address (defaults to the logged-in user),
            // using a chosen SENDING PROFILE for the SMTP transport + identity (SMTP now lives
            // per-profile, not in global Settings).
            $to = strtolower(trim($_POST['to'] ?? '')) ?: strtolower(trim($_SESSION['ten_email'] ?? ''));
            if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address to send the test to');
            $subject=trim($_POST['subject']??'(test)'); $html=$_POST['html_body']??''; $text=trim($_POST['text_body']??'');
            $isPlain=(int)(!empty($_POST['is_plain']));
            $profile = ec_profile((int)($_POST['profile_id'] ?? 0));
            $s = ec_settings();
            // Identity: sending profile wins, then the template's own from fields, then Settings defaults.
            $fromEmail = ($profile['from_email'] ?? '') ?: trim($_POST['from_email']??'') ?: ($s['default_from_email'] ?? '');
            $fromName  = ($profile['from_name']  ?? '') ?: trim($_POST['from_name']??'')  ?: ($s['default_from_name']  ?? '');
            $replyTo   = ($profile['reply_to']   ?? '') ?: trim($_POST['reply_to']??'')   ?: ($s['default_reply_to']   ?? $fromEmail);
            if($fromEmail==='') throw new Exception('No From address — pick a sending profile (Sending tab) or set a default From in Settings');
            $opts=['from_name'=>$fromName,'from_email'=>$fromEmail,'reply_to'=>$replyTo,'list_unsub_url'=>ec_track_base().'/u.php?r=TEST'];
            if($isPlain) $opts['plain_only']=true; // send text/plain headers, no HTML part
            // SMTP transport: from the profile. Without one, fall back to global Settings SMTP (may be empty).
            if(!empty($profile['smtp_host'])) {
                $opts['smtp']=['host'=>$profile['smtp_host'],'port'=>$profile['smtp_port'],'security'=>$profile['smtp_security'],'user'=>$profile['smtp_user'],'pass'=>$profile['smtp_pass_plain']??''];
            } elseif (empty($s['smtp_host'])) {
                throw new Exception('No SMTP host — choose a sending profile that has SMTP configured (Sending tab)');
            }
            $sample=ec_sample_contact(); $sample['email']=$to;
            $unsub=$opts['list_unsub_url'];
            if($text==='') $text=trim(preg_replace('/\s+/',' ',strip_tags($html)));
            $res = ec_send_message(
                ['email'=>$to,'name'=>trim(($_SESSION['ten_full_name']??''))],
                ec_render($subject,$sample,$unsub),
                ec_render($html,$sample,$unsub),
                ec_render($text,$sample,$unsub),
                $opts
            );
            $resp=['success'=>$res['ok'],'message'=>$res['ok']?('Sent to '.$to.' via '.($profile['name']??'global settings')):$res['error']];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
