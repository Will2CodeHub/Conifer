<?php
/**
 * Post-it deadline reminders. Run every 5 minutes from cron:
 *   *\/5 * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/notes_reminders.php >/dev/null 2>&1
 * Manual/HTTP run for testing: ?t=nrem_4h8Tq2  (token-guarded).
 *
 * A note's reminder first fires at deadline - remind_before_mins (0 = at the deadline), then every
 * remind_repeat_mins (if set) until the note is completed, put on hold, archived or deleted. Each fire goes to the
 * note's AUTHOR by email and/or the header bell (remind_via = both|email|bell). next_remind_at holds
 * the next fire time; it is re-planned by ajax/project_notes.php whenever the settings change.
 */
if (php_sapi_name() !== 'cli' && ($_GET['t'] ?? '') !== 'nrem_4h8Tq2') { http_response_code(403); exit('no'); }
ini_set('display_errors', '0');
@set_time_limit(120);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/notes_core.php';
header('Content-Type: text/plain');

$c = getDBConnection();
notes_ensure_schema($c);
$lock = $c->query("SELECT GET_LOCK('ten_notes_reminders', 0) AS l")->fetch_assoc();
if (!$lock || (int) $lock['l'] !== 1) { echo "another run is in progress\n"; exit; }

$due = $c->query("
    SELECT n.id, n.author_id, n.title, n.body, n.deadline, n.remind_before_mins, n.remind_repeat_mins, n.remind_via,
           n.next_remind_at, n.page_key, n.page_label, n.project_id, u.email, u.full_name, p.project_name
    FROM ten_project_notes n
    JOIN ten_users u ON u.id = n.author_id
    LEFT JOIN ten_projects p ON p.id = n.project_id
    WHERE n.status IN ('active','in_progress') AND n.next_remind_at IS NOT NULL AND n.next_remind_at <= NOW()
    ORDER BY n.next_remind_at
    LIMIT 200");

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$emailed = 0; $belled = 0; $failed = 0;
$now = time();
while ($due && ($n = $due->fetch_assoc())) {
    $id = (int) $n['id'];
    $repeat = (int) $n['remind_repeat_mins'];

    // Next fire: step the repeat forward past "now" (so a missed cron run doesn't cause a burst).
    $next = null;
    if ($repeat > 0) {
        $t = strtotime($n['next_remind_at']);
        $step = $repeat * 60;
        if ($t <= $now) { $t += (int) (floor(($now - $t) / $step) + 1) * $step; }
        $next = date('Y-m-d H:i:s', $t);
    }
    // Claim this fire (compare-and-set) before sending, so overlapping runs can't double-send.
    $st = $c->prepare("UPDATE ten_project_notes SET next_remind_at = ?, reminder_sent_at = NOW(), remind_count = remind_count + 1, snoozed_until = NULL
                       WHERE id = ? AND next_remind_at = ?");
    $st->bind_param("sis", $next, $id, $n['next_remind_at']);
    $st->execute();
    $claimed = $st->affected_rows > 0;
    $st->close();
    if (!$claimed) { continue; }

    $title = trim((string) $n['title']) !== '' ? $n['title'] : 'Your note';
    $deadlineTs = strtotime($n['deadline']);
    $when = date('D j M Y, H:i', $deadlineTs);
    $diffMins = (int) round(($deadlineTs - $now) / 60);
    if ($diffMins > 1)       { $status = 'due in ' . notes_fmt_mins($diffMins); $prefix = 'Reminder: '; }
    elseif ($diffMins >= -1) { $status = 'due now'; $prefix = 'Due now: '; }
    else                     { $status = 'overdue by ' . notes_fmt_mins(-$diffMins); $prefix = 'Overdue: '; }
    $where = $n['page_label'] ?: ($n['project_name'] ?: '');
    $linkPath = $n['page_key'] ?: ($n['project_id'] ? 'module-project-management.php?open_project=' . (int) $n['project_id'] : 'dashboard.php');
    $via = $n['remind_via'] ?: 'both';

    if ($via === 'both' || $via === 'bell') {
        $msg = ucfirst($status) . ' (' . $when . ')' . ($where ? ' · ' . $where : '');
        $kind = 'note_reminder';
        $bt = $prefix . $title;
        $ins = $c->prepare("INSERT INTO ten_user_notifications (user_id, kind, title, message, note_id, link) VALUES (?, ?, ?, ?, ?, ?)");
        $uid = (int) $n['author_id'];
        $ins->bind_param("isssis", $uid, $kind, $bt, $msg, $id, $linkPath);
        $ins->execute();
        $ins->close();
        $belled++;
    }

    if (($via === 'both' || $via === 'email') && !empty($n['email'])) {
        $subject = $prefix . $title . ' (' . $status . ')';
        $body = "<div style=\"font-family:Arial,sans-serif;max-width:560px;\">"
              . "<p>Hi " . $h($n['full_name']) . ",</p>"
              . "<p>Your note is <strong>" . $h($status) . "</strong> (deadline " . $h($when) . "):</p>"
              . "<div style=\"background:#fff3a3;border-radius:6px;padding:14px 16px;margin:14px 0;box-shadow:0 2px 6px rgba(0,0,0,.12);\">"
              . "<div style=\"font-weight:bold;font-size:16px;margin-bottom:6px;\">" . $h($title) . "</div>"
              . "<div style=\"white-space:pre-wrap;color:#333;\">" . nl2br($h($n['body'])) . "</div>"
              . ($where ? "<div style=\"margin-top:10px;font-size:12px;color:#666;\">On: " . $h($where) . "</div>" : "")
              . "</div>"
              . ($repeat > 0 ? "<p style=\"font-size:12px;color:#666;\">This reminder repeats every " . $h(notes_fmt_mins($repeat))
                  . " until you mark the note Completed (or put it on hold).</p>" : "")
              . "<p><a href=\"" . $h(SITE_URL . '/' . $linkPath) . "\">Open in TEN Management</a></p></div>";
        if (sendEmail($n['email'], $subject, $body)) { $emailed++; } else { $failed++; error_log("notes_reminders: mail failed for note $id"); }
    }
}

echo date('Y-m-d H:i:s') . " reminders bell=$belled email=$emailed failed=$failed\n";
