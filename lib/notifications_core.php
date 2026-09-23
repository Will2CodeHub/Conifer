<?php
/**
 * Header bell — a live "needs attention" feed for the logged-in user, built on the fly from:
 *   - my project tasks that are overdue or due today (stay until the task is completed),
 *   - unread ten_project_notifications (task assigned, deadline approaching, ...),
 *   - unread ten_user_notifications (note reminders from cron/notes_reminders.php),
 *   - notes newly shared with me (ten_note_shares.seen_at IS NULL),
 *   - new replies on notes I wrote or that are shared with me (vs ten_note_reads),
 *   - scraper users: curated stories waiting to be promoted today (informational, not counted).
 * Newest first. Every item carries a `key` so it can be deleted from the bell
 * (see ten_notifications_delete); computed items are hidden via ten_notification_dismissals.
 *
 * Used by includes/header.php (badge count at render) and ajax/notifications.php (the list).
 */

require_once __DIR__ . '/notes_core.php';

/** Build the feed. Returns ['items' => [...], 'count' => actionable count]. */
function ten_notifications_feed($c, $userId, $limit = 60) {
    $userId = (int) $userId;
    notes_ensure_schema($c);
    $items = [];

    $dismissed = [];
    $res = $c->query("SELECT item_key FROM ten_notification_dismissals WHERE user_id = $userId");
    while ($res && ($r = $res->fetch_assoc())) { $dismissed[$r['item_key']] = true; }

    // 1) My open tasks: overdue + due today.
    $st = $c->prepare("SELECT t.id, t.task_name, t.due_date, t.priority, t.project_id, p.project_name
                       FROM ten_project_tasks t JOIN ten_projects p ON p.id = t.project_id
                       WHERE t.assigned_to = ? AND t.status <> 'completed'
                         AND t.due_date IS NOT NULL AND t.due_date <= CURDATE()
                       ORDER BY t.due_date DESC LIMIT 40");
    $st->bind_param("i", $userId);
    $st->execute();
    $res = $st->get_result();
    $today = date('Y-m-d');
    while ($t = $res->fetch_assoc()) {
        $key = 'task:' . (int) $t['id'] . ':' . $t['due_date']; // a new due date brings it back
        if (isset($dismissed[$key])) { continue; }
        $overdue = $t['due_date'] < $today;
        $days = $overdue ? (int) round((strtotime($today) - strtotime($t['due_date'])) / 86400) : 0;
        $items[] = [
            'key'    => $key,
            'kind'   => $overdue ? 'task_overdue' : 'task_today',
            'tone'   => $overdue ? 'red' : 'amber',
            'icon'   => $overdue ? 'fa-triangle-exclamation' : 'fa-calendar-day',
            'title'  => $t['task_name'],
            'detail' => ($overdue ? "Overdue by $days day" . ($days === 1 ? '' : 's') : 'Due today') . ' · ' . $t['project_name'],
            'link'   => 'module-project-management.php?open_project=' . (int) $t['project_id'],
            'when'   => $t['due_date'] . ' 00:00:00',
            'counts' => true,
        ];
    }
    $st->close();

    // 2) Unread project notifications.
    $st = $c->prepare("SELECT n.id, n.title, n.message, n.created_at, n.project_id
                       FROM ten_project_notifications n
                       WHERE n.user_id = ? AND n.is_read = 0 ORDER BY n.created_at DESC LIMIT 40");
    $st->bind_param("i", $userId);
    $st->execute();
    $res = $st->get_result();
    while ($n = $res->fetch_assoc()) {
        $items[] = [
            'key'    => 'pn:' . (int) $n['id'],
            'kind'   => 'project_notification',
            'tone'   => 'blue',
            'icon'   => 'fa-diagram-project',
            'title'  => $n['title'],
            'detail' => $n['message'],
            'link'   => 'module-project-management.php?open_project=' . (int) $n['project_id'],
            'when'   => $n['created_at'],
            'counts' => true,
        ];
    }
    $st->close();

    // 3) Unread personal notifications (note reminders).
    $st = $c->prepare("SELECT id, kind, title, message, note_id, link, created_at FROM ten_user_notifications
                       WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 40");
    $st->bind_param("i", $userId);
    $st->execute();
    $res = $st->get_result();
    while ($n = $res->fetch_assoc()) {
        $late = stripos($n['title'], 'Overdue') === 0;
        $isStatus = $n['kind'] === 'note_status'; // "X completed / reopened a note"
        $items[] = [
            'key'     => 'un:' . (int) $n['id'],
            'kind'    => $n['kind'],
            'tone'    => $isStatus ? 'green' : ($late ? 'red' : 'yellow'),
            'icon'    => $isStatus ? 'fa-circle-check' : 'fa-clock',
            'title'   => $n['title'],
            'detail'  => $n['message'],
            'note_id' => $n['note_id'] !== null ? (int) $n['note_id'] : null,
            'link'    => $n['link'],
            'when'    => $n['created_at'],
            'counts'  => true,
        ];
    }
    $st->close();

    // 4) Notes newly shared with me.
    $st = $c->prepare("SELECT n.id, n.title, n.body, s.created_at, u.full_name
                       FROM ten_note_shares s
                       JOIN ten_project_notes n ON n.id = s.note_id AND n.status IN ('active','in_progress','on_hold','completed')
                       LEFT JOIN ten_users u ON u.id = n.author_id
                       WHERE s.user_id = ? AND s.seen_at IS NULL ORDER BY s.created_at DESC LIMIT 20");
    $st->bind_param("i", $userId);
    $st->execute();
    $res = $st->get_result();
    $sharedIds = [];
    while ($n = $res->fetch_assoc()) {
        $sharedIds[(int) $n['id']] = true;
        $items[] = [
            'key'    => 'share:' . (int) $n['id'],
            'kind'   => 'note_shared',
            'tone'   => 'yellow',
            'icon'   => 'fa-note-sticky',
            'title'  => ($n['full_name'] ?: 'Someone') . ' shared a note with you',
            'detail' => ten_notif_note_snippet($n),
            'note_id'=> (int) $n['id'],
            'when'   => $n['created_at'],
            'counts' => true,
        ];
    }
    $st->close();

    // 5) New replies (from others) on notes I'm in, since I last read the thread.
    $st = $c->prepare("
        SELECT n.id, n.title, n.body, COUNT(r.id) AS n_new, MAX(r.created_at) AS last_at
        FROM ten_project_notes n
        JOIN ten_note_replies r ON r.note_id = n.id AND r.status = 'active' AND r.type <> 'event' AND r.author_id <> ?
        LEFT JOIN ten_note_reads rd ON rd.note_id = n.id AND rd.user_id = ?
        LEFT JOIN ten_note_shares s ON s.note_id = n.id AND s.user_id = ?
        WHERE n.status NOT IN ('archived','deleted') AND (n.author_id = ? OR s.id IS NOT NULL)
          AND r.created_at > COALESCE(rd.last_read_at, s.created_at, n.created_at)
        GROUP BY n.id ORDER BY last_at DESC LIMIT 20");
    $st->bind_param("iiii", $userId, $userId, $userId, $userId);
    $st->execute();
    $res = $st->get_result();
    while ($n = $res->fetch_assoc()) {
        if (isset($sharedIds[(int) $n['id']])) { continue; } // already listed as newly shared
        $k = (int) $n['n_new'];
        $items[] = [
            'key'    => 'reply:' . (int) $n['id'],
            'kind'   => 'note_reply',
            'tone'   => 'green',
            'icon'   => 'fa-comments',
            'title'  => $k . ' new repl' . ($k === 1 ? 'y' : 'ies') . ' on a note',
            'detail' => ten_notif_note_snippet($n),
            'note_id'=> (int) $n['id'],
            'when'   => $n['last_at'],
            'counts' => true,
        ];
    }
    $st->close();

    // 6) Scraper: curated stories waiting today (info line for scraper users only).
    $scraper = ten_notif_scraper_waiting($c);
    if ($scraper && !isset($dismissed[$scraper['key']])) { $items[] = $scraper; }

    // Newest first.
    usort($items, function ($a, $b) { return strcmp((string) $b['when'], (string) $a['when']); });

    $count = 0;
    foreach ($items as $it) { if (!empty($it['counts'])) { $count++; } }
    return ['items' => array_slice($items, 0, $limit), 'count' => $count];
}

/** Just the badge number (cheap enough to call on every page render). */
function ten_notifications_count($c, $userId) {
    try {
        return ten_notifications_feed($c, $userId)['count'];
    } catch (Throwable $e) {
        error_log('notifications count: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Mark one item read (by key) — used when the user clicks it. Stored notifications are
 * flagged read; note items clear their share/reply markers; computed items are untouched.
 */
function ten_notifications_mark_read($c, $userId, $key) {
    $userId = (int) $userId;
    if (!preg_match('/^(pn|un|share|reply):(\d+)$/', (string) $key, $m)) { return; }
    $id = (int) $m[2];
    if ($m[1] === 'pn') { $c->query("UPDATE ten_project_notifications SET is_read = 1 WHERE id = $id AND user_id = $userId"); }
    elseif ($m[1] === 'un') { $c->query("UPDATE ten_user_notifications SET is_read = 1 WHERE id = $id AND user_id = $userId"); }
    else { notes_mark_read($c, $id, $userId); }
}

/**
 * Delete items from the user's bell. Stored notifications are deleted outright; notes are
 * marked read/seen; computed items (overdue tasks, the scraper line) are dismissed for this user.
 * Returns how many keys were recognised.
 */
function ten_notifications_delete($c, $userId, array $keys) {
    $userId = (int) $userId;
    $n = 0;
    foreach (array_slice($keys, 0, 200) as $key) {
        $key = (string) $key;
        if (preg_match('/^pn:(\d+)$/', $key, $m)) {
            $c->query("DELETE FROM ten_project_notifications WHERE id = " . (int) $m[1] . " AND user_id = $userId");
        } elseif (preg_match('/^un:(\d+)$/', $key, $m)) {
            $c->query("DELETE FROM ten_user_notifications WHERE id = " . (int) $m[1] . " AND user_id = $userId");
        } elseif (preg_match('/^(share|reply):(\d+)$/', $key, $m)) {
            notes_mark_read($c, (int) $m[2], $userId);
        } elseif (preg_match('/^(task:\d+:\d{4}-\d{2}-\d{2}|scraper:\d{4}-\d{2}-\d{2})$/', $key)) {
            $st = $c->prepare("INSERT INTO ten_notification_dismissals (user_id, item_key, dismissed_at) VALUES (?, ?, NOW())
                               ON DUPLICATE KEY UPDATE dismissed_at = NOW()");
            $st->bind_param("is", $userId, $key);
            $st->execute();
            $st->close();
        } else {
            continue;
        }
        $n++;
    }
    // Old scraper-line dismissals are only relevant for their day.
    $c->query("DELETE FROM ten_notification_dismissals WHERE user_id = $userId AND item_key LIKE 'scraper:%' AND dismissed_at < CURDATE()");
    return $n;
}

/** Mark everything markable as read: stored notifications, new shares, reply threads. */
function ten_notifications_mark_all_read($c, $userId) {
    $userId = (int) $userId;
    notes_ensure_schema($c);
    $c->query("UPDATE ten_project_notifications SET is_read = 1 WHERE user_id = $userId AND is_read = 0");
    $c->query("UPDATE ten_user_notifications SET is_read = 1 WHERE user_id = $userId AND is_read = 0");
    $c->query("UPDATE ten_note_shares SET seen_at = NOW() WHERE user_id = $userId AND seen_at IS NULL");
    $c->query("INSERT INTO ten_note_reads (note_id, user_id, last_read_at)
               SELECT n.id, $userId, NOW() FROM ten_project_notes n
               WHERE n.status <> 'deleted' AND (n.author_id = $userId
                     OR EXISTS (SELECT 1 FROM ten_note_shares s WHERE s.note_id = n.id AND s.user_id = $userId))
               ON DUPLICATE KEY UPDATE last_read_at = NOW()");
}

function ten_notif_note_snippet($n) {
    $t = trim((string) ($n['title'] ?? ''));
    if ($t === '') { $t = trim(preg_replace('/\s+/', ' ', (string) ($n['body'] ?? ''))); }
    return mb_strlen($t) > 90 ? mb_substr($t, 0, 90) . '…' : $t;
}

/** "N curated stories waiting" for users who can use the scraper, scoped to their publications. */
function ten_notif_scraper_waiting($c) {
    $can = (function_exists('hasPermission') && (hasPermission('scraper.use') || hasPermission('scraper.manage')))
        || (function_exists('isAdmin') && isAdmin());
    if (!$can) { return null; }
    $position = $_SESSION['ten_position'] ?? '';
    $seeAll = (function_exists('isAdmin') && isAdmin())
        || in_array($position, ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager'], true);
    $where = '';
    if (!$seeAll) {
        $pubs = array_values(array_filter(array_map('trim', explode(',', (string) ($_SESSION['ten_publication'] ?? '')))));
        if (!$pubs) { return null; }
        $where = " AND ps.publication_key IN ('" . implode("','", array_map([$c, 'real_escape_string'], $pubs)) . "')";
    }
    $r = @$c->query("SELECT COUNT(*) c, MAX(i.curated_at) last_at FROM ten_scraper_items i
                     JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
                     WHERE ps.is_active = 1 AND i.status = 'new' AND i.curate_rank IS NOT NULL
                       AND DATE(i.curated_at) = CURDATE()$where");
    $row = $r ? $r->fetch_assoc() : null;
    $n = $row ? (int) $row['c'] : 0;
    if ($n < 1) { return null; }
    return [
        'key'    => 'scraper:' . date('Y-m-d'),
        'kind'   => 'scraper_waiting',
        'tone'   => 'gray',
        'icon'   => 'fa-newspaper',
        'title'  => $n . ' curated stor' . ($n === 1 ? 'y' : 'ies') . ' waiting today',
        'detail' => 'Ready to publish in Curate & Promote',
        'link'   => 'module-scraper.php',
        'when'   => $row['last_at'],
        'counts' => false,
    ];
}
