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
$resp = ['success'=>false,'message'=>''];

/** A sample contact for previews/tests. */
function ec_sample_contact(): array {
    return ['first_name'=>'Alex','last_name'=>'Muster','company'=>'Muster Versicherung','email'=>'sample@example.com','city'=>'Berlin'];
}

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            $res=$c->query("SELECT id,name,subject,from_name,from_email,reply_to,updated_at FROM ten_ec_templates ORDER BY id DESC");
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
            $html=$_POST['html_body']??''; $text=trim($_POST['text_body']??'');
            $fromName=trim($_POST['from_name']??''); $fromEmail=trim($_POST['from_email']??''); $replyTo=trim($_POST['reply_to']??'');
            if($name===''||$subject==='') throw new Exception('Name and subject are required');
            if(strpos($html,'{{unsubscribe_url}}')===false) throw new Exception('The HTML must include an unsubscribe link using {{unsubscribe_url}}');
            if($fromEmail!=='' && !filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new Exception('From email is invalid');
            if($text==='') $text = trim(preg_replace('/\s+/',' ', strip_tags($html)));
            if($id>0){
                $st=$c->prepare("UPDATE ten_ec_templates SET name=?,subject=?,from_name=?,from_email=?,reply_to=?,html_body=?,text_body=? WHERE id=?");
                $st->bind_param('sssssssi',$name,$subject,$fromName,$fromEmail,$replyTo,$html,$text,$id); $st->execute(); $st->close();
            } else {
                $uid=(int)($_SESSION['ten_user_id']??0);
                $st=$c->prepare("INSERT INTO ten_ec_templates (name,subject,from_name,from_email,reply_to,html_body,text_body,created_by) VALUES (?,?,?,?,?,?,?,?)");
                $st->bind_param('sssssssi',$name,$subject,$fromName,$fromEmail,$replyTo,$html,$text,$uid); $st->execute(); $id=(int)$c->insert_id; $st->close();
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
            $html=$_POST['html_body']??''; $subject=$_POST['subject']??'';
            $sample=ec_sample_contact();
            $unsub=ec_track_base().'/u.php?r=SAMPLE';
            $resp=['success'=>true,'subject'=>ec_render($subject,$sample,$unsub),'html'=>ec_render($html,$sample,$unsub)];
            break;
        }
        case 'test_send': {
            // send the rendered template to the logged-in user
            $to = $_SESSION['ten_email'] ?? '';
            if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new Exception('Your account has no valid email');
            $subject=trim($_POST['subject']??'(test)'); $html=$_POST['html_body']??''; $text=trim($_POST['text_body']??'');
            $fromName=trim($_POST['from_name']??''); $fromEmail=trim($_POST['from_email']??''); $replyTo=trim($_POST['reply_to']??'');
            $sample=ec_sample_contact(); $sample['email']=$to;
            $unsub=ec_track_base().'/u.php?r=TEST';
            if($text==='') $text=trim(preg_replace('/\s+/',' ',strip_tags($html)));
            $res = ec_send_message(
                ['email'=>$to,'name'=>trim(($_SESSION['ten_full_name']??''))],
                ec_render($subject,$sample,$unsub),
                ec_render($html,$sample,$unsub),
                ec_render($text,$sample,$unsub),
                ['from_name'=>$fromName,'from_email'=>$fromEmail,'reply_to'=>$replyTo,'list_unsub_url'=>$unsub]
            );
            $resp=['success'=>$res['ok'],'message'=>$res['ok']?('Sent to '.$to):$res['error']];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
