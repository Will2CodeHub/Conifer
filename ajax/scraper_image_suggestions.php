<?php
/**
 * Return the scraper's royalty-free image suggestions for a promoted article,
 * looked up via the draft that links the article to its collated item.
 */
ini_set('display_errors', '0');
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$articleId = (int)($_GET['article_id'] ?? $_POST['article_id'] ?? 0);
if (!$articleId) {
    echo json_encode(['success' => true, 'suggestions' => []]);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT i.image_suggestions
     FROM ten_scraper_drafts d
     JOIN ten_scraper_items i ON i.id = d.item_id
     WHERE d.article_id = ?
     ORDER BY d.id DESC LIMIT 1"
);
$stmt->bind_param('i', $articleId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$suggestions = [];
if ($row && !empty($row['image_suggestions'])) {
    $suggestions = json_decode($row['image_suggestions'], true) ?: [];
}
echo json_encode(['success' => true, 'suggestions' => $suggestions]);
