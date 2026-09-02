<?php
/**
 * Download a stored image suggestion server-side and return it as a base64 data
 * URL (same-origin) so Cropper.js can load and export it. The URL is looked up
 * from our own stored suggestions (by article + index) — the client never sends
 * an arbitrary URL, avoiding SSRF.
 */
ini_set('display_errors', '0');
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$articleId = (int)($_POST['article_id'] ?? $_GET['article_id'] ?? 0);
$index = (int)($_POST['index'] ?? $_GET['index'] ?? -1);
if (!$articleId || $index < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing article/index']);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT i.image_suggestions FROM ten_scraper_drafts d
     JOIN ten_scraper_items i ON i.id = d.item_id
     WHERE d.article_id = ? ORDER BY d.id DESC LIMIT 1"
);
$stmt->bind_param('i', $articleId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$suggestions = ($row && !empty($row['image_suggestions'])) ? (json_decode($row['image_suggestions'], true) ?: []) : [];
if (!isset($suggestions[$index]) || empty($suggestions[$index]['url'])) {
    echo json_encode(['status' => 'error', 'message' => 'Suggestion not found']);
    exit();
}

$url = $suggestions[$index]['url'];
$attribution = $suggestions[$index]['attribution'] ?? '';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TENNewsBot/1.0)',
]);
$data = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$data || $code < 200 || $code >= 300) {
    echo json_encode(['status' => 'error', 'message' => 'Download failed (HTTP ' . $code . ')']);
    exit();
}
$info = getimagesizefromstring($data);
if (!$info) {
    echo json_encode(['status' => 'error', 'message' => 'Not a valid image']);
    exit();
}

echo json_encode([
    'status' => 'success',
    'dataUrl' => 'data:' . $info['mime'] . ';base64,' . base64_encode($data),
    'attribution' => $attribution,
]);
