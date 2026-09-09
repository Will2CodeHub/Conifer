<?php
/**
 * Email Campaign sender worker. Run every minute from cron:
 *   * * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_send.php >/dev/null 2>&1
 * Manual/HTTP run for testing: ?t=ecsend_9x2k7Q  (token-guarded).
 * Sends due queued recipients respecting batch size, per-domain limit, daily cap
 * and warm-up ramp; re-checks suppression at send time; records events.
 */
if (php_sapi_name() !== 'cli' && ($_GET['t'] ?? '') !== 'ecsend_9x2k7Q') { http_response_code(403); exit('no'); }
@set_time_limit(0); ignore_user_abort(true);
require_once __DIR__ . '/../lib/ec_core.php';
require_once __DIR__ . '/../lib/ec_mailer.php';
header('Content-Type: text/plain');

$c = ec_db();
// single-instance lock so overlapping ticks don't double-send
$lock = $c->query("SELECT GET_LOCK('ten_ec_send', 0) AS l")->fetch_assoc();
if (!$lock || (int)$lock['l'] !== 1) { echo "another sender is running\n"; exit; }

$settings = ec_settings();
$warmup = json_decode($settings['warmup_json'] ?? '', true);
$globalDailyCap = (int)($settings['daily_cap'] ?? 0);
$siteHosts = ['theeyenewspapers.com','themunicheye.com','thegermanyeye.com','theberlineye.com','thepariseye.com'];
$log = [];

// flip scheduled campaigns whose time has come
$c->query("UPDATE ten_ec_campaigns SET status='sending' WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=NOW()");

$camps = $c->query("SELECT * FROM ten_ec_campaigns WHERE status='sending' ORDER BY id");
while ($camp = $camps->fetch_assoc()) {
    $cid = (int)$camp['id'];
    $batch = max(1,(int)$camp['batch_size']);
    $perDomain = (int)$camp['per_domain_limit'];
    $profile = ec_profile((int)($camp['sending_profile_id'] ?? 0)); // per-campaign sending identity (subdomain + mailboxes)

    // remaining daily allowance (warm-up ramp + campaign cap + global cap)
    $sentToday = (int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$cid AND status='sent' AND DATE(sent_at)=CURDATE()")->fetch_assoc()['n'];
    $dayCaps = [];
    if ($camp['warmup_enabled'] && is_array($warmup) && $warmup) {
        $startedDay = $camp['started_at'] ? (int)floor((time()-strtotime($camp['started_at']))/86400) : 0;
        $dayCaps[] = (int)($warmup[min($startedDay, count($warmup)-1)]);
    }
    if ((int)$camp['daily_cap'] > 0) $dayCaps[] = (int)$camp['daily_cap'];
    if ($globalDailyCap > 0) $dayCaps[] = $globalDailyCap;
    $dailyAllow = $dayCaps ? max(0, min($dayCaps) - $sentToday) : PHP_INT_MAX;
    if ($dailyAllow <= 0) { $log[] = "camp $cid: daily cap reached"; continue; }

    $limit = min($batch, $dailyAllow);
    $recips = $c->query("SELECT r.*, ct.email, ct.first_name, ct.last_name, ct.company, ct.city
                         FROM ten_ec_recipients r JOIN ten_ec_contacts ct ON ct.id=r.contact_id
                         WHERE r.campaign_id=$cid AND r.status='queued' AND (r.send_after IS NULL OR r.send_after<=NOW())
                         ORDER BY r.send_after ASC, r.id ASC LIMIT $limit");
    $domainCount = [];
    $sent=0;$failed=0;$skipped=0;
    while ($r = $recips->fetch_assoc()) {
        $rid=(int)$r['id']; $email=$r['email'];
        // per-domain throttle within this run
        if ($perDomain > 0) {
            $dom = strtolower(substr(strrchr($email,'@'),1));
            $domainCount[$dom] = ($domainCount[$dom] ?? 0);
            if ($domainCount[$dom] >= $perDomain) continue;
        }
        // re-check suppression at send time
        if (ec_is_suppressed($email)) { $c->query("UPDATE ten_ec_recipients SET status='skipped', error='suppressed' WHERE id=$rid"); $skipped++; continue; }
        // load variant + template
        $vid=(int)$r['variant_id'];
        $tpl = $c->query("SELECT v.subject_override, t.* FROM ten_ec_campaign_variants v JOIN ten_ec_templates t ON t.id=v.template_id WHERE v.id=$vid")->fetch_assoc();
        if (!$tpl) { $c->query("UPDATE ten_ec_recipients SET status='failed', error='no template' WHERE id=$rid"); $failed++; continue; }

        $token=$r['token'];
        $trackBase = ec_track_base();
        $unsub = $trackBase . '/u.php?r=' . rawurlencode($token);
        $contact = ['first_name'=>$r['first_name'],'last_name'=>$r['last_name'],'company'=>$r['company'],'email'=>$email,'city'=>$r['city']];
        $subject = ec_render($tpl['subject_override'] ?: $tpl['subject'], $contact, $unsub);
        $html = ec_render($tpl['html_body'], $contact, $unsub);
        $html = ec_rewrite_links($html, $token, $trackBase, $siteHosts);
        $text = ec_render($tpl['text_body'] ?: strip_tags($tpl['html_body']), $contact, $unsub);

        // Identity: campaign override -> sending profile -> template -> global settings.
        $fromEmail = $camp['from_email'] ?: ($profile['from_email'] ?? '') ?: ($tpl['from_email'] ?: ($settings['default_from_email'] ?? ''));
        $fromName  = $camp['from_name'] ?: ($profile['from_name'] ?? '') ?: ($tpl['from_name'] ?: ($settings['default_from_name'] ?? ''));
        $replyTo   = $camp['reply_to'] ?: ($profile['reply_to'] ?? '') ?: ($tpl['reply_to'] ?: ($settings['default_reply_to'] ?? $fromEmail));
        // VERP return-path: from the profile's bounce_address (insert +token) else bounce+token@fromdomain.
        if (!empty($profile['bounce_address']) && strpos($profile['bounce_address'],'@')!==false) {
            [$blp,$bdom] = explode('@', $profile['bounce_address'], 2);
            $returnPath = $blp . '+' . $token . '@' . $bdom;
        } else {
            $domain = substr(strrchr($fromEmail,'@'),1) ?: 'theeyenewspapers.com';
            $returnPath = 'bounce+' . $token . '@' . $domain;
        }
        // SMTP transport: profile overrides global settings.
        $sendOpts = ['from_name'=>$fromName,'from_email'=>$fromEmail,'reply_to'=>$replyTo,'return_path'=>$returnPath,'list_unsub_url'=>$unsub];
        if (!empty($profile['smtp_host'])) {
            $sendOpts['smtp'] = ['host'=>$profile['smtp_host'],'port'=>$profile['smtp_port'],'security'=>$profile['smtp_security'],'user'=>$profile['smtp_user'],'pass'=>$profile['smtp_pass_plain']??''];
        }

        $c->query("UPDATE ten_ec_recipients SET status='sending' WHERE id=$rid");
        $res = ec_send_message(
            ['email'=>$email,'name'=>trim(($r['first_name'].' '.$r['last_name']))],
            $subject, $html, $text,
            $sendOpts
        );
        if ($res['ok']) {
            $mid=$c->real_escape_string($res['message_id']);
            $c->query("UPDATE ten_ec_recipients SET status='sent', sent_at=NOW(), message_id='$mid', error=NULL WHERE id=$rid");
            $c->query("INSERT INTO ten_ec_events (recipient_id,type) VALUES ($rid,'sent')");
            if ($perDomain>0){ $dom=strtolower(substr(strrchr($email,'@'),1)); $domainCount[$dom]++; }
            $sent++;
        } else {
            $err=$c->real_escape_string(substr($res['error'],0,240));
            $c->query("UPDATE ten_ec_recipients SET status='failed', error='$err' WHERE id=$rid");
            $failed++;
        }
    }
    // complete campaign when nothing queued remains
    $remaining=(int)$c->query("SELECT COUNT(*) n FROM ten_ec_recipients WHERE campaign_id=$cid AND status='queued'")->fetch_assoc()['n'];
    if ($remaining===0) $c->query("UPDATE ten_ec_campaigns SET status='completed', completed_at=NOW() WHERE id=$cid");
    $log[] = "camp $cid: sent=$sent failed=$failed skipped=$skipped remaining=$remaining";
}

$c->query("SELECT RELEASE_LOCK('ten_ec_send')");
echo implode("\n", $log ?: ['nothing to send']) . "\n";
