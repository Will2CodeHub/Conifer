<?php
/**
 * Phase 5 review/promote AJAX.
 * entity=review, action=sections|list|promote. Allowed for scraper.use.
 */
ini_set('display_errors', '0');
set_time_limit(180);

require_once '../config.php';
require_once __DIR__ . '/../scraper/lib/scraper_review.php';
requireLogin();

header('Content-Type: application/json');

$canUse = hasPermission('scraper.use') || hasPermission('scraper.manage') || isAdmin();
if (!$canUse) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'sections':
            $projectId = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
            $all = scraper_list_sections($projectId);
            $out = [];
            foreach ($all as $s) {
                if ((int)$s['is_active'] !== 1) continue;
                $out[] = [
                    'id' => (int)$s['id'],
                    'publication_key' => $s['publication_key'],
                    'ten_section' => $s['ten_section'],
                    'daily_count' => (int)$s['daily_count'],
                    'auto_publish' => (int)$s['auto_publish'],
                ];
            }
            echo json_encode(['success' => true, 'sections' => $out]);
            break;

        case 'list':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? $_GET['pub_section_id'] ?? 0);
            $r = scraper_review_items($pubSectionId);
            $section = scraper_get_section($pubSectionId);
            echo json_encode([
                'success' => true,
                'items' => $r['items'],
                'language' => $r['language'],
                'daily_count' => $section ? (int)$section['daily_count'] : 0,
                'auto_publish' => $section ? (int)$section['auto_publish'] : 0,
            ]);
            break;

        case 'promote':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? 0);
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) {
                $ids = array_filter(array_map('trim', explode(',', $ids)), 'strlen');
            }
            $results = scraper_promote_items($pubSectionId, $ids);
            echo json_encode(['success' => true, 'results' => $results]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
