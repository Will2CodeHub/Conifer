<?php
/** Click-tracking redirect: /management/t/c.php?r=<token>&u=<encoded url> */
require_once __DIR__ . '/../lib/ec_core.php';
$token = $_GET['r'] ?? '';
$url = $_GET['u'] ?? '';
// only allow http/https redirects
if (!preg_match('#^https?://#i', $url)) { http_response_code(400); exit('bad url'); }
if ($token !== '' && $token !== 'SAMPLE' && $token !== 'TEST') {
    $c = ec_db();
    $st = $c->prepare("SELECT id, first_click_at FROM ten_ec_recipients WHERE token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        $rid = (int)$row['id'];
        if (empty($row['first_click_at'])) $c->query("UPDATE ten_ec_recipients SET first_click_at=NOW() WHERE id=$rid");
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255); $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $u2 = substr($url, 0, 500);
        $ev = $c->prepare("INSERT INTO ten_ec_events (recipient_id,type,url,ip,user_agent) VALUES (?, 'click', ?, ?, ?)");
        $ev->bind_param('isss', $rid, $u2, $ip, $ua); $ev->execute(); $ev->close();
    }
}
header('Location: ' . $url, true, 302);
