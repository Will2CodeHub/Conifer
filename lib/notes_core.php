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
    if (($post['status'] ?? 'active') !== 'active') { return false; }
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
