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
        'project_id'  => (int) $note['project_id'],
        'task_id'     => $note['task_id'] !== null ? (int) $note['task_id'] : null,
        'author_id'   => (int) $note['author_id'],
        'author_name' => $author['name'],
        'author_avatar' => $author['avatar'],
        'type'        => $note['type'],
        'body'        => $note['body'],
        'visibility'  => $note['visibility'],
        'status'      => $note['status'],
        'created_at'  => $note['created_at'],
        'edited_at'   => $note['edited_at'],
        'is_mine'     => ((int) $note['author_id'] === (int) $me),
        'can_edit'    => notes_can_edit($note, $me),
        'can_manage'  => notes_can_manage($note, $me),
        'can_reply'   => notes_can_reply($c, $note, $me),
    ];
    if ($withReplyCount) {
        $nid = (int) $note['id'];
        $r = $c->query("SELECT COUNT(*) AS n FROM ten_note_replies WHERE note_id = $nid AND status = 'active'")->fetch_assoc();
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

/** Replace a note's share rows from a [{user_id, permission}] list, keeping only real
 *  project members. Returns the number of shares written. */
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
        if (!notes_is_project_member($c, $projectId, $uid)) { continue; } // members only
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
            $includeArchived = !empty($_POST['include_archived'] ?? $_GET['include_archived'] ?? '');
            if (!notes_is_project_member($c, $projectId, $me)) {
                $resp = ['success' => false, 'message' => 'Not a member of this project'];
                break;
            }
            $statusCond = $includeArchived ? "n.status IN ('active','archived')" : "n.status = 'active'";
            $admin = isAdmin() ? 1 : 0;
            // Visible = mine, or shared to me, or (admin sees all).
            $sql = "
                SELECT n.* FROM ten_project_notes n
                LEFT JOIN ten_note_shares s ON s.note_id = n.id AND s.user_id = ?
                WHERE n.project_id = ?
                  AND $statusCond
                  AND ( n.author_id = ? OR s.id IS NOT NULL OR ? = 1 )
                GROUP BY n.id
                ORDER BY n.created_at DESC
            ";
            $st = $c->prepare($sql);
            $st->bind_param("iiii", $me, $projectId, $me, $admin);
            $st->execute();
            $res = $st->get_result();
            $notes = [];
            while ($row = $res->fetch_assoc()) { $notes[] = notes_present_note($c, $row, $me); }
            $st->close();
            $resp = ['success' => true, 'notes' => $notes];
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
                    'can_edit' => notes_can_edit($row, $me),
                ];
            }
            $st->close();
            $data['replies'] = $replies;

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

            if (!notes_is_project_member($c, $projectId, $me)) { $resp = ['success' => false, 'message' => 'Not a member of this project']; break; }
            if ($body === '') { $resp = ['success' => false, 'message' => 'Write something first']; break; }

            // A task, if given, must belong to this project.
            $taskVal = null;
            if ($taskId > 0 && notes_task_in_project($c, $taskId, $projectId)) { $taskVal = $taskId; }

            $st = $c->prepare("INSERT INTO ten_project_notes (project_id, task_id, author_id, type, body, visibility, status) VALUES (?, ?, ?, 'text', ?, ?, 'active')");
            $st->bind_param("iiiss", $projectId, $taskVal, $me, $body, $visibility);
            $st->execute();
            $noteId = $st->insert_id;
            $st->close();

            if ($visibility === 'shared') { notes_write_shares($c, $noteId, $projectId, $shares, $me); }

            $resp = ['success' => true, 'note_id' => $noteId, 'note' => notes_present_note($c, notes_get_note($c, $noteId), $me)];
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

        // ── Archive / unarchive / delete (author or admin) ────────────────────
        case 'archive':
        case 'unarchive':
        case 'delete': {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $note = notes_get_note($c, $noteId);
            if (!$note || !notes_can_manage($note, $me)) { $resp = ['success' => false, 'message' => 'Not allowed']; break; }
            $newStatus = $action === 'archive' ? 'archived' : ($action === 'delete' ? 'deleted' : 'active');
            $st = $c->prepare("UPDATE ten_project_notes SET status = ? WHERE id = ?");
            $st->bind_param("si", $newStatus, $noteId);
            $st->execute();
            $st->close();
            $resp = ['success' => true, 'status' => $newStatus];
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
                $n = notes_write_shares($c, $noteId, (int) $note['project_id'], $shares, $me);
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
            if (!$reply) { $resp = ['success' => false, 'message' => 'Reply not found']; break; }
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
            if (!$reply) { $resp = ['success' => false, 'message' => 'Reply not found']; break; }
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
