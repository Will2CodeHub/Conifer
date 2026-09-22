<?php
/**
 * Project Notes — CRUD + sharing + threaded replies.
 *
 * Every action is permission-checked in lib/notes_core.php: a user must be a member of
 * the project to see its notes, and an individual note is visible only to its author,
 * to admins, or to the members it was shared with (view or reply).
 */
require_once '../config.php';
requireLogin();
require_once '../lib/notes_core.php';
header('Content-Type: application/json');

$c = notes_db();
notes_ensure_schema($c);

$me     = notes_current_user_id();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$resp   = ['success' => false, 'message' => 'Unknown action'];

/** Shape a note row for the client, with the caller's capabilities resolved. */
function notes_present_note($c, $note, $me, $withReplyCount = true) {
    $author = notes_user_brief($c, $note['author_id']);
    $out = [
        'id'          => (int) $note['id'],
        'project_id'  => $note['project_id'] !== null ? (int) $note['project_id'] : null,
        'task_id'     => $note['task_id'] !== null ? (int) $note['task_id'] : null,
        'author_id'   => (int) $note['author_id'],
        'author_name' => $author['name'],
        'author_avatar' => $author['avatar'],
        'type'        => $note['type'],
        'title'       => $note['title'] ?? null,
        'color'       => $note['color'] ?? 'yellow',
        'deadline'    => $note['deadline'] ?? null,
        'is_overdue'  => !empty($note['deadline']) && strtotime($note['deadline']) < time(),
        'remind_before_mins' => isset($note['remind_before_mins']) ? (int) $note['remind_before_mins'] : null,
        'remind_repeat_mins' => isset($note['remind_repeat_mins']) ? (int) $note['remind_repeat_mins'] : null,
        'remind_via'  => $note['remind_via'] ?? 'both',
        'next_remind_at' => $note['next_remind_at'] ?? null,
        'page_key'    => $note['page_key'] ?? null,
        'page_label'  => $note['page_label'] ?? null,
        'project_name'=> notes_project_name($c, $note['project_id']),
        'body'        => $note['body'],
        'visibility'  => $note['visibility'],
        'status'      => $note['status'],
        'created_at'  => $note['created_at'],
        'edited_at'   => $note['edited_at'],
        'is_mine'     => ((int) $note['author_id'] === (int) $me),
        'can_edit'    => notes_can_edit($note, $me),
        'can_manage'  => notes_can_manage($note, $me),
        'can_reply'   => notes_can_reply($c, $note, $me),
        'completed_at'=> $note['completed_at'] ?? null,
        'completed_by_name' => !empty($note['completed_by']) ? notes_user_brief($c, $note['completed_by'])['name'] : null,
        'status_changed_at' => $note['status_changed_at'] ?? null,
        'snoozed_until' => $note['snoozed_until'] ?? null,
        // Which status moves this user may make (drives the card menu).
        'can_status'  => array_values(array_filter(notes_statuses(), function ($to) use ($c, $note, $me) {
            return $to !== $note['status'] && notes_can_set_status($c, $note, $me, $to);
        })),
    ];
    if ($withReplyCount) {
        $nid = (int) $note['id'];
        $r = $c->query("SELECT COUNT(*) AS n FROM ten_note_replies WHERE note_id = $nid AND status = 'active' AND type <> 'event'")->fetch_assoc();
        $out['reply_count'] = (int) ($r['n'] ?? 0);
    }
    return $out;
}

/** Minimal user display info. */
function notes_user_brief($c, $userId) {
    $userId = (int) $userId;
    $st = $c->prepare("SELECT full_name, profile_image FROM ten_users WHERE id = ?");
    $st->bind_param("i", $userId);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    return [
        'name'   => $u['full_name'] ?? 'Unknown',
        'avatar' => $u['profile_image'] ?? '',
    ];
}

/** Project name (cached per request), or null for a project-less note. */
function notes_project_name($c, $projectId) {
    static $cache = [];
    if ($projectId === null || (int) $projectId <= 0) { return null; }
    $pid = (int) $projectId;
    if (!array_key_exists($pid, $cache)) {
        $r = $c->query("SELECT project_name FROM ten_projects WHERE id = $pid")->fetch_assoc();
        $cache[$pid] = $r['project_name'] ?? null;
    }
    return $cache[$pid];
}

/** Is this an active management user? (share target for project-less notes) */
function notes_is_active_user($c, $userId) {
    $userId = (int) $userId;
    $r = $c->query("SELECT id FROM ten_users WHERE id = $userId AND status = 'active'");
    return $r && $r->num_rows > 0;
}

/** "2026-09-25T14:30" (datetime-local) or "" -> 'Y-m-d H:i:s' or null. */
function notes_parse_deadline($v) {
    $v = trim((string) $v);
    if ($v === '') { return null; }
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Validated post-it fields from the request (title, color, deadline, remind, page). */
function notes_meta_from_request() {
    $color = strtolower(trim($_POST['color'] ?? 'yellow'));
    if (!in_array($color, notes_colors(), true)) { $color = 'yellow'; }
    $deadline = notes_parse_deadline($_POST['deadline'] ?? '');
    // Reminder: '' = none, 0 = at the deadline, N = N minutes before. Needs a deadline.
    $rawRemind = trim((string) ($_POST['remind_before_mins'] ?? ''));
    $remind = ($rawRemind === '' || !$deadline) ? null : max(0, min(NOTES_REMIND_MAX_BEFORE, (int) $rawRemind));
    $repeat = (int) ($_POST['remind_repeat_mins'] ?? 0);
    $repeat = ($remind === null || $repeat <= 0) ? null : max(NOTES_REPEAT_MIN, min(NOTES_REPEAT_MAX, $repeat));
    $via = $_POST['remind_via'] ?? 'both';
    if (!in_array($via, ['both', 'bell', 'email'], true)) { $via = 'both'; }
    $title = trim($_POST['title'] ?? '');
    $title = $title === '' ? null : mb_substr($title, 0, 120);
    $pageKey = notes_clean_page_key($_POST['page_key'] ?? '');
    $pageLabel = $pageKey ? mb_substr(trim($_POST['page_label'] ?? ''), 0, 120) : null;
    if ($pageLabel === '') { $pageLabel = null; }
    return ['title' => $title, 'color' => $color, 'deadline' => $deadline, 'remind' => $remind,
            'repeat' => $repeat, 'via' => $via, 'next' => notes_first_remind_at($deadline, $remind),
            'page_key' => $pageKey, 'page_label' => $pageLabel];
}

/** Replace a note's share rows from a [{user_id, permission}] list, keeping only real
 *  project members (or, for a project-less note, active users). Returns the number written. */
function notes_write_shares($c, $noteId, $projectId, $shares, $sharedBy) {
    $noteId = (int) $noteId;
    $c->query("DELETE FROM ten_note_shares WHERE note_id = $noteId");
    if (!is_array($shares)) { return 0; }
    $written = 0;
    $ins = $c->prepare("INSERT INTO ten_note_shares (note_id, user_id, permission, shared_by) VALUES (?, ?, ?, ?)");
    foreach ($shares as $s) {
        $uid  = (int) ($s['user_id'] ?? 0);
        $perm = ($s['permission'] ?? 'view') === 'reply' ? 'reply' : 'view';
        if ($uid <= 0 || $uid === (int) $sharedBy) { continue; }          // don't share to self
        if ($projectId) {
            if (!notes_is_project_member($c, $projectId, $uid)) { continue; } // members only
        } elseif (!notes_is_active_user($c, $uid)) { continue; }
        $ins->bind_param("iisi", $noteId, $uid, $perm, $sharedBy);
        $ins->execute();
        $written++;
    }
    $ins->close();
    return $written;
}

try {
    switch ($action) {

        // ── Project members for the share picker ──────────────────────────────
        case 'members': {
            $projectId = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
            if (!notes_is_project_member($c, $projectId, $me)) {
                $resp = ['success' => false, 'message' => 'Not a member of this project'];
                break;
            }
            $members = array_map(function ($m) use ($me) {
                return [
                    'id'     => (int) $m['id'],
                    'name'   => $m['full_name'],
                    'avatar' => $m['profile_image'] ?? '',
                    'is_me'  => ((int) $m['id'] === (int) $me),
                ];
            }, notes_project_members($c, $projectId));
            $resp = ['success' => true, 'members' => $members];
            break;
        }

        // ── Projects the caller can move a note into (member/creator; admin: all) ──
        case 'projects': {
            if (isAdmin()) {
                $res = $c->query("SELECT id, project_name FROM ten_projects ORDER BY project_name");
            } else {
                $st = $c->prepare("
                    SELECT DISTINCT p.id, p.project_name FROM ten_projects p
                    LEFT JOIN ten_project_members m ON m.project_id = p.id AND m.user_id = ?
                    WHERE p.created_by = ? OR m.user_id IS NOT NULL
                    ORDER BY p.project_name");
                $st->bind_param("ii", $me, $me);
                $st->execute();
                $res = $st->get_result();
            }
            $projects = [];
            while ($res && ($row = $res->fetch_assoc())) { $projects[] = ['id' => (int) $row['id'], 'name' => $row['project_name']]; }
            $resp = ['success' => true, 'projects' => $projects];
            break;
        }

        // ── Tasks in a project (for the attach-to-task picker) ────────────────
        case 'tasks': {
            $projectId = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
            if (!notes_is_project_member($c, $projectId, $me)) { $resp = ['success' => false, 'message' => 'Not a member of this project']; break; }
            $st = $c->prepare("SELECT id, task_name FROM ten_project_tasks WHERE project_id = ? ORDER BY task_name");
            $st->bind_param("i", $projectId);
            $st->execute();
            $res = $st->get_result();
            $tasks = [];
            while ($res && ($row = $res->fetch_assoc())) { $tasks[] = ['id' => (int) $row['id'], 'name' => $row['task_name']]; }
            $st->close();
            $resp = ['success' => true, 'tasks' => $tasks];
            break;
        }

        // ── List notes the caller can see in a project ────────────────────────
        case 'list': {
            $projectId = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
            $view = $_POST['view'] ?? $_GET['view'] ?? 'open';
            if (!notes_is_project_member($c, $projectId, $me)) {
                $resp = ['success' => false, 'message' => 'Not a member of this project'];
                break;
            }
            $statusCond = notes_view_sql($view);
            $admin = isAdmin() ? 1 : 0;
            // Visible = mine, or shared to me, or (admin sees all).
            $sql = "
                SELECT n.* FROM ten_project_notes n
                LEFT JOIN ten_note_shares s ON s.note_id = n.id AND s.user_id = ?
                WHERE n.project_id = ?
                  AND $statusCond
                  AND ( n.author_id = ? OR s.id IS NOT NULL OR ? = 1 )
                GROUP BY n.id
                ORDER BY FIELD(n.status,'in_progress','active','on_hold','completed','archived','deleted'),
                         (n.deadline IS NULL), n.deadline, n.created_at DESC
            ";
            $st = $c->prepare($sql);
            $st->bind_param("iiii", $me, $projectId, $me, $admin);
            $st->execute();
            $res = $st->get_result();
            $notes = [];
            while ($row = $res->fetch_assoc()) { $notes[] = notes_present_note($c, $row, $me); }
            $st->close();
            $resp = ['success' => true, 'notes' => $notes, 'counts' => notes_view_counts($c, "n.project_id = " . (int) $projectId, $me)];
            break;
        }

        // ── One note + its replies + (for the owner) its share list ───────────
        case 'get': {
            $noteId = (int) ($_POST['note_id'] ?? $_GET['note_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || $note['status'] === 'deleted' || !notes_can_access($c, $note, $me)) {
                $resp = ['success' => false, 'message' => 'Note not found']; break;
            }
            $data = notes_present_note($c, $note, $me);

            // Replies
            $st = $c->prepare("SELECT * FROM ten_note_replies WHERE note_id = ? AND status = 'active' ORDER BY created_at ASC");
            $st->bind_param("i", $noteId);
            $st->execute();
            $rr = $st->get_result();
            $replies = [];
            while ($row = $rr->fetch_assoc()) {
                $a = notes_user_brief($c, $row['author_id']);
                $replies[] = [
                    'id' => (int) $row['id'], 'note_id' => (int) $row['note_id'],
                    'author_id' => (int) $row['author_id'], 'author_name' => $a['name'], 'author_avatar' => $a['avatar'],
                    'type' => $row['type'], 'body' => $row['body'],
                    'created_at' => $row['created_at'], 'edited_at' => $row['edited_at'],
                    'is_mine' => ((int) $row['author_id'] === (int) $me),
                    'can_edit' => $row['type'] !== 'event' && notes_can_edit($row, $me),
                ];
            }
            $st->close();
            $data['replies'] = $replies;
            notes_mark_read($c, $noteId, $me);

            // Share list (only the manager needs it)
            $data['shares'] = [];
            if (notes_can_manage($note, $me)) {
                $st = $c->prepare("
                    SELECT s.user_id, s.permission, u.full_name
                    FROM ten_note_shares s JOIN ten_users u ON u.id = s.user_id
                    WHERE s.note_id = ? ORDER BY u.full_name");
                $st->bind_param("i", $noteId);
                $st->execute();
                $sr = $st->get_result();
                while ($row = $sr->fetch_assoc()) {
                    $data['shares'][] = ['user_id' => (int) $row['user_id'], 'name' => $row['full_name'], 'permission' => $row['permission']];
                }
                $st->close();
            }
            $resp = ['success' => true, 'note' => $data];
            break;
        }

        // ── Create a note ─────────────────────────────────────────────────────
        case 'create': {
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');
            $visibility = ($_POST['visibility'] ?? 'private') === 'shared' ? 'shared' : 'private';
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $shares = json_decode($_POST['shares'] ?? '[]', true);
            $m = notes_meta_from_request();

            // Project is optional (page notes); when given, the caller must belong to it.
            if ($projectId > 0 && !notes_is_project_member($c, $projectId, $me)) { $resp = ['success' => false, 'message' => 'Not a member of this project']; break; }
            if ($body === '' && $m['title'] === null) { $resp = ['success' => false, 'message' => 'Write something first']; break; }
            $projectVal = $projectId > 0 ? $projectId : null;

            // A task, if given, must belong to this project.
            $taskVal = null;
            if ($projectVal && $taskId > 0 && notes_task_in_project($c, $taskId, $projectId)) { $taskVal = $taskId; }

            $st = $c->prepare("INSERT INTO ten_project_notes
                (project_id, task_id, author_id, type, title, color, body, visibility, status, deadline,
                 remind_before_mins, remind_repeat_mins, remind_via, next_remind_at, page_key, page_label)
                VALUES (?, ?, ?, 'text', ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("iiisssssiissss", $projectVal, $taskVal, $me, $m['title'], $m['color'], $body, $visibility,
                $m['deadline'], $m['remind'], $m['repeat'], $m['via'], $m['next'], $m['page_key'], $m['page_label']);
            $st->execute();
            $noteId = $st->insert_id;
            $st->close();

            if ($visibility === 'shared') { notes_write_shares($c, $noteId, $projectVal, $shares, $me); }

            $resp = ['success' => true, 'note_id' => $noteId, 'note' => notes_present_note($c, notes_get_note($c, $noteId), $me)];
            break;
        }

        // ── Post-it properties: title, colour, deadline, reminder, page pin ────
        //    (author or admin, any time; only the body has the 30-minute lock)
        case 'meta': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || $note['status'] === 'deleted' || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            $m = notes_meta_from_request();
            // Re-plan the reminder schedule when any reminder setting changes. A moved deadline
            // or lead time starts afresh; adding/changing only the repeat continues from the last send.
            $oldRemind = $note['remind_before_mins'] === null ? null : (int) $note['remind_before_mins'];
            $oldRepeat = $note['remind_repeat_mins'] === null ? null : (int) $note['remind_repeat_mins'];
            $timingChanged = ($m['deadline'] !== $note['deadline']) || ($m['remind'] !== $oldRemind);
            $next = $note['next_remind_at'];
            if ($timingChanged) {
                $next = $m['next'];
            } elseif ($m['repeat'] !== $oldRepeat) {
                if ($m['remind'] === null) { $next = null; }
                elseif (!empty($note['reminder_sent_at'])) {
                    $next = $m['repeat'] ? date('Y-m-d H:i:s', strtotime($note['reminder_sent_at']) + $m['repeat'] * 60) : null;
                } else { $next = $m['next']; }
            }
            if (!notes_is_live($note['status'])) { $next = null; } // on hold / done: re-planned when reopened
            $st = $c->prepare("UPDATE ten_project_notes SET title = ?, color = ?, deadline = ?, remind_before_mins = ?,
                               remind_repeat_mins = ?, remind_via = ?, next_remind_at = ?, page_key = ?, page_label = ?"
                               . ($timingChanged ? ", reminder_sent_at = NULL, remind_count = 0" : "") . " WHERE id = ?");
            $st->bind_param("sssiissssi", $m['title'], $m['color'], $m['deadline'], $m['remind'], $m['repeat'], $m['via'],
                $next, $m['page_key'], $m['page_label'], $noteId);
            $st->execute();
            $st->close();
            $resp = ['success' => true, 'note' => notes_present_note($c, notes_get_note($c, $noteId), $me)];
            break;
        }

        // ── Active users (share picker for notes without a project) ───────────
        case 'users': {
            $res = $c->query("SELECT id, full_name, profile_image FROM ten_users WHERE status = 'active' ORDER BY full_name");
            $users = [];
            while ($res && ($u = $res->fetch_assoc())) {
                $users[] = ['id' => (int) $u['id'], 'name' => $u['full_name'], 'avatar' => $u['profile_image'] ?? '', 'is_me' => ((int) $u['id'] === $me)];
            }
            $resp = ['success' => true, 'members' => $users];
            break;
        }

        // ── Header panel: notes pinned to a page + my deadline notes ──────────
        case 'page_panel': {
            $pageKey = notes_clean_page_key($_POST['page_key'] ?? $_GET['page_key'] ?? '');
            $view = $_POST['view'] ?? 'open';
            $vis = notes_mine_or_shared_sql();
            $pageNotes = [];
            $counts = null;
            if ($pageKey) {
                $st = $c->prepare("SELECT n.* FROM ten_project_notes n WHERE " . notes_view_sql($view) . " AND n.page_key = ? AND $vis
                                   ORDER BY (n.deadline IS NULL), n.deadline, n.created_at DESC");
                $st->bind_param("sii", $pageKey, $me, $me);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) { $pageNotes[] = notes_present_note($c, $row, $me); }
                $st->close();
                $counts = notes_view_counts($c, "n.page_key = '" . $c->real_escape_string($pageKey) . "' AND "
                    . str_replace('?', (string) (int) $me, notes_mine_or_shared_sql()), $me, false);
            }
            $st = $c->prepare("SELECT n.* FROM ten_project_notes n WHERE n.status IN " . NOTES_LIVE_SQL . " AND n.deadline IS NOT NULL AND $vis
                               ORDER BY n.deadline ASC LIMIT 50");
            $st->bind_param("ii", $me, $me);
            $st->execute();
            $res = $st->get_result();
            $deadlines = [];
            while ($row = $res->fetch_assoc()) { $deadlines[] = notes_present_note($c, $row, $me); }
            $st->close();
            $resp = ['success' => true, 'page_notes' => $pageNotes, 'page_counts' => $counts, 'deadlines' => $deadlines,
                     'summary' => notes_header_summary($c, $me, $pageKey)];
            break;
        }

        // ── Edit a note (author, inside the 30-minute window) ─────────────────
        case 'update': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');
            $note = notes_get_note($c, $noteId);
            if (!$note) { $resp = ['success' => false, 'message' => 'Note not found']; break; }
            if (!notes_can_edit($note, $me)) { $resp = ['success' => false, 'message' => 'This note can no longer be edited (30-minute window passed)']; break; }
            if ($body === '') { $resp = ['success' => false, 'message' => 'Note cannot be empty']; break; }
            $st = $c->prepare("UPDATE ten_project_notes SET body = ?, edited_at = NOW() WHERE id = ?");
            $st->bind_param("si", $body, $noteId);
            $st->execute();
            $st->close();
            $resp = ['success' => true, 'note' => notes_present_note($c, notes_get_note($c, $noteId), $me)];
            break;
        }

        // ── Status lifecycle: open / in progress / on hold / completed / archived / deleted ──
        //    (archive/unarchive/delete/restore kept as shorthands). Side effects on reminders
        //    and the bell live in notes_set_status().
        case 'archive':
        case 'unarchive':
        case 'delete':
        case 'restore':
        case 'set_status': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $map = ['archive' => 'archived', 'unarchive' => 'active', 'delete' => 'deleted', 'restore' => 'active'];
            $to = $map[$action] ?? (string) ($_POST['status'] ?? '');
            $note = notes_get_note($c, $noteId);
            if (!$note || !in_array($to, notes_statuses(), true)) { $resp = ['success' => false, 'message' => 'Note not found']; break; }
            if (!notes_can_access($c, $note, $me) || !notes_can_set_status($c, $note, $me, $to)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            $updated = notes_set_status($c, $note, $to, $me);
            $resp = ['success' => true, 'status' => $to, 'note' => notes_present_note($c, $updated, $me)];
            break;
        }

        // ── Snooze: push the next reminder to a later time (note stays live) ───
        case 'snooze': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            if (!notes_is_live($note['status']) || $note['remind_before_mins'] === null) { $resp = ['success' => false, 'message' => 'This note has no active reminder to snooze']; break; }
            $mins = (int) ($_POST['minutes'] ?? 0);
            $until = $mins > 0 ? date('Y-m-d H:i:s', time() + min($mins, 525600) * 60) : notes_parse_deadline($_POST['until'] ?? '');
            if (!$until || strtotime($until) <= time()) { $resp = ['success' => false, 'message' => 'Pick a time in the future']; break; }
            $st = $c->prepare("UPDATE ten_project_notes SET next_remind_at = ?, snoozed_until = ? WHERE id = ?");
            $st->bind_param("ssi", $until, $until, $noteId);
            $st->execute();
            $st->close();
            $c->query("DELETE FROM ten_user_notifications WHERE note_id = $noteId AND kind = 'note_reminder' AND is_read = 0");
            $resp = ['success' => true, 'note' => notes_present_note($c, notes_get_note($c, $noteId), $me)];
            break;
        }

        // ── Change visibility + share list (author or admin) ──────────────────
        case 'share': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $visibility = ($_POST['visibility'] ?? 'private') === 'shared' ? 'shared' : 'private';
            $shares = json_decode($_POST['shares'] ?? '[]', true);
            $note = notes_get_note($c, $noteId);
            if (!$note || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }

            $st = $c->prepare("UPDATE ten_project_notes SET visibility = ? WHERE id = ?");
            $st->bind_param("si", $visibility, $noteId);
            $st->execute();
            $st->close();

            if ($visibility === 'shared') {
                $n = notes_write_shares($c, $noteId, $note['project_id'] !== null ? (int) $note['project_id'] : null, $shares, $me);
            } else {
                $c->query("DELETE FROM ten_note_shares WHERE note_id = $noteId");
                $n = 0;
            }
            $resp = ['success' => true, 'visibility' => $visibility, 'shared_with' => $n];
            break;
        }

        // ── Move a note to another project (author or admin) ──────────────────
        case 'reassign': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $targetProject = (int) ($_POST['target_project_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            if (!notes_is_project_member($c, $targetProject, $me)) { $resp = ['success' => false, 'message' => 'You are not a member of the target project']; break; }

            // Detach from any task and move. Drop shares for users who aren't members of the new project.
            $st = $c->prepare("UPDATE ten_project_notes SET project_id = ?, task_id = NULL WHERE id = ?");
            $st->bind_param("ii", $targetProject, $noteId);
            $st->execute();
            $st->close();

            $sr = $c->query("SELECT user_id FROM ten_note_shares WHERE note_id = $noteId");
            while ($sr && ($row = $sr->fetch_assoc())) {
                if (!notes_is_project_member($c, $targetProject, (int) $row['user_id'])) {
                    $uid = (int) $row['user_id'];
                    $c->query("DELETE FROM ten_note_shares WHERE note_id = $noteId AND user_id = $uid");
                }
            }
            $resp = ['success' => true, 'project_id' => $targetProject];
            break;
        }

        // ── Attach / detach a task (author or admin) ──────────────────────────
        case 'attach_task': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }

            if ($taskId > 0) {
                if (!notes_task_in_project($c, $taskId, (int) $note['project_id'])) { $resp = ['success' => false, 'message' => 'That task is not in this project']; break; }
                $st = $c->prepare("UPDATE ten_project_notes SET task_id = ? WHERE id = ?");
                $st->bind_param("ii", $taskId, $noteId);
            } else {
                $st = $c->prepare("UPDATE ten_project_notes SET task_id = NULL WHERE id = ?");
                $st->bind_param("i", $noteId);
            }
            $st->execute();
            $st->close();
            $resp = ['success' => true, 'task_id' => $taskId > 0 ? $taskId : null];
            break;
        }

        // ── Post a reply (author or reply-permission sharee) ──────────────────
        case 'reply_create': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');
            $note = notes_get_note($c, $noteId);
            if (!$note || $note['status'] === 'deleted' || !notes_can_reply($c, $note, $me)) { $resp = ['success' => false, 'message' => 'You cannot reply to this note']; break; }
            if ($body === '') { $resp = ['success' => false, 'message' => 'Write a reply first']; break; }
            $st = $c->prepare("INSERT INTO ten_note_replies (note_id, author_id, type, body, status) VALUES (?, ?, 'text', ?, 'active')");
            $st->bind_param("iis", $noteId, $me, $body);
            $st->execute();
            $rid = $st->insert_id;
            $st->close();
            notes_mark_read($c, $noteId, $me); // my own reply isn't "new" to me
            $a = notes_user_brief($c, $me);
            $reply = notes_get_reply($c, $rid);
            $resp = ['success' => true, 'reply' => [
                'id' => (int) $rid, 'note_id' => $noteId, 'author_id' => $me,
                'author_name' => $a['name'], 'author_avatar' => $a['avatar'],
                'type' => 'text', 'body' => $body,
                'created_at' => $reply['created_at'], 'edited_at' => null,
                'is_mine' => true, 'can_edit' => true,
            ]];
            break;
        }

        // ── Edit a reply (author, inside the 30-minute window) ────────────────
        case 'reply_update': {
            $replyId = (int) ($_POST['reply_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');
            $reply = notes_get_reply($c, $replyId);
            if (!$reply || $reply['type'] === 'event') { $resp = ['success' => false, 'message' => 'Reply not found']; break; }
            if (!notes_can_edit($reply, $me)) { $resp = ['success' => false, 'message' => 'This reply can no longer be edited (30-minute window passed)']; break; }
            if ($body === '') { $resp = ['success' => false, 'message' => 'Reply cannot be empty']; break; }
            $st = $c->prepare("UPDATE ten_note_replies SET body = ?, edited_at = NOW() WHERE id = ?");
            $st->bind_param("si", $body, $replyId);
            $st->execute();
            $st->close();
            $resp = ['success' => true, 'reply_id' => $replyId, 'body' => $body];
            break;
        }

        // ── Delete a reply (author or admin) ──────────────────────────────────
        case 'reply_delete': {
            $replyId = (int) ($_POST['reply_id'] ?? 0);
            $reply = notes_get_reply($c, $replyId);
            if (!$reply || $reply['type'] === 'event') { $resp = ['success' => false, 'message' => 'Reply not found']; break; }
            if ((int) $reply['author_id'] !== (int) $me && !isAdmin()) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            $c->query("UPDATE ten_note_replies SET status = 'deleted' WHERE id = " . (int) $replyId);
            $resp = ['success' => true];
            break;
        }
    }
} catch (Throwable $e) {
    error_log('project_notes: ' . $e->getMessage());
    $resp = ['success' => false, 'message' => 'Server error'];
}

echo json_encode($resp);

/** Does a task belong to the given project? */
function notes_task_in_project($c, $taskId, $projectId) {
    $taskId = (int) $taskId; $projectId = (int) $projectId;
    $st = $c->prepare("SELECT id FROM ten_project_tasks WHERE id = ? AND project_id = ?");
    if (!$st) { return false; }
    $st->bind_param("ii", $taskId, $projectId);
    $st->execute();
    $ok = (bool) $st->get_result()->fetch_assoc();
    $st->close();
    return $ok;
}

/** Fetch a reply row (any status) or null. */
function notes_get_reply($c, $replyId) {
    $replyId = (int) $replyId;
    $st = $c->prepare("SELECT * FROM ten_note_replies WHERE id = ?");
    $st->bind_param("i", $replyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** SQL status condition for a list view: open | completed | archived | deleted | all (alias n). */
function notes_view_sql($view) {
    switch ($view) {
        case 'completed': return "n.status = 'completed'";
        case 'archived':  return "n.status = 'archived'";
        case 'deleted':   return "n.status = 'deleted'";
        case 'all':       return "n.status <> 'deleted'";
        default:          return "n.status IN " . NOTES_OPEN_SQL;
    }
}

/** Per-view note counts for the filter tabs. $where is trusted SQL on alias n. */
function notes_view_counts($c, $where, $me, $applyVisibility = true) {
    $me = (int) $me;
    $vis = ($applyVisibility && !isAdmin())
        ? " AND (n.author_id = $me OR EXISTS (SELECT 1 FROM ten_note_shares s WHERE s.note_id = n.id AND s.user_id = $me))" : "";
    $r = $c->query("SELECT SUM(n.status IN " . NOTES_OPEN_SQL . ") open_n, SUM(n.status = 'completed') completed_n,
                           SUM(n.status = 'archived') archived_n, SUM(n.status = 'deleted') deleted_n
                    FROM ten_project_notes n WHERE $where$vis")->fetch_assoc();
    return ['open' => (int) $r['open_n'], 'completed' => (int) $r['completed_n'],
            'archived' => (int) $r['archived_n'], 'deleted' => (int) $r['deleted_n']];
}
