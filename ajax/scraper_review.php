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
                'translate_error' => $r['translate_error'] ?? null,
                'cap_note' => $r['cap_note'] ?? null,
                'daily_count' => $section ? (int)$section['daily_count'] : 0,
                'auto_publish' => $section ? (int)$section['auto_publish'] : 0,
            ]);
            break;

        case 'history':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? $_GET['pub_section_id'] ?? 0);
            $days = (int)($_POST['days'] ?? $_GET['days'] ?? 30);
            echo json_encode(['success' => true, 'history' => scraper_history($pubSectionId, $days)]);
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

        case 'history_query':
            $f = [
                'project_id'  => (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0),
                'publication' => trim($_POST['publication'] ?? ''),
                'section'     => trim($_POST['section'] ?? ''),
                'state'       => trim($_POST['state'] ?? ''),
                'from'        => trim($_POST['from'] ?? ''),
                'to'          => trim($_POST['to'] ?? ''),
                'search'      => trim($_POST['search'] ?? ''),
            ];
            echo json_encode(['success' => true, 'rows' => scraper_history_query($f), 'filters' => scraper_history_filters($f['project_id'])]);
            break;

        case 'curate_pubs':
            $uid = (int)($_SESSION['ten_user_id'] ?? 0);
            echo json_encode(['success' => true, 'publications' => scraper_curate_publications_ordered($uid)]);
            break;

        case 'curate_sections':
            $pub = trim($_POST['publication'] ?? $_GET['publication'] ?? '');
            echo json_encode(['success' => true, 'sections' => scraper_curate_sections($pub)]);
            break;

        case 'curate_items':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? $_GET['pub_section_id'] ?? 0);
            $mode = ($_POST['mode'] ?? $_GET['mode'] ?? 'all') === 'curated' ? 'curated' : 'all';
            $date = trim($_POST['date'] ?? $_GET['date'] ?? '');
            echo json_encode(['success' => true, 'items' => scraper_curate_items($pubSectionId, $mode, $date), 'mode' => $mode]);
            break;

        case 'curate_action':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? 0);
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)), 'strlen');
            $publish = ($_POST['do'] ?? '') === 'publish';
            $results = scraper_promote_items($pubSectionId, $ids, $publish);
            $section = scraper_get_section($pubSectionId);
            if ($publish && $section) scraper_trigger_publication_cache($section['publication_key']);
            // Attach editor/live links for each newly-created article so the UI can badge + link.
            $pubKey = $section ? $section['publication_key'] : '';
            $rows = [];
            foreach ($results as $r) { if (!empty($r['ok'])) $rows[] = ['article_id' => $r['article_id'], 'publication_key' => $pubKey, 'status' => 'promoted']; }
            $linked = $rows ? scraper_attach_article_links($rows) : [];
            $byArt = [];
            foreach ($linked as $l) { $byArt[(int)$l['article_id']] = $l; }
            foreach ($results as &$r) {
                if (!empty($r['ok']) && isset($byArt[(int)$r['article_id']])) {
                    $l = $byArt[(int)$r['article_id']];
                    $r['editor_url'] = $l['editor_url']; $r['live_url'] = $l['live_url']; $r['row_state'] = $l['row_state'];
                }
            }
            unset($r);
            echo json_encode(['success' => true, 'results' => $results]);
            break;

        case 'curate_published_today':
            $pubSectionId = (int)($_POST['pub_section_id'] ?? $_GET['pub_section_id'] ?? 0);
            $r = scraper_published_today($pubSectionId);
            echo json_encode(['success' => true, 'items' => $r['items'], 'front_page_url' => $r['front_page_url']]);
            break;

        case 'save_pub_order':
            $uid = (int)($_SESSION['ten_user_id'] ?? 0);
            $order = trim($_POST['order'] ?? '');
            $arr = array_values(array_filter(array_map('trim', explode(',', $order)), 'strlen'));
            scraper_set_pref($uid, 'curate_pub_order', json_encode($arr));
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
