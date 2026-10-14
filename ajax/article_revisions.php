<?php
/**
 * Article edit-history endpoint: list / view+diff / restore past revisions.
 * Senior roles only (Admin / Super Admin / Super User / Administrator / Manager /
 * Editor-in-Chief). Journalists and Section Editors never reach it.
 */
session_start();
require_once '../config.php';
require_once '../config_ten_admin.php';
require_once __DIR__ . '/../lib/article_revisions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

/** Only senior/oversight roles may view or restore history. */
function ten_can_use_history(): bool {
    if (isAdmin()) { return true; }
    $position = $_SESSION['ten_position'] ?? '';
    return in_array($position, [
        'Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager', 'Editor-in-Chief',
    ], true);
}

if (!ten_can_use_history()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to view article history.']);
    exit;
}

/** Build the current article's snapshot map (same shape as a stored revision). */
function ten_current_article_snapshot($conn, int $articleId): ?array {
    $fields = ten_revision_fields();
    $cols = implode(', ', $fields);
    $stmt = $conn->prepare("SELECT $cols FROM articles WHERE id = ?");
    $stmt->bind_param('i', $articleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }

    // Per-publication flags → map pub => {frontpage, section_head, sponsored}.
    $pubFlags = [];
    if ($pf = $conn->prepare("SELECT publication, frontpage, section_head, sponsored FROM article_pub_flags WHERE article_id = ?")) {
        $pf->bind_param('i', $articleId);
        $pf->execute();
        $res = $pf->get_result();
        while ($r = $res->fetch_assoc()) {
            $pubFlags[$r['publication']] = [
                'frontpage'    => (int) $r['frontpage'],
                'section_head' => (int) $r['section_head'],
                'sponsored'    => (int) $r['sponsored'],
            ];
        }
        $pf->close();
    }
    $row['pub_flags'] = $pubFlags;
    return $row;
}

$action    = $_GET['action'] ?? $_POST['action'] ?? 'list';
$articleId = (int) ($_GET['article_id'] ?? $_POST['article_id'] ?? 0);

if ($articleId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing article id']);
    exit;
}

$conn = getDBConnection_TENAdmin();
ten_ensure_revisions_table($conn);

try {
    if ($action === 'list') {
        $stmt = $conn->prepare("SELECT id, edited_by, edited_by_name, edited_at, state, action
                                FROM article_revisions WHERE article_id = ? ORDER BY id DESC");
        $stmt->bind_param('i', $articleId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'             => (int) $r['id'],
                'edited_by'      => (int) $r['edited_by'],
                'edited_by_name' => $r['edited_by_name'],
                'edited_at'      => $r['edited_at'],
                'state'          => $r['state'],
                'action'         => $r['action'],
            ];
        }
        $stmt->close();
        $conn->close();
        echo json_encode(['status' => 'success', 'revisions' => $rows]);
        exit;
    }

    if ($action === 'current') {
        // The live article as a snapshot map, so the UI can use "Current" as one
        // side of a side-by-side comparison.
        $current = ten_current_article_snapshot($conn, $articleId);
        $conn->close();
        if ($current === null) {
            echo json_encode(['status' => 'error', 'message' => 'Article not found']);
            exit;
        }
        echo json_encode(['status' => 'success', 'snapshot' => $current]);
        exit;
    }

    if ($action === 'get') {
        $revId = (int) ($_GET['rev'] ?? 0);
        $stmt = $conn->prepare("SELECT id, edited_by_name, edited_at, state, action, snapshot
                                FROM article_revisions WHERE id = ? AND article_id = ?");
        $stmt->bind_param('ii', $revId, $articleId);
        $stmt->execute();
        $rev = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rev) {
            $conn->close();
            echo json_encode(['status' => 'error', 'message' => 'Revision not found']);
            exit;
        }
        $revSnap = json_decode((string) $rev['snapshot'], true) ?: [];
        $current = ten_current_article_snapshot($conn, $articleId) ?: [];
        $conn->close();
        echo json_encode([
            'status'   => 'success',
            'revision' => [
                'id'             => (int) $rev['id'],
                'edited_by_name' => $rev['edited_by_name'],
                'edited_at'      => $rev['edited_at'],
                'state'          => $rev['state'],
                'action'         => $rev['action'],
            ],
            'snapshot' => $revSnap,
            'current'  => $current,
            'diff'     => ten_diff_snapshots($revSnap, $current),
        ]);
        exit;
    }

    if ($action === 'restore') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $conn->close();
            echo json_encode(['status' => 'error', 'message' => 'Restore must be POST']);
            exit;
        }
        $revId = (int) ($_POST['rev'] ?? 0);
        $stmt = $conn->prepare("SELECT snapshot FROM article_revisions WHERE id = ? AND article_id = ?");
        $stmt->bind_param('ii', $revId, $articleId);
        $stmt->execute();
        $rev = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rev) {
            $conn->close();
            echo json_encode(['status' => 'error', 'message' => 'Revision not found']);
            exit;
        }
        $snap = json_decode((string) $rev['snapshot'], true);
        if (!is_array($snap)) {
            $conn->close();
            echo json_encode(['status' => 'error', 'message' => 'Revision snapshot is unreadable']);
            exit;
        }

        // Overwrite the live article from the snapshot, then log the restore as a
        // new revision (so the restore itself is in the history).
        ten_apply_article_fields($conn, $articleId, $snap);
        $editorId   = (int) ($_SESSION['ten_user_id'] ?? 0);
        $editorName = $_SESSION['ten_full_name'] ?? ($_SESSION['ten_username'] ?? '');
        $restoredState = (string) ($snap['state'] ?? 'draft');
        ten_write_revision($conn, $articleId, $editorId, $editorName, $restoredState, 'restore',
            json_encode($snap, JSON_UNESCAPED_UNICODE));

        $publications = (string) ($snap['publications'] ?? '');
        $conn->close();

        echo json_encode([
            'status'     => 'success',
            'message'    => 'Version restored',
            'article_id' => $articleId,
            'state'      => $restoredState,
        ]);

        // If the restored version was published, rebuild the affected front pages
        // so the live site reflects the restore immediately.
        if ($restoredState === 'published') {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            ten_regenerate_publication_caches($publications);
        }
        exit;
    }

    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('article_revisions.php error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
