<?php
/** Unsubscribe: /management/u.php?r=<token>  (GET shows page; POST = one-click). */
require_once __DIR__ . '/lib/ec_core.php';
$token = $_GET['r'] ?? ($_POST['r'] ?? '');
$done = false; $email = '';
if ($token !== '' && $token !== 'SAMPLE' && $token !== 'TEST') {
    $c = ec_db();
    $st = $c->prepare("SELECT r.id, r.campaign_id, ct.email FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        $rid=(int)$row['id']; $cid=(int)$row['campaign_id']; $email=$row['email'];
        ec_suppress($email, 'unsubscribe', $cid);
        $c->query("UPDATE ten_ec_recipients SET unsubscribed_at=NOW() WHERE id=$rid");
        $c->query("INSERT INTO ten_ec_events (recipient_id,type) VALUES ($rid,'unsubscribe')");
        // Stamp the permanent history so it shows they opted out (survives deletion).
        $st2=$c->prepare("UPDATE ten_ec_sent_log SET unsubscribed_at=NOW() WHERE email=? AND unsubscribed_at IS NULL");
        $st2->bind_param('s',$email); $st2->execute(); $st2->close();
        $done = true;
    }
}
// RFC 8058 one-click: a POST just confirms with 200
if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: text/plain'); echo $done?'unsubscribed':'ok'; exit; }
header('Content-Type: text/html; charset=UTF-8');
$msg = $done ? 'You have been unsubscribed'.($email?(' ('.htmlspecialchars($email).')'):'').'. You will not receive further emails from us.'
             : 'This unsubscribe link is invalid or has expired.';
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title>'
   . '<style>body{font-family:Inter,Segoe UI,Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}.card{max-width:520px;margin:60px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;text-align:center;box-shadow:0 3px 16px rgba(16,24,40,.08)}h1{font-size:20px;color:#111827;margin:0 0 12px}p{color:#374151;line-height:1.6}</style></head>'
   . '<body><div class="card"><h1>'.($done?'Unsubscribed':'Unsubscribe').'</h1><p>'.$msg.'</p></div></body></html>';
