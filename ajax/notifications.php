<?php
/**
 * Header bell feed.
 *   action=feed                     — list (newest first) + counts
 *   action=mark_read  key=<key>     — the user clicked an item
 *   action=delete     keys=<json>   — delete selected items from the bell
 *   action=mark_all_read
 * Also returns the post-it summary so both header badges refresh in one call.
 */
ini_set('display_errors', '0');
require_once '../config.php';
requireLogin();
require_once '../lib/notifications_core.php';
header('Content-Type: application/json');

$c = getDBConnection();
$me = (int) ($_SESSION['ten_user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'feed';

try {
    notes_ensure_schema($c);
    switch ($action) {
        case 'mark_read':
            ten_notifications_mark_read($c, $me, $_POST['key'] ?? '');
            break;
        case 'delete':
            $keys = json_decode($_POST['keys'] ?? '[]', true);
            ten_notifications_delete($c, $me, is_array($keys) ? $keys : []);
            break;
        case 'mark_all_read':
            ten_notifications_mark_all_read($c, $me);
            break;
    }
    $feed = ten_notifications_feed($c, $me);
    $pageKey = notes_clean_page_key($_POST['page_key'] ?? $_GET['page_key'] ?? '');
    echo json_encode(['success' => true, 'items' => $feed['items'], 'count' => $feed['count'],
                      'notes' => notes_header_summary($c, $me, $pageKey)]);
} catch (Throwable $e) {
    error_log('notifications: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
