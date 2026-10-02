<?php
require_once '../config_ten_admin.php';
requireLogin();

// Get user session data
$position = $_SESSION['ten_position'] ?? '';
$section = $_SESSION['ten_section'] ?? '';
$publication = $_SESSION['ten_publication'] ?? '';
$userId = $_SESSION['ten_user_id'] ?? 0;

// Check basic permission using role-based logic
$allowedRoles = ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager', 'Editor-in-Chief', 'Edition Editor-in-Chief', 'Managing Editor', 'General Editor', 'Editor', 'Section Editor', 'Journalist'];
if (!isAdmin() && !in_array($position, $allowedRoles)) {
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

header('Content-Type: application/json');

// INPUT
$page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortCol    = isset($_GET['sort_col']) ? $_GET['sort_col'] : 'modified_date';
$sortDir    = (isset($_GET['sort_dir']) && strtolower($_GET['sort_dir']) === 'asc') ? 'ASC' : 'DESC';
$hide_imageless = isset($_GET['hide_imageless']) ? intval($_GET['hide_imageless']) : 0;
$scrapedOnly = isset($_GET['scraped']) ? intval($_GET['scraped']) : 0;

// Filter parameters
$filterUser = isset($_GET['user']) ? intval($_GET['user']) : 0;
$filterSection = isset($_GET['section']) ? trim($_GET['section']) : '';
$filterPublication = isset($_GET['publication']) ? trim($_GET['publication']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filterState = isset($_GET['state']) ? trim($_GET['state']) : '';
$allowedStates = array('published', 'draft', 'under review', 'expired', 'deleted');
$hasStateFilter = ($filterState !== '' && in_array($filterState, $allowedStates, true));

// Map the client sort key to a safe, qualified ORDER BY expression. Every
// displayed column is sortable; Author sorts by the joined ten_users.full_name,
// everything else is an articles column. Unknown keys fall back to last-modified.
$sortMap = array(
    'id'              => 'a.id',
    'title'           => 'a.title',
    'journalist_name' => 'u.full_name',
    'publications'    => 'a.publications',
    'section'         => 'a.section',
    'state'           => 'a.state',
    'modified_date'   => 'a.modified_date',
    'submission_date' => 'a.submission_date',
);
$sortExpr = isset($sortMap[$sortCol]) ? $sortMap[$sortCol] : $sortMap['modified_date'];

$offset = ($page - 1) * $limit;

$conn = getDBConnection_TENAdmin();

// -----------------------------------------------------------------------------
// Load publication URLs
// -----------------------------------------------------------------------------
$pubMap = array();
$sql = "SELECT publication, url FROM publications WHERE pub_live = '1'";
$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    $pubMap[$row['publication']] = $row['url'];
}

// -----------------------------------------------------------------------------
// WHERE clause
// -----------------------------------------------------------------------------
// When a specific state is chosen, filter to it (this also allows viewing 'deleted');
// otherwise keep the default of hiding deleted articles. The '?' is the FIRST bound param.
$where = $hasStateFilter ? "a.state = ?" : "a.state != 'deleted'";

// Add role-based filtering BEFORE user filters.
// Editorial roles are scoped to their assigned publication(s); Section Editors
// are further scoped to their section(s) and never see drafts; Journalists see
// only their own articles. Admin/Super/Manager see everything.
$userPubs = array_values(array_filter(array_map('trim', explode(',', (string)$publication)), fn($x) => $x !== ''));
$userSections = array_values(array_filter(array_map('trim', explode(',', (string)$section)), fn($x) => $x !== ''));

// SQL fragment: article belongs to at least one of the user's publications.
$pubScope = '';
if ($userPubs) {
    $pubScope = '(' . implode(' OR ', array_map(fn($p) => "a.publications LIKE '%" . $conn->real_escape_string($p) . "%'", $userPubs)) . ')';
}
// SQL fragment: article's section is one of the user's sections.
$sectionScope = '';
if ($userSections) {
    $sectionScope = 'a.section IN (' . implode(',', array_map(fn($s) => "'" . $conn->real_escape_string($s) . "'", $userSections)) . ')';
}

$seeAllRoles = ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager'];
$pubScopedEditorRoles = ['Editor', 'Managing Editor', 'General Editor', 'Editor-in-Chief', 'Edition Editor-in-Chief'];

if (isAdmin() || in_array($position, $seeAllRoles, true)) {
    // No restriction.
} elseif ($position === 'Journalist') {
    // Own articles only.
    $where .= " AND a.journalist_id = " . intval($userId);
} elseif ($position === 'Section Editor') {
    // Their section(s) within their publication(s), non-draft only.
    if ($pubScope)     { $where .= " AND $pubScope"; } else { $where .= " AND 0"; }
    if ($sectionScope) { $where .= " AND $sectionScope"; }
    $where .= " AND a.state <> 'draft'";
} elseif (in_array($position, $pubScopedEditorRoles, true)) {
    // All sections within their publication(s).
    if ($pubScope) { $where .= " AND $pubScope"; } else { $where .= " AND a.journalist_id = " . intval($userId); }
} else {
    // Unknown/non-editorial position with no explicit access: own articles only.
    $where .= " AND a.journalist_id = " . intval($userId);
}

$paramTypes = "";
$paramValues = array();

if ($search !== "") {
    $where .= " AND (a.title LIKE ? OR a.id LIKE ?)";
}

if ($hide_imageless == 1) {
    $where .= " AND (a.imageless IS NULL OR a.imageless != 1)";
}

// Scraped-only: articles the scraper wrote carry a source-URL hash (independent of imageless).
if ($scrapedOnly == 1) {
    $where .= " AND a.news_scrape_url_hash IS NOT NULL AND a.news_scrape_url_hash <> ''";
}

// User/Author filter
if ($filterUser > 0) {
    $where .= " AND a.journalist_id = ?";
}

// Section filter - only allow for non-Section Editors
if ($filterSection !== "" && $position !== 'Section Editor') {
    $where .= " AND a.section = ?";
}

// Publication filter
if ($filterPublication !== "") {
    $where .= " AND a.publications LIKE ?";
}

// Date range filters
if ($filterDateFrom !== "") {
    $where .= " AND a.submission_date >= ?";
}

if ($filterDateTo !== "") {
    $where .= " AND a.submission_date <= ?";
}

// -----------------------------------------------------------------------------
// Count Query
// -----------------------------------------------------------------------------
$countSql = "SELECT COUNT(*) AS total FROM articles a WHERE $where";
$stmt = $conn->prepare($countSql);
if (!$stmt) {
    error_log("get_articles.php count prepare failed: " . $conn->error);
    echo json_encode(['error' => 'db_error', 'message' => 'Count query failed: ' . $conn->error]);
    exit;
}

// Build parameter binding dynamically
$bindParams = array();
$bindTypes = '';

// State placeholder is first in $where, so bind it first.
if ($hasStateFilter) {
    $bindParams[] = &$filterState;
    $bindTypes .= 's';
}

if ($search !== "") {
    $likeSearch = '%' . $search . '%';
    $bindParams[] = &$likeSearch;
    $bindParams[] = &$likeSearch;
    $bindTypes .= 'ss';
}

if ($filterUser > 0) {
    $bindParams[] = &$filterUser;
    $bindTypes .= 'i';
}

if ($filterSection !== "" && $position !== 'Section Editor') {
    $bindParams[] = &$filterSection;
    $bindTypes .= 's';
}

if ($filterPublication !== "") {
    $likePublication = '%' . $filterPublication . '%';
    $bindParams[] = &$likePublication;
    $bindTypes .= 's';
}

if ($filterDateFrom !== "") {
    // Convert date format from YYYY-MM-DD (HTML5 date input) to database format
    $dateFrom = date('Y-m-d', strtotime($filterDateFrom));
    $bindParams[] = &$dateFrom;
    $bindTypes .= 's';
}

if ($filterDateTo !== "") {
    $dateTo = date('Y-m-d 23:59:59', strtotime($filterDateTo));
    $bindParams[] = &$dateTo;
    $bindTypes .= 's';
}

if ($bindTypes !== '') {
    array_unshift($bindParams, $bindTypes);
    call_user_func_array(array($stmt, 'bind_param'), $bindParams);
}

$stmt->execute();
$countRes = $stmt->get_result();
$totalRow = $countRes->fetch_assoc();
$total = (int) $totalRow['total'];
$stmt->close();

// -----------------------------------------------------------------------------
// Main Query
// -----------------------------------------------------------------------------
// SELECT a.* keeps every articles column (incl. canonical/url); the LEFT JOIN
// pulls the author's display name from ten_users (cross-DB; admin_smyth1w has
// SELECT on TEN_Management) so we can both show it and ORDER BY it across pages.
$query = "SELECT a.*, u.full_name AS author_full_name, u.username AS author_username
          FROM articles a
          LEFT JOIN TEN_Management.ten_users u ON u.id = a.journalist_id
          WHERE $where
          ORDER BY $sortExpr $sortDir
          LIMIT ?, ?";

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("get_articles.php prepare failed: " . $conn->error);
    echo json_encode(['error' => 'db_error', 'message' => 'Query failed: ' . $conn->error]);
    exit;
}

// Build parameter binding dynamically (same as count query)
$bindParams2 = array();
$bindTypes2 = '';

// State placeholder is first in $where, so bind it first.
if ($hasStateFilter) {
    $bindParams2[] = &$filterState;
    $bindTypes2 .= 's';
}

if ($search !== "") {
    $likeSearch = '%' . $search . '%';
    $bindParams2[] = &$likeSearch;
    $bindParams2[] = &$likeSearch;
    $bindTypes2 .= 'ss';
}

if ($filterUser > 0) {
    $bindParams2[] = &$filterUser;
    $bindTypes2 .= 'i';
}

if ($filterSection !== "" && $position !== 'Section Editor') {
    $bindParams2[] = &$filterSection;
    $bindTypes2 .= 's';
}

if ($filterPublication !== "") {
    $likePublication = '%' . $filterPublication . '%';
    $bindParams2[] = &$likePublication;
    $bindTypes2 .= 's';
}

if ($filterDateFrom !== "") {
    $dateFrom = date('Y-m-d', strtotime($filterDateFrom));
    $bindParams2[] = &$dateFrom;
    $bindTypes2 .= 's';
}

if ($filterDateTo !== "") {
    $dateTo = date('Y-m-d 23:59:59', strtotime($filterDateTo));
    $bindParams2[] = &$dateTo;
    $bindTypes2 .= 's';
}

// Add LIMIT parameters
$bindParams2[] = &$offset;
$bindParams2[] = &$limit;
$bindTypes2 .= 'ii';

array_unshift($bindParams2, $bindTypes2);
call_user_func_array(array($stmt, 'bind_param'), $bindParams2);

$stmt->execute();
$result = $stmt->get_result();

$articles = array();

// -----------------------------------------------------------------------------
// Process Rows
// -----------------------------------------------------------------------------
while ($row = $result->fetch_assoc()) {

    // Author display name comes from the LEFT JOIN on ten_users (no per-row query).
    $journalistName = '';
    if (!empty($row['author_full_name'])) {
        $journalistName = $row['author_full_name'] . ' (' . $row['author_username'] . ')';
    }

    // Publications as links
    $pubLinks = array();
    $pubParts = explode(',', $row['publications']);
    $canonicalPub  = trim($row['canonical'] ?? '');
    $articleUrl    = trim($row['url'] ?? '');   // articles.url is the article's path slug

    foreach ($pubParts as $p) {
        $p = trim($p);
        if (isset($pubMap[$p])) {
            if ($row['state'] == 'published' && !empty($articleUrl)) {
                $url = $pubMap[$p] . '/' . $articleUrl;
            } else {
                $url = $pubMap[$p] . '/preview:' . $row['id'];
            }

            $pubLinks[] = array(
                'name' => $p,
                'url' => $url,
                'canonical' => ($p === $canonicalPub)
            );
        }
    }

    // Build preview and live URLs from canonical publication
    // Prefer canonical pub; fall back to first listed publication
    $previewUrl = '';
    $liveUrl    = '';
    $basePub = (!empty($canonicalPub) && isset($pubMap[$canonicalPub]))
        ? $canonicalPub
        : ((!empty($pubParts[0]) && isset($pubMap[trim($pubParts[0])])) ? trim($pubParts[0]) : '');

    if (!empty($basePub)) {
        $baseUrl    = $pubMap[$basePub];
        $previewUrl = $baseUrl . '/preview:' . $row['id'];
        // Live URL: canonical base + article url slug (only for published articles)
        if ($row['state'] === 'published' && !empty($articleUrl)) {
            $liveUrl = $baseUrl . '/' . $articleUrl;
        }
    }

    // Editing allowed based on role. Journalists only ever receive their OWN
    // rows (WHERE journalist_id = them, above) and may edit only their DRAFTS —
    // once submitted or published they can view but not edit. Everyone else keeps
    // their existing scope. save_article.php re-checks these rules on save.
    $canEdit = isAdmin() || in_array($position, ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager', 'Editor-in-Chief', 'Managing Editor', 'General Editor', 'Edition Editor-in-Chief', 'Editor', 'Section Editor', 'Journalist']);
    if ($position === 'Journalist' && $row['state'] !== 'draft') {
        $canEdit = false;
    }
    $editable = $canEdit ? 1 : 0;

    // Add row
    $articles[] = array(
        'id'                => $row['id'],
        'title'             => $row['title'],
        'journalist_id'     => $row['journalist_id'],
        'journalist_name'   => $journalistName,
        'publications'      => $row['publications'],
        'publication_links' => $pubLinks,
        'canonical'         => $canonicalPub,
        'section'           => $row['section'] ?? '',
        'state'             => $row['state'],
        'submission_date'   => $row['submission_date'],
        'modified_date'     => $row['modified_date'] ?? $row['submission_date'],
        'publish_now'       => $row['publish_now'] ?? 1,
        'publish_from'      => $row['publish_from'] ?? null,
        'editable'          => $editable,
        'preview_url'       => $previewUrl,
        'live_url'          => $liveUrl
    );
}

$stmt->close();
$conn->close();

// OUTPUT JSON
echo json_encode(array(
    'page'      => $page,
    'limit'     => $limit,
    'total'     => $total,
    'results'   => $articles
));
