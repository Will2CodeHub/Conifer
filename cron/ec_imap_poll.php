<?php
/**
 * Poll reply + bounce mailboxes over IMAP and attribute to recipients.
 *   */5 * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_imap_poll.php >/dev/null 2>&1
 * Manual: ?t=ecimap_9x2k7Q
 * Reply match: In-Reply-To/References -> ten_ec_recipients.message_id -> mark replied + suppress.
 * Bounce match: VERP token in 'bounce+<token>@' -> classify hard/soft -> hard suppresses.
 */
if (php_sapi_name() !== 'cli' && ($_GET['t'] ?? '') !== 'ecimap_9x2k7Q') { http_response_code(403); exit('no'); }
@set_time_limit(0); ignore_user_abort(true);
require_once __DIR__ . '/../lib/ec_core.php';
require_once __DIR__ . '/../lib/ec_mailer.php';
header('Content-Type: text/plain');

if (!function_exists('imap_open')) { echo "PHP imap extension not enabled\n"; exit; }
$s = ec_settings();
if (empty($s['imap_host']) || empty($s['imap_user'])) { echo "IMAP not configured\n"; exit; }
$host = $s['imap_host']; $port = (int)($s['imap_port'] ?: 993);
$user = $s['imap_user']; $pass = $s['imap_pass_plain'] ?? '';
$c = ec_db();
$log = [];

function ec_imap_connect($host,$port,$mailbox,$user,$pass){
    $flags = ($port == 993) ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    $ref = '{' . $host . ':' . $port . $flags . '}' . $mailbox;
    return @imap_open($ref, $user, $pass, 0, 1);
}

/* ---- Replies (INBOX) ---- */
$inbox = ec_imap_connect($host,$port,'INBOX',$user,$pass);
if ($inbox) {
    $ids = imap_search($inbox, 'UNSEEN') ?: [];
    $replies=0;
    foreach ($ids as $num) {
        $hdr = imap_headerinfo($inbox, $num);
        $raw = imap_fetchheader($inbox, $num);
        // extract In-Reply-To / References message-ids
        $mids = [];
        if (preg_match('/In-Reply-To:\s*(<[^>]+>)/i', $raw, $m)) $mids[] = $m[1];
        if (preg_match('/References:\s*(.+)/i', $raw, $m) && preg_match_all('/<[^>]+>/', $m[1], $mm)) $mids = array_merge($mids, $mm[0]);
        $matched=false;
        foreach (array_unique($mids) as $mid) {
            $mide = $c->real_escape_string(trim($mid));
            $row = $c->query("SELECT r.id, ct.email, r.campaign_id, r.replied_at FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.message_id='$mide' LIMIT 1")->fetch_assoc();
            if ($row) {
                $rid=(int)$row['id'];
                if (empty($row['replied_at'])) {
                    $c->query("UPDATE ten_ec_recipients SET replied_at=NOW() WHERE id=$rid");
                    $c->query("INSERT INTO ten_ec_events (recipient_id,type) VALUES ($rid,'reply')");
                    ec_suppress($row['email'], 'do_not_contact', (int)$row['campaign_id']); // a reply = stop further sends
                    $replies++;
                }
                $matched=true; break;
            }
        }
        imap_setflag_full($inbox, (string)$num, "\\Seen"); // mark processed
    }
    imap_close($inbox);
    $log[] = "replies matched: $replies";
} else {
    $log[] = 'INBOX open failed: ' . imap_last_error();
}

/* ---- Bounces (VERP mailbox) ---- */
$bounceMailbox = $s['imap_bounce_mailbox'] ?: 'INBOX';
if ($bounceMailbox !== 'INBOX') {
    $bx = ec_imap_connect($host,$port,$bounceMailbox,$user,$pass);
    if ($bx) {
        $ids = imap_search($bx, 'UNSEEN') ?: [];
        $bounces=0;
        foreach ($ids as $num) {
            $raw = imap_fetchheader($bx, $num) . "\n" . imap_body($bx, $num);
            // token from VERP address bounce+<token>@
            if (preg_match('/bounce\+([A-Za-z0-9_\-]+)@/', $raw, $m)) {
                $tok = $c->real_escape_string($m[1]);
                $row = $c->query("SELECT r.id, ct.email FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id WHERE r.token='$tok' LIMIT 1")->fetch_assoc();
                if ($row) {
                    $rid=(int)$row['id'];
                    // hard vs soft from DSN status/code
                    $hard = preg_match('/(Status:\s*5\.\d+\.\d+)|(\b55\d\b)|(mailbox.*(not exist|unavailable|disabled))/i', $raw);
                    $type = $hard ? 'hard' : 'soft';
                    $c->query("UPDATE ten_ec_recipients SET status='bounced', bounce_type='$type' WHERE id=$rid");
                    $c->query("INSERT INTO ten_ec_events (recipient_id,type,meta) VALUES ($rid,'bounce','$type')");
                    if ($hard) ec_suppress($row['email'], 'hard_bounce');
                    $bounces++;
                }
            }
            imap_setflag_full($bx, (string)$num, "\\Seen");
        }
        imap_close($bx);
        $log[] = "bounces processed: $bounces";
    } else {
        $log[] = 'bounce mailbox open failed: ' . imap_last_error();
    }
}

echo implode("\n", $log) . "\n";
