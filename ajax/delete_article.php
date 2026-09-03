<?php
/**
 * Soft-delete an article: set state = 'deleted'. The row is KEPT in the DB
 * (never removed), so it disappears from the site but can be restored.
 */
ini_set('display_errors', '0');
error_reporting(0);
session_start();
require_once '../config.php';
require_once '../config_ten_admin.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$articleId = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($articleId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Article ID is required']);
    exit;
}

try {
    $userId = $_SESSION['ten_user_id'] ?? 0;
    $position = $_SESSION['ten_position'] ?? '';
    $isUserAdmin = isAdmin();

    $conn = getDBConnection_TENAdmin();

    // Load the article and check edit permission — keep role list in sync with
    // save_article.php / get_article_data.php.
    $checkStmt = $conn->prepare("SELECT journalist_id, section FROM articles WHERE id = ?");
    $checkStmt->bind_param('i', $articleId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'Article not found']);
        $conn->close();
        exit;
    }

    $canEdit = $isUserAdmin ||
               in_array($position, ['Admin', 'Super Admin', 'Editor-in-Chief', 'Managing Editor', 'General Editor', 'Edition Editor-in-Chief', 'Administrator', 'Manager', 'Editor', 'Super User']) ||
               ($position === 'Section Editor' && $existing['section'] === ($_SESSION['ten_section'] ?? null)) ||
               ($existing['journalist_id'] == $userId);

    if (!$canEdit) {
        echo json_encode(['status' => 'error', 'message' => 'You do not have permission to delete this article']);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("UPDATE articles SET state = 'deleted' WHERE id = ?");
    $stmt->bind_param('i', $articleId);
    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        echo json_encode(['status' => 'success', 'message' => 'Article moved to Deleted (kept in the database).', 'article_id' => $articleId]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete article: ' . $err]);
    }
} catch (Exception $e) {
    error_log('Error deleting article: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
