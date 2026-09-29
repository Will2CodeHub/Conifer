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

        case 'rollback':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
            $vno = (int)($_POST['version_no'] ?? 0);
            dl_rollback($pub, $vno, $user);
            logActivity('design_rollback', 'publication', 0, "rollback $pub to v$vno");
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
