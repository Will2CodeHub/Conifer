<?php
/**
 * TEN Site Menus — manage the shared admin_ten.main_menu table per publication.
 *
 * The main_menu table is read by every live site to build its nav + article
 * sections (SELECT ... WHERE edition = <site abbreviation>). We only ever write
 * DATA here (position, menu_item, section_item) or INSERT/DELETE rows — never
 * ALTER the table — so the schema all sites depend on stays untouched.
 *
 *   menu_item    (0/1) — whether the item shows in the site's navigation menu
 *   section_item (0/1) — whether the item is a valid article section
 *   position     (int) — order within the publication's menu
 *
 * All actions require menus.manage (admins bypass).
 */
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isAdmin() && !hasPermission('menus.manage')) {
    $response['message'] = 'Unauthorized — requires menus.manage';
    echo json_encode($response);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$conn = getDBConnection_TENAdmin();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit();
}

/** Publications that can own menu rows (pub_live), keyed by acronym. */
function menu_publications($conn): array {
    $out = [];
    $res = $conn->query("SELECT publication, title, url FROM publications WHERE pub_live = '1' ORDER BY title");
    while ($res && ($r = $res->fetch_assoc())) {
        $out[$r['publication']] = ['publication' => $r['publication'], 'title' => $r['title'], 'url' => rtrim((string)$r['url'], '/')];
    }
    return $out;
}

/** Slugify a section name into a URL path segment. */
function menu_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

try {
    $pubs = menu_publications($conn);

    switch ($action) {

        case 'list': {
            // Rows for one publication, ordered for display (top-level first).
            $edition = $_POST['edition'] ?? $_GET['edition'] ?? '';
            if (!isset($pubs[$edition])) {
                throw new Exception('Unknown publication: ' . $edition);
            }
            $stmt = $conn->prepare("SELECT id, name, url, title, position, menu_item, section_item, parent_item
                                    FROM main_menu WHERE edition = ?
                                    ORDER BY parent_item ASC, position ASC, id ASC");
            $stmt->bind_param('s', $edition);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['position'] = (int)$row['position'];
                $row['menu_item'] = (int)$row['menu_item'];
                $row['section_item'] = (int)$row['section_item'];
                $row['parent_item'] = (int)$row['parent_item'];
                $rows[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'edition' => $edition, 'rows' => $rows];
            break;
        }

        case 'toggle': {
            // Flip menu_item or section_item on one row.
            $id    = (int)($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = (int)($_POST['value'] ?? 0) ? 1 : 0;
            if ($id <= 0 || !in_array($field, ['menu_item', 'section_item'], true)) {
                throw new Exception('Invalid toggle request');
            }
            $stmt = $conn->prepare("UPDATE main_menu SET `$field` = ? WHERE id = ?");
            $stmt->bind_param('ii', $value, $id);
            $stmt->execute();
            $stmt->close();
            $response = ['success' => true, 'id' => $id, 'field' => $field, 'value' => $value];
            break;
        }

        case 'update': {
            // Edit name / url / title of one row.
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $url   = trim($_POST['url'] ?? '');
            $title = trim($_POST['title'] ?? '');
            if ($id <= 0 || $name === '') throw new Exception('Name is required');
            $stmt = $conn->prepare("UPDATE main_menu SET name = ?, url = ?, title = ? WHERE id = ?");
            $stmt->bind_param('sssi', $name, $url, $title, $id);
            $stmt->execute();
            $stmt->close();
            $response = ['success' => true, 'id' => $id];
            break;
        }

        case 'reorder': {
            // Persist a new top-level order. order = JSON array of row ids in
            // the sequence shown. Positions are rewritten 1..n for those ids.
            $edition = $_POST['edition'] ?? '';
            $order   = json_decode($_POST['order'] ?? '[]', true);
            if (!isset($pubs[$edition]) || !is_array($order)) throw new Exception('Invalid reorder request');
            $pos = 1;
            $stmt = $conn->prepare("UPDATE main_menu SET position = ? WHERE id = ? AND edition = ?");
            foreach ($order as $rid) {
                $rid = (int)$rid;
                if ($rid <= 0) continue;
                $stmt->bind_param('iis', $pos, $rid, $edition);
                $stmt->execute();
                $pos++;
            }
            $stmt->close();
            $response = ['success' => true, 'edition' => $edition, 'count' => $pos - 1];
            break;
        }

        case 'create': {
            // Create a new item and assign it to one or more publications.
            $name  = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $urlIn = trim($_POST['url'] ?? '');
            $menuItem    = (int)($_POST['menu_item'] ?? 1) ? 1 : 0;
            $sectionItem = (int)($_POST['section_item'] ?? 1) ? 1 : 0;
            $editions = $_POST['editions'] ?? [];
            if (!is_array($editions)) $editions = array_filter(array_map('trim', explode(',', (string)$editions)));
            if ($name === '') throw new Exception('Name is required');
            $editions = array_values(array_filter($editions, fn($e) => isset($pubs[$e])));
            if (!$editions) throw new Exception('Select at least one valid publication');

            $created = [];
            $ins = $conn->prepare("INSERT INTO main_menu (name, url, title, position, edition, menu_item, section_item, parent_item)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            foreach ($editions as $ed) {
                // append at end of this publication's top-level list
                $mp = $conn->query("SELECT COALESCE(MAX(position),0)+1 AS p FROM main_menu WHERE edition = '" . $conn->real_escape_string($ed) . "' AND parent_item = 0")->fetch_assoc();
                $pos = (int)$mp['p'];
                // per-publication url default
                $url = $urlIn;
                if ($url === '') {
                    $url = $pubs[$ed]['url'] . '/' . menu_slug($name);
                } elseif (count($editions) > 1 && preg_match('#^https?://#', $url)) {
                    // when one URL was given but assigning to many pubs, rehost per publication
                    $host = parse_url($pubs[$ed]['url'], PHP_URL_HOST);
                    if ($host) $url = preg_replace('#https?://[^/]+#', 'https://' . $host, $url);
                }
                $t = ($title !== '') ? $title : $name;
                $ins->bind_param('ssssiii', $name, $url, $t, $pos, $ed, $menuItem, $sectionItem);
                $ins->execute();
                $created[] = ['edition' => $ed, 'id' => (int)$conn->insert_id, 'position' => $pos, 'url' => $url];
            }
            $ins->close();
            $response = ['success' => true, 'created' => $created];
            break;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid id');
            $stmt = $conn->prepare("DELETE FROM main_menu WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $response = ['success' => true, 'id' => $id];
            break;
        }

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Throwable $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

$conn->close();
echo json_encode($response);
