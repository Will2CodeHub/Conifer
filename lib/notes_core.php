<?php
/**
 * Project Notes — shared helpers, schema provisioning and permission checks.
 *
 * A note belongs to a project (optionally attached to a task) and is either private
 * (author only) or shared with specific project members, each granted 'view' or 'reply'.
 * A note with replies is a WhatsApp-style conversation thread. Notes and replies can be
 * edited for 30 minutes after posting, then lock.
 *
 * Voice notes are a planned follow-up: the schema already carries type/audio_path/
 * duration_secs so voice slots in without a migration; today the UI creates text only.
 */

if (!defined('NOTES_EDIT_WINDOW_SECS')) {
    define('NOTES_EDIT_WINDOW_SECS', 1800); // 30 minutes
}

/** Management DB connection (mysqli) — same DB that holds ten_projects / ten_users. */
function notes_db() {
    return getDBConnection();
}

/** Current management user id, or 0 when not logged in. */
function notes_current_user_id() {
    return (int) ($_SESSION['ten_user_id'] ?? 0);
}

/** Create the notes tables on first use (idempotent). */
function notes_ensure_schema($c) {
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_project_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            task_id INT DEFAULT NULL,
            author_id INT NOT NULL,
            type ENUM('text','voice') NOT NULL DEFAULT 'text',
            body MEDIUMTEXT NULL,
            audio_path VARCHAR(255) NULL,
            duration_secs INT DEFAULT NULL,
            visibility ENUM('private','shared') NOT NULL DEFAULT 'private',
            status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            edited_at DATETIME NULL DEFAULT NULL,
            KEY idx_project (project_id, status),
            KEY idx_author (author_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_note_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            note_id INT NOT NULL,
            user_id INT NOT NULL,
            permission ENUM('view','reply') NOT NULL DEFAULT 'view',
            shared_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_note_user (note_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_note_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            note_id INT NOT NULL,
            author_id INT NOT NULL,
            type ENUM('text','voice') NOT NULL DEFAULT 'text',
            body MEDIUMTEXT NULL,
            audio_path VARCHAR(255) NULL,
            duration_secs INT DEFAULT NULL,
            status ENUM('active','deleted') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            edited_at DATETIME NULL DEFAULT NULL,
            KEY idx_note (note_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_note_reads (
            note_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_at DATETIME NOT NULL,
            PRIMARY KEY (note_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // General per-user bell notifications (note reminders today; not tied to a project).
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            note_id INT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Bell items a user deleted that are computed rather than stored (overdue tasks, scraper line).
    $c->query("
        CREATE TABLE IF NOT EXISTS ten_notification_dismissals (
            user_id INT NOT NULL,
            item_key VARCHAR(80) NOT NULL,
            dismissed_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, item_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    notes_upgrade_schema($c);
}

/**
 * Post-it upgrade (Sep 2026): title/colour/deadline/reminder, page pinning, optional project,
 * share "seen" marker. Add-only; checked once per request against INFORMATION_SCHEMA.
 */
function notes_upgrade_schema($c) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $cols = [];
    $types = [];
    $res = $c->query("SELECT TABLE_NAME t, COLUMN_NAME col, IS_NULLABLE n, COLUMN_TYPE ct FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ten_project_notes','ten_note_shares','ten_note_replies')");
    while ($res && ($r = $res->fetch_assoc())) { $cols[$r['t'] . '.' . $r['col']] = $r['n']; $types[$r['t'] . '.' . $r['col']] = $r['ct']; }

    $add = [
        'title'              => "ADD COLUMN title VARCHAR(120) NULL AFTER type",
        'color'              => "ADD COLUMN color VARCHAR(16) NOT NULL DEFAULT 'yellow' AFTER title",
        'deadline'           => "ADD COLUMN deadline DATETIME NULL",
        'remind_before_mins' => "ADD COLUMN remind_before_mins INT NULL",
        'reminder_sent_at'   => "ADD COLUMN reminder_sent_at DATETIME NULL",
        'page_key'           => "ADD COLUMN page_key VARCHAR(100) NULL",
        'page_label'         => "ADD COLUMN page_label VARCHAR(120) NULL",
        // Reminder schedule: first fire = deadline - remind_before_mins (0 = at the deadline),
        // then every remind_repeat_mins until the note is Done (archived) or deleted.
        'remind_repeat_mins' => "ADD COLUMN remind_repeat_mins INT NULL",
        'remind_via'         => "ADD COLUMN remind_via VARCHAR(8) NOT NULL DEFAULT 'both'",
        'next_remind_at'     => "ADD COLUMN next_remind_at DATETIME NULL",
        'remind_count'       => "ADD COLUMN remind_count INT NOT NULL DEFAULT 0",
        // Lifecycle (see notes_set_status): when/by whom completed, last status change, snooze.
        'completed_at'       => "ADD COLUMN completed_at DATETIME NULL",
        'completed_by'       => "ADD COLUMN completed_by INT NULL",
        'status_changed_at'  => "ADD COLUMN status_changed_at DATETIME NULL",
        'snoozed_until'      => "ADD COLUMN snoozed_until DATETIME NULL",
    ];
    foreach ($add as $col => $ddl) {
        if (!isset($cols['ten_project_notes.' . $col])) {
            $c->query("ALTER TABLE ten_project_notes $ddl");
            if ($col === 'page_key') { $c->query("ALTER TABLE ten_project_notes ADD KEY idx_page (page_key, status)"); }
            if ($col === 'deadline') { $c->query("ALTER TABLE ten_project_notes ADD KEY idx_deadline (deadline)"); }
            if ($col === 'next_remind_at') {
                $c->query("ALTER TABLE ten_project_notes ADD KEY idx_next_remind (next_remind_at)");
                // Carry over reminders set before the schedule column existed.
                $c->query("UPDATE ten_project_notes SET next_remind_at = DATE_SUB(deadline, INTERVAL remind_before_mins MINUTE)
                           WHERE deadline IS NOT NULL AND remind_before_mins IS NOT NULL AND reminder_sent_at IS NULL");
            }
        }
    }
    if (($cols['ten_project_notes.project_id'] ?? 'YES') === 'NO') {
        $c->query("ALTER TABLE ten_project_notes MODIFY project_id INT NULL DEFAULT NULL");
    }
    // Status lifecycle: open (active) / in_progress / on_hold / completed / archived / deleted.
    if (strpos($types['ten_project_notes.status'] ?? '', 'completed') === false) {
        $c->query("ALTER TABLE ten_project_notes MODIFY status
                   ENUM('active','in_progress','on_hold','completed','archived','deleted') NOT NULL DEFAULT 'active'");
    }
    // 'event' replies are system lines in the thread ("William marked this completed").
    if (strpos($types['ten_note_replies.type'] ?? '', 'event') === false) {
        $c->query("ALTER TABLE ten_note_replies MODIFY type ENUM('text','voice','event') NOT NULL DEFAULT 'text'");
    }
    if (!isset($cols['ten_note_shares.seen_at'])) {
        $c->query("ALTER TABLE ten_note_shares ADD COLUMN seen_at DATETIME NULL");
        // Existing shares predate the bell — treat them as already seen.
        $c->query("UPDATE ten_note_shares SET seen_at = NOW()");
    }
}

/* -- Status lifecycle ---------------------------------------------------------
 *  active (Open) / in_progress : live - reminders fire, counted in the post-it badge.
 *  on_hold                     : paused - reminders stop until resumed; not counted.
 *  completed / archived        : done - reminders off, unread reminder bells cleared, hidden from
 *                                the open views; can be reopened (reminders re-planned).
 *  deleted                     : soft-deleted - like archived, only restorable by author/admin.
 */
define('NOTES_OPEN_SQL', "('active','in_progress','on_hold')"); // shown on boards/panels
define('NOTES_LIVE_SQL', "('active','in_progress')");           // reminders + badge

function notes_statuses() { return ['active', 'in_progress', 'on_hold', 'completed', 'archived', 'deleted']; }
function notes_is_live($status) { return in_array($status, ['active', 'in_progress'], true); }

/** Statuses a sharee with reply permission may set (workflow only; not archive/delete/restore). */
function notes_can_set_status($c, $note, $userId, $to) {
    if (notes_can_manage($note, $userId)) { return true; }
    if (in_array($note['status'], ['archived', 'deleted'], true)) { return false; }
    return in_array($to, ['active', 'in_progress', 'on_hold', 'completed'], true) && notes_can_reply($c, $note, $userId);
}

/**
 * When the next reminder should fire for a note becoming live again (reopen / resume / restore):
 * the first reminder if it's still ahead; if it already passed but was never sent, now; otherwise
 * the next repeat step after now; one-off reminders that already went out stay done.
 */
function notes_replan_reminder($note) {
    $first = notes_first_remind_at($note['deadline'] ?? null, isset($note['remind_before_mins']) ? $note['remind_before_mins'] : null);
    if (!$first) { return null; }
    $now = time();
    $t = strtotime($first);
    if ($t > $now) { return $first; }
    if (empty($note['reminder_sent_at'])) { return date('Y-m-d H:i:s', $now); }
    $rep = (int) ($note['remind_repeat_mins'] ?? 0);
    if ($rep <= 0) { return null; }
    $step = $rep * 60;
    $t += (int) (floor(($now - $t) / $step) + 1) * $step;
    return date('Y-m-d H:i:s', $t);
}

/**
 * Move a note to a new status and apply every side effect: reminder schedule, completion stamp,
 * clearing stale reminder bells, a history line in the thread and a bell for the other people on
 * the note when it's completed or reopened. Returns the updated note row.
 */
function notes_set_status($c, $note, $to, $userId) {
    $from = $note['status'];
    $id = (int) $note['id'];
    $userId = (int) $userId;
    if ($from === $to) { return $note; }

    if (notes_is_live($to)) {
        // Becoming live: keep the schedule when just switching open <-> in progress, else re-plan.
        $next = notes_is_live($from) ? $note['next_remind_at'] : notes_replan_reminder($note);
    } else {
        $next = null; // on hold / completed / archived / deleted: nothing fires
    }
    $completedSql = $to === 'completed' ? ", completed_at = NOW(), completed_by = $userId"
                  : ($from === 'completed' ? ", completed_at = NULL, completed_by = NULL" : "");
    // A snooze survives open <-> in progress (the schedule is kept); any other move clears it.
    $snoozeSql = (notes_is_live($from) && notes_is_live($to)) ? "" : ", snoozed_until = NULL";
    $st = $c->prepare("UPDATE ten_project_notes SET status = ?, next_remind_at = ?, status_changed_at = NOW()
                       $snoozeSql $completedSql WHERE id = ?");
    $st->bind_param("ssi", $to, $next, $id);
    $st->execute();
    $st->close();

    if (!notes_is_live($to)) {
        // Unread reminder bells for a note that's no longer live are just noise.
        $c->query("DELETE FROM ten_user_notifications WHERE note_id = $id AND kind = 'note_reminder' AND is_read = 0");
    }
    if ($to === 'deleted') {
        $c->query("DELETE FROM ten_user_notifications WHERE note_id = $id AND is_read = 0");
    }

    // History line in the thread (not for delete; nobody can open the thread then).
    $verbs = [
        'completed'   => 'marked this completed',
        'archived'    => 'archived this note',
        'on_hold'     => 'put this on hold (reminders paused)',
        'in_progress' => 'marked this in progress',
        'active'      => in_array($from, ['completed', 'archived', 'deleted'], true) ? 'reopened this note'
                         : ($from === 'on_hold' ? 'resumed this note' : 'marked this open'),
    ];
    $verb = $verbs[$to] ?? null;
    if ($verb) {
        $ins = $c->prepare("INSERT INTO ten_note_replies (note_id, author_id, type, body, status) VALUES (?, ?, 'event', ?, 'active')");
        $ins->bind_param("iis", $id, $userId, $verb);
        $ins->execute();
        $ins->close();
    }

    // Tell everyone else on the note when it's completed or reopened.
    if ($to === 'completed' || ($to === 'active' && in_array($from, ['completed', 'archived'], true))) {
        $actor = $c->query("SELECT full_name FROM ten_users WHERE id = $userId")->fetch_assoc();
        $name = $actor['full_name'] ?? 'Someone';
        $title = $name . ($to === 'completed' ? ' completed a note' : ' reopened a note');
        $msg = trim((string) $note['title']) !== '' ? $note['title'] : mb_substr(trim((string) $note['body']), 0, 90);
        $people = [(int) $note['author_id']];
        $sr = $c->query("SELECT user_id FROM ten_note_shares WHERE note_id = $id");
        while ($sr && ($r = $sr->fetch_assoc())) { $people[] = (int) $r['user_id']; }
        $ins = $c->prepare("INSERT INTO ten_user_notifications (user_id, kind, title, message, note_id) VALUES (?, 'note_status', ?, ?, ?)");
        foreach (array_unique($people) as $uid) {
            if ($uid === $userId) { continue; }
            $ins->bind_param("issi", $uid, $title, $msg, $id);
            $ins->execute();
        }
        $ins->close();
    }
    return notes_get_note($c, $id);
}

/** Post-it colours the UI offers (first is the default). */
function notes_colors() {
    return ['yellow', 'pink', 'green', 'blue', 'orange', 'purple'];
}

/** Reminder limits (minutes): lead time 0 (at deadline) .. 1 year; repeat 15 min .. 90 days. */
define('NOTES_REMIND_MAX_BEFORE', 525600);
define('NOTES_REPEAT_MIN', 15);
define('NOTES_REPEAT_MAX', 129600);

/** First reminder time for a deadline + lead time, or null when there's no reminder. */
function notes_first_remind_at($deadline, $beforeMins) {
    if (!$deadline || $beforeMins === null) { return null; }
    return date('Y-m-d H:i:s', strtotime($deadline) - ((int) $beforeMins) * 60);
}

/** "15 minutes", "3 hours", "2 days" — for emails and the UI. */
function notes_fmt_mins($m) {
    $m = (int) $m;
    if ($m > 0 && $m % 1440 === 0) { $n = $m / 1440; return $n . ' day' . ($n === 1 ? '' : 's'); }
    if ($m > 0 && $m % 60 === 0)   { $n = $m / 60;   return $n . ' hour' . ($n === 1 ? '' : 's'); }
    return $m . ' minute' . ($m === 1 ? '' : 's');
}

/** Page key for the current request: the script basename, e.g. "module-scraper.php". */
function notes_current_page_key() {
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

/** Normalise a client-supplied page key to a safe script basename (or null). */
function notes_clean_page_key($k) {
    $k = basename(trim((string) $k));
    return preg_match('/^[A-Za-z0-9_.-]{1,100}\.php$/', $k) ? $k : null;
}

/**
 * SQL fragment + params restricting notes to ones the user personally sees (author or shared to
 * them). Used by the header/page views; admins' "see everything" is deliberately NOT applied there
 * so their counts reflect their own notes.
 */
function notes_mine_or_shared_sql() {
    return "(n.author_id = ? OR EXISTS (SELECT 1 FROM ten_note_shares s WHERE s.note_id = n.id AND s.user_id = ?))";
}

/** Header summary: deadline count, overdue count and whether this page has notes. */
function notes_header_summary($c, $userId, $pageKey) {
    $userId = (int) $userId;
    $vis = notes_mine_or_shared_sql();
    $st = $c->prepare("SELECT COUNT(*) total, SUM(n.deadline < NOW()) overdue FROM ten_project_notes n
                       WHERE n.status IN " . NOTES_LIVE_SQL . " AND n.deadline IS NOT NULL AND $vis");
    $st->bind_param("ii", $userId, $userId);
    $st->execute();
    $d = $st->get_result()->fetch_assoc();
    $st->close();
    $page = 0;
    if ($pageKey) {
        $st = $c->prepare("SELECT COUNT(*) c FROM ten_project_notes n WHERE n.status IN " . NOTES_OPEN_SQL . " AND n.page_key = ? AND $vis");
        $st->bind_param("sii", $pageKey, $userId, $userId);
        $st->execute();
        $page = (int) $st->get_result()->fetch_assoc()['c'];
        $st->close();
    }
    return ['deadlines' => (int) $d['total'], 'overdue' => (int) $d['overdue'], 'page' => $page];
}

/** Record that the user has read a note's thread now (clears "new replies" in the bell). */
function notes_mark_read($c, $noteId, $userId) {
    $noteId = (int) $noteId; $userId = (int) $userId;
    $c->query("INSERT INTO ten_note_reads (note_id, user_id, last_read_at) VALUES ($noteId, $userId, NOW())
               ON DUPLICATE KEY UPDATE last_read_at = NOW()");
    $c->query("UPDATE ten_note_shares SET seen_at = NOW() WHERE note_id = $noteId AND user_id = $userId AND seen_at IS NULL");
}

/** Fetch a single note row (any status) or null. */
function notes_get_note($c, $noteId) {
    $noteId = (int) $noteId;
    $st = $c->prepare("SELECT * FROM ten_project_notes WHERE id = ?");
    $st->bind_param("i", $noteId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** The caller's role in a project ('owner'|'manager'|'member'|'viewer'), or null.
 *  The project creator counts as owner even without a members row. */
function notes_project_role($c, $projectId, $userId) {
    $projectId = (int) $projectId; $userId = (int) $userId;

    $st = $c->prepare("SELECT created_by FROM ten_projects WHERE id = ?");
    $st->bind_param("i", $projectId);
    $st->execute();
    $proj = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$proj) { return null; }
    if ((int) $proj['created_by'] === $userId) { return 'owner'; }

    $st = $c->prepare("SELECT role FROM ten_project_members WHERE project_id = ? AND user_id = ?");
    $st->bind_param("ii", $projectId, $userId);
    $st->execute();
    $m = $st->get_result()->fetch_assoc();
    $st->close();
    return $m ? $m['role'] : null;
}

/** Is the user a member of (or admin for) the project? */
function notes_is_project_member($c, $projectId, $userId) {
    if (isAdmin()) { return true; }
    return notes_project_role($c, $projectId, $userId) !== null;
}

/** The user's share permission on a note ('view'|'reply'), or null. */
function notes_share_permission($c, $noteId, $userId) {
    $noteId = (int) $noteId; $userId = (int) $userId;
    $st = $c->prepare("SELECT permission FROM ten_note_shares WHERE note_id = ? AND user_id = ?");
    $st->bind_param("ii", $noteId, $userId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? $r['permission'] : null;
}

/** Can the user read this note? Author, admin, or (shared + has a share row). */
function notes_can_access($c, $note, $userId) {
    if ((int) $note['author_id'] === (int) $userId) { return true; }
    if (isAdmin()) { return true; }
    if ($note['visibility'] === 'shared') {
        return notes_share_permission($c, $note['id'], $userId) !== null;
    }
    return false;
}

/** Can the user post replies in this note's thread? Author, admin, or share=='reply'. */
function notes_can_reply($c, $note, $userId) {
    if ((int) $note['author_id'] === (int) $userId) { return true; }
    if (isAdmin()) { return true; }
    if ($note['visibility'] === 'shared') {
        return notes_share_permission($c, $note['id'], $userId) === 'reply';
    }
    return false;
}

/** Manage = share / archive / delete / reassign / attach. Author or admin. */
function notes_can_manage($note, $userId) {
    return ((int) $note['author_id'] === (int) $userId) || isAdmin();
}

/** Is a post still inside its 30-minute edit window? */
function notes_within_edit_window($createdAt) {
    $ts = strtotime((string) $createdAt);
    if (!$ts) { return false; }
    return (time() - $ts) <= NOTES_EDIT_WINDOW_SECS;
}

/** Author + admin may edit their own post, but only inside the 30-minute window. */
function notes_can_edit($post, $userId) {
    if ((int) $post['author_id'] !== (int) $userId) { return false; }
    if (!in_array($post['status'] ?? 'active', ['active', 'in_progress', 'on_hold'], true)) { return false; }
    return notes_within_edit_window($post['created_at']);
}

/** Project members (+ creator) as [id, name, avatar] for the share picker. */
function notes_project_members($c, $projectId) {
    $projectId = (int) $projectId;
    $sql = "
        SELECT u.id, u.full_name, u.profile_image
        FROM ten_users u
        WHERE u.id IN (
            SELECT user_id FROM ten_project_members WHERE project_id = ?
            UNION
            SELECT created_by FROM ten_projects WHERE id = ?
        )
        AND u.status = 'active'
        ORDER BY u.full_name
    ";
    $st = $c->prepare($sql);
    $st->bind_param("ii", $projectId, $projectId);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($res && ($r = $res->fetch_assoc())) { $out[] = $r; }
    $st->close();
    return $out;
}
