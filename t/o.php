<?php
/** Open-tracking pixel: /management/t/o.php?r=<token> */
require_once __DIR__ . '/../lib/ec_core.php';
$token = $_GET['r'] ?? '';
if ($token !== '' && $token !== 'SAMPLE' && $token !== 'TEST') {
    $c = ec_db();
    $st = $c->prepare("SELECT id, opened_at FROM ten_ec_recipients WHERE token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        $rid = (int)$row['id'];
        if (empty($row['opened_at'])) $c->query("UPDATE ten_ec_recipients SET opened_at=NOW() WHERE id=$rid");
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ev = $c->prepare("INSERT INTO ten_ec_events (recipient_id,type,ip,user_agent) VALUES (?, 'open', ?, ?)");
        $ev->bind_param('iss', $rid, $ip, $ua); $ev->execute(); $ev->close();
    }
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
// 1x1 transparent gif
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
