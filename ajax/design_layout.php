<?php
/**
 * TEN Design System — editor AJAX endpoint.
 * actions: load | save_draft | publish | list_versions | rollback
 * Reads: any logged-in user. Writes (save_draft/publish/rollback): admin only.
 * All design data lives in admin_ten (see lib/design_layouts_db.php).
 */
ini_set('display_errors', '0'); // JSON endpoint — keep warnings out of the body

require_once '../config.php';
require_once __DIR__ . '/../lib/design_layouts_db.php';
requireLogin();

header('Content-Type: application/json');

function dl_pub_enabled(string $pub): bool {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return false; }
    $st = $conn->prepare("SELECT design_enabled FROM publications WHERE publication=? LIMIT 1");
    $st->bind_param('s', $pub);
    $st->execute();
    $enabled = (int)($st->get_result()->fetch_assoc()['design_enabled'] ?? 0) === 1;
    $st->close();
    $conn->close();
    return $enabled;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pub    = trim((string)($_POST['pub'] ?? $_GET['pub'] ?? ''));
$user   = $_SESSION['ten_full_name'] ?? $_SESSION['ten_username'] ?? 'unknown';

try {
    if ($pub === '' || !dl_pub_enabled($pub)) {
        echo json_encode(['success' => false, 'message' => 'Design not enabled for this publication']);
        exit;
    }

    switch ($action) {
        case 'load':
            $page   = (string)($_POST['page'] ?? $_GET['page'] ?? 'front');
            $device = (string)($_POST['device'] ?? $_GET['device'] ?? 'desktop');
            echo json_encode(['success' => true, 'layout' => dl_load_draft($pub, $page, $device)]);
            break;

        case 'save_draft':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $page   = (string)($_POST['page'] ?? '');
            $device = (string)($_POST['device'] ?? 'desktop');
            $layout = json_decode((string)($_POST['layout_json'] ?? ''), true);
            if (!is_array($layout)) { echo json_encode(['success' => false, 'message' => 'Invalid layout_json']); exit; }
            dl_save_draft($pub, $page, $device, $layout, $user);
            logActivity('design_save_draft', 'publication', 0, "draft $pub/$page/$device");
            echo json_encode(['success' => true]);
            break;

        case 'publish':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $label = trim((string)($_POST['label'] ?? '')) ?: null;
            $v = dl_publish($pub, $label, $user);
            logActivity('design_publish', 'publication', 0, "publish $pub v$v");
            echo json_encode(['success' => true, 'version_no' => $v]);
            break;

        case 'list_versions':
            echo json_encode(['success' => true, 'versions' => dl_list_versions($pub)]);
            break;

        case 'link_targets':
            // Section pages for this publication (for the WYSIWYG link picker).
            // main_menu.edition stores the same acronym as publications.publication.
            $sections = [];
            $conn = getDBConnection_TENAdmin();
            if ($conn) {
                $st = $conn->prepare("SELECT name FROM main_menu
                    WHERE edition = ? AND section_item = '1' AND parent_item = 0
                    ORDER BY position ASC");
                $st->bind_param('s', $pub);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $name = trim((string)$row['name']);
                    if ($name === '') { continue; }
                    $slug = strtolower(str_replace(' ', '-', $name));
                    $sections[] = ['name' => $name, 'href' => '/design/' . $slug];
                }
                $st->close();
                $conn->close();
            }
            echo json_encode(['success' => true, 'sections' => $sections]);
            break;

        case 'rollback':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $vno = (int)($_POST['version_no'] ?? 0);
            dl_rollback($pub, $vno, $user);
            logActivity('design_rollback', 'publication', 0, "rollback $pub to v$vno");
            echo json_encode(['success' => true]);
            break;

        case 'pub_info':
            $conn = getDBConnection_TENAdmin(); $host = '';
            if ($conn) {
                $s = $conn->prepare("SELECT url FROM publications WHERE publication=? LIMIT 1");
                $s->bind_param('s', $pub); $s->execute();
                $host = (string)($s->get_result()->fetch_assoc()['url'] ?? '');
                $s->close(); $conn->close();
            }
            $host = preg_replace('#^https?://#', '', $host);
            $host = preg_replace('#/.*$#', '', $host);
            echo json_encode(['success' => true, 'host' => $host]);
            break;

        case 'list_pages':
            echo json_encode(['success' => true, 'builtin' => dl_builtin_pages(), 'pages' => dl_load_pages($pub)['pages']]);
            break;

        case 'list_templates':
            echo json_encode(['success' => true, 'templates' => dl_load_templates($pub)['templates']]);
            break;

        case 'list_blocks':
            $meta = dl_load_blocks_meta($pub);
            echo json_encode(['success' => true, 'names' => $meta['names'], 'types' => array_keys($meta['templates'])]);
            break;

        case 'create_page':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $row = dl_create_page($pub, (string)($_POST['slug'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['template'] ?? 'tpl_default'), $user);
            logActivity('design_create_page', 'publication', 0, "page $pub/{$row['slug']}");
            echo json_encode(['success' => true, 'page' => $row]);
            break;

        case 'create_template':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $row = dl_create_template($pub, (string)($_POST['name'] ?? ''), $user);
            logActivity('design_create_template', 'publication', 0, "tpl $pub/{$row['id']}");
            echo json_encode(['success' => true, 'template' => $row]);
            break;

        case 'create_block':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $type = dl_create_named_block($pub, (string)($_POST['name'] ?? ''), $user);
            logActivity('design_create_block', 'publication', 0, "block $pub/$type");
            echo json_encode(['success' => true, 'type' => $type]);
            break;

        case 'rename_item':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            dl_rename_item($pub, (string)($_POST['kind'] ?? ''), (string)($_POST['id'] ?? ''), (string)($_POST['name'] ?? ''), $user);
            logActivity('design_rename', 'publication', 0, "rename $pub " . (string)($_POST['kind'] ?? '') . ":" . (string)($_POST['id'] ?? ''));
            echo json_encode(['success' => true]);
            break;

        case 'block_default':
            // Server-side proxy for a block's built-in default template (the browser's
            // cross-origin fetch to the site is unreliable; this is same-origin + reliable).
            $type = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['type'] ?? $_POST['type'] ?? ''));
            $conn = getDBConnection_TENAdmin(); $url = '';
            if ($conn) {
                $s = $conn->prepare("SELECT url FROM publications WHERE publication=? LIMIT 1");
                $s->bind_param('s', $pub); $s->execute();
                $url = (string)($s->get_result()->fetch_assoc()['url'] ?? ''); $s->close(); $conn->close();
            }
            $host = preg_replace('#^https?://#', '', $url); $host = preg_replace('#/.*$#', '', $host);
            $tpl = '';
            if ($host !== '' && $type !== '') {
                $ctx = stream_context_create(['http' => ['timeout' => 6], 'https' => ['timeout' => 6]]);
                $tpl = @file_get_contents('https://' . $host . '/design/?block=' . urlencode($type) . '&tpl_default=1', false, $ctx);
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo ($tpl !== false ? $tpl : '');
            exit;

        case 'get_version_item':
            // One block's template, or one page's layout, from a published version
            // (for the per-editor "revert to a saved version" history).
            $vno  = (int)($_POST['version_no'] ?? $_GET['version_no'] ?? 0);
            $kind = (string)($_POST['kind'] ?? $_GET['kind'] ?? '');
            $id   = (string)($_POST['id'] ?? $_GET['id'] ?? '');
            $bundle = dl_get_version($pub, $vno) ?: [];
            if ($kind === 'block') {
                echo json_encode(['success' => true, 'template' => (string)($bundle['_blocks']['desktop']['templates'][$id] ?? '')]);
            } elseif ($kind === 'page') {
                echo json_encode(['success' => true, 'layout' => $bundle[$id]['desktop'] ?? null]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Unknown kind']);
            }
            break;

        case 'list_assign':
            echo json_encode(['success' => true, 'assign' => dl_load_assign($pub)]);
            break;

        case 'assign_template':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            dl_set_assign($pub, (string)($_POST['slug'] ?? ''), (string)($_POST['template'] ?? ''), $user);
            logActivity('design_assign_template', 'publication', 0, "assign $pub " . (string)($_POST['slug'] ?? '') . "=" . (string)($_POST['template'] ?? ''));
            echo json_encode(['success' => true]);
            break;

        case 'delete_template':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            dl_delete_template($pub, (string)($_POST['id'] ?? ''), $user);
            logActivity('design_delete_template', 'publication', 0, "delete tpl $pub/" . (string)($_POST['id'] ?? ''));
            echo json_encode(['success' => true]);
            break;

        case 'save_pages':
        case 'save_templates':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $meta = json_decode((string)($_POST['meta_json'] ?? ''), true);
            if (!is_array($meta)) { echo json_encode(['success' => false, 'message' => 'Invalid meta_json']); exit; }
            dl_save_draft($pub, $action === 'save_pages' ? '_pages' : '_templates', 'desktop', $meta, $user);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
