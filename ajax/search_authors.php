<?php
/**
 * Author finder for the article editor.
 *
 * The author dropdown (ajax/get_article_data.php) lists only ACTIVE editorial/
 * management users by default. This endpoint lets an editor search for any author
 * by name INCLUDING inactive accounts, so an old/retired journalist can still be
 * credited. Same author-role whitelist as the dropdown (byline-only rows with no
 * role and non-editorial verticals stay out); Journalists cannot reassign authors.
 */
session_start();
require_once '../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$position = $_SESSION['ten_position'] ?? '';
if ($position === 'Journalist') {
    // Journalists always author as themselves — no one else to search.
    echo json_encode(['status' => 'success', 'authors' => []]);
    exit;
}

$q = trim($_POST['q'] ?? $_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['status' => 'error', 'message' => 'Type at least 2 characters']);
    exit;
}

// Same author-eligible roles as the default dropdown.
$authorRoles = "'Journalist','Section Editor','Editor','Managing Editor','General Editor',"
             . "'Editor-in-Chief','Edition Editor-in-Chief','Administrator','Super User','Manager'";

try {
    $conn = getDBConnection();
    $like = '%' . $q . '%';
    // Any status (active + inactive); DISTINCT because a user may hold several roles.
    $sql = "SELECT DISTINCT u.id, u.full_name, u.username, u.status
            FROM ten_users u
            JOIN ten_user_roles ur ON u.id = ur.user_id
            JOIN ten_roles r ON ur.role_id = r.id
            WHERE (u.full_name LIKE ? OR u.username LIKE ?)
              AND r.role_name IN ($authorRoles)
            ORDER BY (u.status = 'active') DESC, u.full_name ASC
            LIMIT 30";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $authors = [];
    while ($r = $res->fetch_assoc()) {
        $active = ($r['status'] === 'active');
        $authors[] = [
            'id'     => (int) $r['id'],
            'name'   => $r['full_name'] . ' (' . $r['username'] . ')',
            'active' => $active,
        ];
    }
    $stmt->close();
    $conn->close();
    echo json_encode(['status' => 'success', 'authors' => $authors]);
} catch (Throwable $e) {
    error_log('search_authors: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
