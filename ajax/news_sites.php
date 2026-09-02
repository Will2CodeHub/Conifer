<?php
/**
 * TEN News Sites AJAX dispatcher.
 * entity=publication (list|get|save) | feeds (list).
 * Reads: any logged-in user. Writes (save): admin only (edits live publications).
 */
ini_set('display_errors', '0'); // JSON endpoint — keep warnings out of the body

require_once '../config.php';
require_once __DIR__ . '/../scraper/lib/news_sites_db.php';
requireLogin();

header('Content-Type: application/json');

$entity = $_POST['entity'] ?? $_GET['entity'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($entity . '.' . $action) {

        case 'publication.list':
            echo json_encode(['success' => true, 'publications' => ns_list_publications()]);
            break;

        case 'publication.get':
            echo json_encode(['success' => true, 'publication' => ns_get_publication((int)($_POST['id'] ?? $_GET['id'] ?? 0))]);
            break;

        case 'publication.save':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit(); }
            if (trim((string)($_POST['publication'] ?? '')) === '' || trim((string)($_POST['title'] ?? '')) === '') {
                echo json_encode(['success' => false, 'message' => 'Acronym and site name are required']); exit();
            }
            $id = ns_save_publication($_POST);
            logActivity('news_site_save', 'publication', $id, 'Saved publication ' . ($_POST['publication'] ?? ''));
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'feeds.list':
            echo json_encode(['success' => true, 'feeds' => ns_feeds_for_publication((string)($_POST['publication'] ?? $_GET['publication'] ?? ''))]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown entity/action: $entity.$action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
