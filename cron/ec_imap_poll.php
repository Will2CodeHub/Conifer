<?php
/**
 * Poll reply + bounce mailboxes over IMAP and attribute to recipients.
 *   */5 * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_imap_poll.php >/dev/null 2>&1
 * Manual: ?t=ecimap_9x2k7Q
 * Polls every active sending profile's reply + bounce mailboxes (each subdomain
 * has its own), and falls back to the global Settings IMAP account if no profile
 * has IMAP configured.
 * Reply match: In-Reply-To/References -> ten_ec_recipients.message_id -> mark replied + suppress.
 * Bounce match: VERP token in 'bounce+<token>@' -> classify hard/soft -> hard suppresses.
 */
if (php_sapi_name() !== 'cli' && ($_GET['t'] ?? '') !== 'ecimap_9x2k7Q') { http_response_code(403); exit('no'); }
@set_time_limit(0); ignore_user_abort(true);
require_once __DIR__ . '/../lib/ec_core.php';
require_once __DIR__ . '/../lib/ec_mailer.php';
header('Content-Type: text/plain');

if (!function_exists('imap_open')) { echo "PHP imap extension not enabled\n"; exit; }
$c = ec_db();
$log = [];

function ec_imap_connect($host,$port,$mailbox,$user,$pass){
    $flags = ((int)$port === 993) ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    $ref = '{' . $host . ':' . (int)$port . $flags . '}' . ($mailbox ?: 'INBOX');
    return @imap_open($ref, $user, $pass, 0, 1);
}

/** Process reply messages in an open mailbox. */
function ec_poll_replies($mbx, mysqli $c): int {
    $ids = imap_search($mbx, 'UNSEEN') ?: []; $n=0;
    foreach ($ids as $num) {
        $raw = imap_fetchheader($mbx, $num);
        $mids = [];
        if (preg_match('/In-Reply-To:\s*(<[^>]+>)/i', $raw, $m)) $mids[] = $m[1];
        if (preg_match('/References:\s*(.+)/i', $raw, $m) && preg_match_all('/<[^>]+>/', $m[1], $mm)) $mids = array_merge($mids, $mm[0]);
        foreach (array_unique($mids) as $mid) {
            $mide=$c->real_escape_string(trim($mid));
            $row=$c->query("SELECT r.id, ct.email, r.campaign_id, r.replied_at FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.message_id='$mide' LIMIT 1")->fetch_assoc();
            if($row){ $rid=(int)$row['id']; if(empty($row['replied_at'])){ $c->query("UPDATE ten_ec_recipients SET replied_at=NOW() WHERE id=$rid"); $c->query("INSERT INTO ten_ec_events (recipient_id,type) VALUES ($rid,'reply')"); ec_suppress($row['email'],'do_not_contact',(int)$row['campaign_id']); $n++; } break; }
        }
        imap_setflag_full($mbx, (string)$num, "\\Seen");
    }
    return $n;
}

/** Process bounce (DSN) messages in an open mailbox. */
function ec_poll_bounces($mbx, mysqli $c): int {
    $ids = imap_search($mbx, 'UNSEEN') ?: []; $n=0;
    foreach ($ids as $num) {
        $raw = imap_fetchheader($mbx,$num) . "\n" . imap_body($mbx,$num);
        if (preg_match('/bounce\+([A-Za-z0-9_\-]+)@/', $raw, $m)) {
            $tok=$c->real_escape_string($m[1]);
            $row=$c->query("SELECT r.id, ct.email FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.token='$tok' LIMIT 1")->fetch_assoc();
            if($row){ $rid=(int)$row['id'];
                $hard=preg_match('/(Status:\s*5\.\d+\.\d+)|(\b55\d\b)|(mailbox.*(not exist|unavailable|disabled))/i',$raw);
                $type=$hard?'hard':'soft';
                $c->query("UPDATE ten_ec_recipients SET status='bounced', bounce_type='$type' WHERE id=$rid");
                $c->query("INSERT INTO ten_ec_events (recipient_id,type,meta) VALUES ($rid,'bounce','$type')");
                if($hard) ec_suppress($row['email'],'hard_bounce');
                $n++;
            }
        }
        imap_setflag_full($mbx, (string)$num, "\\Seen");
    }
    return $n;
}

// build the list of IMAP accounts: every active profile with imap_host, else global settings
$accounts = [];
$pr = $c->query("SELECT * FROM ten_ec_sending_profiles WHERE active=1 AND imap_host<>'' AND imap_host IS NOT NULL");
while ($pr && $p = $pr->fetch_assoc()) {
    $accounts[] = ['label'=>'profile '.$p['id'],'host'=>$p['imap_host'],'port'=>$p['imap_port'],'user'=>$p['imap_user'],
        'pass'=>(!empty($p['imap_pass'])?ec_decrypt($p['imap_pass']):''),'reply'=>$p['reply_mailbox']?:'INBOX','bounce'=>$p['bounce_mailbox']];
}
if (!$accounts) {
    $s = ec_settings();
    if (!empty($s['imap_host'])) $accounts[] = ['label'=>'settings','host'=>$s['imap_host'],'port'=>$s['imap_port'],'user'=>$s['imap_user'],'pass'=>$s['imap_pass_plain']??'','reply'=>'INBOX','bounce'=>$s['imap_bounce_mailbox']];
}
if (!$accounts) { echo "no IMAP account configured (add a sending profile with IMAP, or global Settings IMAP)\n"; exit; }

foreach ($accounts as $a) {
    $rx = ec_imap_connect($a['host'],$a['port'],$a['reply'],$a['user'],$a['pass']);
    if ($rx) { $log[] = $a['label'].' replies: '.ec_poll_replies($rx,$c); imap_close($rx); }
    else { $log[] = $a['label'].' reply mbox open failed: '.imap_last_error(); }
    if (!empty($a['bounce']) && $a['bounce'] !== $a['reply']) {
        $bx = ec_imap_connect($a['host'],$a['port'],$a['bounce'],$a['user'],$a['pass']);
        if ($bx) { $log[] = $a['label'].' bounces: '.ec_poll_bounces($bx,$c); imap_close($bx); }
        else { $log[] = $a['label'].' bounce mbox open failed: '.imap_last_error(); }
    }
}
echo implode("\n", $log) . "\n";
