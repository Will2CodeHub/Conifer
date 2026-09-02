<?php
/**
 * Scraper config AJAX dispatcher (Phase 2).
 * POST params: entity (section|source|feed|vpn|prompt|refs), action (list|get|create|update|delete|save).
 * Reads allowed for scraper.use; writes require scraper.manage. Mirrors ajax/roles.php conventions.
 */
require_once '../config.php';
require_once __DIR__ . '/../scraper/lib/scraper_crud.php';
require_once __DIR__ . '/../scraper/lib/scraper_refs.php';
requireLogin();

header('Content-Type: application/json');

$canManage = hasPermission('scraper.manage') || isAdmin();
$canUse    = $canManage || hasPermission('scraper.use');
if (!$canUse) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$entity = $_POST['entity'] ?? $_GET['entity'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function scraper_require_manage(bool $canManage): void {
    if (!$canManage) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized (manage permission required)']);
        exit();
    }
}

try {
    switch ($entity . '.' . $action) {

        /* ---- reference dropdown data ---- */
        case 'refs.all':
            echo json_encode([
                'success'      => true,
                'publications' => scraper_get_publications(),
                'journalists'  => scraper_get_journalists(),
                'sections'     => scraper_get_ten_sections(),
                'models'       => scraper_get_ai_models(),
                'vpn_profiles' => scraper_list_vpn_profiles(),
            ]);
            break;

        /* ---- sections ---- */
        case 'section.list':
            echo json_encode(['success' => true, 'sections' => scraper_list_sections((int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0))]);
            break;
        case 'section.get':
            echo json_encode(['success' => true, 'section' => scraper_get_section((int)($_POST['id'] ?? $_GET['id'] ?? 0))]);
            break;
        case 'section.create':
            scraper_require_manage($canManage);
            $id = scraper_create_section($_POST);
            logActivity('scraper_create_section', 'scraper_section', $id, 'Created scraper section');
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        case 'section.update':
            scraper_require_manage($canManage);
            $ok = scraper_update_section((int)$_POST['id'], $_POST);
            echo json_encode(['success' => $ok]);
            break;
        case 'section.delete':
            scraper_require_manage($canManage);
            $ok = scraper_delete_section((int)$_POST['id']);
            echo json_encode(['success' => $ok]);
            break;

        /* ---- sources ---- */
        case 'source.list':
            echo json_encode(['success' => true, 'sources' => scraper_list_sources((int)($_POST['pub_section_id'] ?? $_GET['pub_section_id'] ?? 0))]);
            break;
        case 'source.create':
            scraper_require_manage($canManage);
            $id = scraper_create_source($_POST);
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        case 'source.update':
            scraper_require_manage($canManage);
            $ok = scraper_update_source((int)$_POST['id'], $_POST);
            echo json_encode(['success' => $ok]);
            break;
        case 'source.delete':
            scraper_require_manage($canManage);
            $ok = scraper_delete_source((int)$_POST['id']);
            echo json_encode(['success' => $ok]);
            break;

        /* ---- feeds ---- */
        case 'feed.list':
            echo json_encode(['success' => true, 'feeds' => scraper_list_feeds((int)($_POST['source_id'] ?? $_GET['source_id'] ?? 0))]);
            break;
        case 'feed.create':
            scraper_require_manage($canManage);
            $id = scraper_create_feed($_POST);
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        case 'feed.update':
            scraper_require_manage($canManage);
            $ok = scraper_update_feed((int)$_POST['id'], $_POST);
            echo json_encode(['success' => $ok]);
            break;
        case 'feed.delete':
            scraper_require_manage($canManage);
            $ok = scraper_delete_feed((int)$_POST['id']);
            echo json_encode(['success' => $ok]);
            break;

        /* ---- vpn profiles ---- */
        case 'vpn.list':
            echo json_encode(['success' => true, 'vpn_profiles' => scraper_list_vpn_profiles()]);
            break;
        case 'vpn.save':
            scraper_require_manage($canManage);
            $id = scraper_save_vpn_profile($_POST);
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        case 'vpn.delete':
            scraper_require_manage($canManage);
            $ok = scraper_delete_vpn_profile((int)$_POST['id']);
            echo json_encode(['success' => $ok]);
            break;

        /* ---- project prompt ---- */
        case 'prompt.get':
            echo json_encode(['success' => true, 'project' => scraper_get_project((int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0))]);
            break;
        case 'prompt.update':
            scraper_require_manage($canManage);
            $ok = scraper_update_project_prompt(
                (int)$_POST['project_id'],
                (string)($_POST['prompt'] ?? ''),
                (string)($_POST['provider'] ?? 'anthropic'),
                (string)($_POST['model'] ?? 'claude-sonnet-5')
            );
            echo json_encode(['success' => $ok]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown entity/action: $entity.$action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
