<?php
/** Site-visit beacon: /management/v.php?ec=<token>  (called by an on-site snippet).
 *  Logs a 'visit' event for the recipient. Returns a 1x1 gif. */
require_once __DIR__ . '/lib/ec_core.php';
$token = $_GET['ec'] ?? '';
if ($token !== '') {
    $c = ec_db();
    $st = $c->prepare("SELECT id, visited_at FROM ten_ec_recipients WHERE token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        $rid=(int)$row['id'];
        if (empty($row['visited_at'])) $c->query("UPDATE ten_ec_recipients SET visited_at=NOW() WHERE id=$rid");
        $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,255); $ip=$_SERVER['REMOTE_ADDR']??''; $ref=substr($_SERVER['HTTP_REFERER']??'',0,500);
        $ev=$c->prepare("INSERT INTO ten_ec_events (recipient_id,type,url,ip,user_agent) VALUES (?, 'visit', ?, ?, ?)");
        $ev->bind_param('isss',$rid,$ref,$ip,$ua); $ev->execute(); $ev->close();
    }
}
header('Content-Type: image/gif'); header('Cache-Control: no-store');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
