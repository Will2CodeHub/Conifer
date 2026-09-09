<?php
/** Email Campaign Manager — sending profiles (per-subdomain sending identities). */
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
            $res=$c->query("SELECT id,name,from_name,from_email,reply_to,bounce_address,smtp_host,smtp_port,smtp_security,smtp_user,imap_host,imap_port,imap_user,reply_mailbox,bounce_mailbox,active,
                (smtp_pass IS NOT NULL AND smtp_pass<>'') AS smtp_pass_set,
                (imap_pass IS NOT NULL AND imap_pass<>'') AS imap_pass_set
                FROM ten_ec_sending_profiles ORDER BY id DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $row=$c->query("SELECT * FROM ten_ec_sending_profiles WHERE id=$id")->fetch_assoc();
            if($row){ $row['smtp_pass_set']=!empty($row['smtp_pass'])?1:0; $row['imap_pass_set']=!empty($row['imap_pass'])?1:0; unset($row['smtp_pass'],$row['imap_pass']); }
            $resp=['success'=>(bool)$row,'profile'=>$row];
            break;
        }
        case 'save': {
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??''); $fromEmail=trim($_POST['from_email']??'');
            if($name===''||$fromEmail==='') throw new Exception('Name and from-email are required');
            if(!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new Exception('From email is invalid');
            $e=fn($v)=>"'".$c->real_escape_string((string)$v)."'";
            $fields=[
                'name='.$e($name),
                'from_name='.$e(trim($_POST['from_name']??'')),
                'from_email='.$e($fromEmail),
                'reply_to='.$e(trim($_POST['reply_to']??'')),
                'bounce_address='.$e(trim($_POST['bounce_address']??'')),
                'smtp_host='.$e(trim($_POST['smtp_host']??'')),
                'smtp_port='.(int)($_POST['smtp_port']??587),
                'smtp_security='.$e(in_array($_POST['smtp_security']??'tls',['tls','ssl','none'],true)?$_POST['smtp_security']:'tls'),
                'smtp_user='.$e(trim($_POST['smtp_user']??'')),
                'imap_host='.$e(trim($_POST['imap_host']??'')),
                'imap_port='.(int)($_POST['imap_port']??993),
                'imap_user='.$e(trim($_POST['imap_user']??'')),
                'reply_mailbox='.$e(trim($_POST['reply_mailbox']??'') ?: 'INBOX'),
                'bounce_mailbox='.$e(trim($_POST['bounce_mailbox']??'')),
                'active='.((int)!empty($_POST['active'])),
            ];
            if(isset($_POST['smtp_pass'])&&$_POST['smtp_pass']!=='') $fields[]='smtp_pass='.$e(ec_encrypt($_POST['smtp_pass']));
            if(isset($_POST['imap_pass'])&&$_POST['imap_pass']!=='') $fields[]='imap_pass='.$e(ec_encrypt($_POST['imap_pass']));
            if($id>0){ $c->query("UPDATE ten_ec_sending_profiles SET ".implode(',',$fields)." WHERE id=$id"); }
            else { $c->query("INSERT INTO ten_ec_sending_profiles SET ".implode(',',$fields)); $id=(int)$c->insert_id; }
            if($c->error) throw new Exception($c->error);
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_sending_profiles WHERE id=$id");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
