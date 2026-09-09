<?php
/** Email Campaign Manager — settings (SMTP/IMAP/warm-up). Admin/super only. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
require_once '../lib/ec_mailer.php';
header('Content-Type: application/json');

if (!isAdmin() && !hasPermission('system.settings')) {
    // settings are more sensitive than campaigns.manage
    echo json_encode(['success'=>false,'message'=>'Unauthorized — admin only']); exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
$resp = ['success'=>false,'message'=>''];

try {
    switch ($action) {
        case 'get': {
            $row = $c->query("SELECT * FROM ten_ec_settings WHERE id=1")->fetch_assoc() ?: [];
            // never return stored passwords; just flag whether they're set
            $row['smtp_pass_set'] = !empty($row['smtp_pass']) ? 1 : 0;
            $row['imap_pass_set'] = !empty($row['imap_pass']) ? 1 : 0;
            unset($row['smtp_pass'], $row['imap_pass']);
            $resp=['success'=>true,'settings'=>$row];
            break;
        }
        case 'save': {
            $host=trim($_POST['smtp_host']??''); $port=(int)($_POST['smtp_port']??587);
            $sec=in_array($_POST['smtp_security']??'tls',['tls','ssl','none'],true)?$_POST['smtp_security']:'tls';
            $user=trim($_POST['smtp_user']??'');
            $fromName=trim($_POST['default_from_name']??''); $fromEmail=trim($_POST['default_from_email']??''); $replyTo=trim($_POST['default_reply_to']??'');
            $imapHost=trim($_POST['imap_host']??''); $imapPort=(int)($_POST['imap_port']??993); $imapUser=trim($_POST['imap_user']??'');
            $imapBounce=trim($_POST['imap_bounce_mailbox']??'');
            $warmup=trim($_POST['warmup_json']??''); $dailyCap=(int)($_POST['daily_cap']??0);
            $trackBase=trim($_POST['track_base']??'') ?: 'https://theeyenewspapers.com/management';
            if($fromEmail!=='' && !filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new Exception('Default from email invalid');
            if($warmup!=='' && json_decode($warmup)===null) throw new Exception('Warm-up must be valid JSON (e.g. [50,100,200,400])');

            // Build the update with escaped values (dynamic column set; encrypted
            // secrets are base64 so escaping is straightforward). Passwords are
            // only overwritten when a new value is supplied.
            $e = fn($v) => "'" . $c->real_escape_string((string)$v) . "'";
            $sets = [
                'smtp_host='.$e($host), 'smtp_port='.(int)$port, 'smtp_security='.$e($sec), 'smtp_user='.$e($user),
                'default_from_name='.$e($fromName), 'default_from_email='.$e($fromEmail), 'default_reply_to='.$e($replyTo),
                'imap_host='.$e($imapHost), 'imap_port='.(int)$imapPort, 'imap_user='.$e($imapUser), 'imap_bounce_mailbox='.$e($imapBounce),
                'warmup_json='.$e($warmup), 'daily_cap='.(int)$dailyCap, 'track_base='.$e($trackBase),
            ];
            if(isset($_POST['smtp_pass']) && $_POST['smtp_pass']!=='') $sets[]='smtp_pass='.$e(ec_encrypt($_POST['smtp_pass']));
            if(isset($_POST['imap_pass']) && $_POST['imap_pass']!=='') $sets[]='imap_pass='.$e(ec_encrypt($_POST['imap_pass']));
            $c->query("UPDATE ten_ec_settings SET ".implode(',',$sets)." WHERE id=1");
            if($c->error) throw new Exception($c->error);
            $resp=['success'=>true];
            break;
        }
        case 'imap_check': {
            $s=ec_settings();
            $resp=['success'=>true,'imap_ext'=>function_exists('imap_open')?'available':'MISSING (enable PHP imap extension)','imap_configured'=>!empty($s['imap_host'])?1:0];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
