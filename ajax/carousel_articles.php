<?php
/**
 * Article picker for the per-article "More stories" carousel.
 *
 * Lists PUBLISHED articles that have an image, so an editor can hand-pick which
 * past articles lead this article's carousel. Browsable by section (the editor
 * defaults it to the article's own section) or across all sections, searchable
 * by title, paged 10 at a time with a thumbnail + title. Scoped to the editor's
 * publication(s) (admins see all). Two modes:
 *   - browse:  ?section=&q=&page=&exclude=   -> paged {results,total,page,limit}
 *   - hydrate: ?ids=12,9,45                   -> those articles, IN THAT ORDER
 */
require_once '../config_ten_admin.php';
requireLogin();
header('Content-Type: application/json');

$position = $_SESSION['ten_position'] ?? '';
$publication = $_SESSION['ten_publication'] ?? '';
$allowed = ['Admin','Super Admin','Super User','Administrator','Manager','Editor-in-Chief',
            'Edition Editor-in-Chief','Managing Editor','General Editor','Editor','Section Editor','Journalist'];
if (!isAdmin() && !in_array($position, $allowed, true)) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

/** Resolve an articles.image_url value to an absolute thumbnail URL. */
function cp_img_url(string $img): string {
    $img = trim($img);
    if ($img === '') { return ''; }
    if (preg_match('#^https?://#i', $img)) { return $img; }
    if (preg_match('/ten_(\d{4})_/', $img, $m)) {
        return 'https://theeyenewspapers.com/article_images/year_folders/' . $m[1] . '/' . $img;
    }
    return 'https://theeyenewspapers.com/article_images/' . $img;
}

$conn = getDBConnection_TENAdmin();
if (!$conn) { echo json_encode(['error' => 'db']); exit; }

// Publication scope: non-admins only see their own publication(s)' articles.
$userPubs = array_values(array_filter(array_map('trim', explode(',', (string)$publication)), fn($x) => $x !== ''));
$pubScope = '';
if (!isAdmin() && $userPubs) {
    $pubScope = '(' . implode(' OR ', array_map(fn($p) => "a.publications LIKE '%" . $conn->real_escape_string($p) . "%'", $userPubs)) . ')';
}

$common = "a.state='published' AND a.image_url IS NOT NULL AND a.image_url<>'' AND (a.imageless IS NULL OR a.imageless=0)";

// ---- hydrate mode: return specific ids in the requested order -----------------
$idsRaw = trim((string)($_GET['ids'] ?? $_POST['ids'] ?? ''));
if ($idsRaw !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($i) => $i > 0));
    if (!$ids) { echo json_encode(['results' => []]); exit; }
    $ids = array_slice($ids, 0, 50);
    $in = implode(',', $ids);
    $sql = "SELECT a.id, a.title, a.section, a.image_url, a.submission_date
            FROM articles a WHERE a.id IN ($in) AND $common" . ($pubScope ? " AND $pubScope" : "");
    $res = $conn->query($sql);
    $byId = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $byId[(int)$r['id']] = $r; } }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $r = $byId[$id];
            $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'section' => $r['section'] ?? '',
                      'image' => cp_img_url((string)$r['image_url']), 'date' => $r['submission_date'] ?? ''];
        }
    }
    $conn->close();
    echo json_encode(['results' => $out]);
    exit;
}

// ---- browse mode --------------------------------------------------------------
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$offset  = ($page - 1) * $limit;
$section = trim((string)($_GET['section'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));
$exclude = (int)($_GET['exclude'] ?? 0);

$where = $common;
$types = '';
$params = [];
if ($pubScope)        { $where .= " AND $pubScope"; }
if ($section !== '')  { $where .= " AND a.section = ?";          $types .= 's'; $params[] = $section; }
if ($q !== '')        { $where .= " AND a.title LIKE ?";         $types .= 's'; $params[] = '%' . $q . '%'; }
if ($exclude > 0)     { $where .= " AND a.id <> ?";              $types .= 'i'; $params[] = $exclude; }

// total
$countSql = "SELECT COUNT(*) AS t FROM articles a WHERE $where";
$cs = $conn->prepare($countSql);
if ($types !== '') { $cs->bind_param($types, ...$params); }
$cs->execute();
$total = (int)($cs->get_result()->fetch_assoc()['t'] ?? 0);
$cs->close();

// page
$sql = "SELECT a.id, a.title, a.section, a.image_url, a.submission_date
        FROM articles a WHERE $where ORDER BY a.modified_date DESC LIMIT ?, ?";
$ps = $conn->prepare($sql);
$ptypes = $types . 'ii';
$pparams = array_merge($params, [$offset, $limit]);
$ps->bind_param($ptypes, ...$pparams);
$ps->execute();
$r = $ps->get_result();
$results = [];
while ($row = $r->fetch_assoc()) {
    $results[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'section' => $row['section'] ?? '',
                  'image' => cp_img_url((string)$row['image_url']), 'date' => $row['submission_date'] ?? ''];
}
$ps->close();
$conn->close();

echo json_encode(['results' => $results, 'total' => $total, 'page' => $page, 'limit' => $limit]);
