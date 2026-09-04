<?php
require_once '../config_ten_admin.php';
requireLogin();

// Get user session data
$position = $_SESSION['ten_position'] ?? '';
$section = $_SESSION['ten_section'] ?? '';
$publication = $_SESSION['ten_publication'] ?? '';
$userId = $_SESSION['ten_user_id'] ?? 0;

// Check basic permission using role-based logic
$allowedRoles = ['Admin', 'Super Admin', 'Editor-in-Chief', 'Managing Editor', 'General Editor', 'Edition Editor-in-Chief', 'Section Editor', 'Journalist'];
if (!isAdmin() && !in_array($position, $allowedRoles)) {
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

header('Content-Type: application/json');

// INPUT
$page       = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortCol    = isset($_GET['sort_col']) ? $_GET['sort_col'] : 'submission_date';
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

$allowedSort = array('id', 'title', 'state', 'submission_date');
if (!in_array($sortCol, $allowedSort)) {
    $sortCol = 'submission_date';
}

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
$where = $hasStateFilter ? "state = ?" : "state != 'deleted'";

// Add role-based filtering BEFORE user filters
if ($position === 'Section Editor' && !empty($section) && !empty($publication)) {
    // Section Editors can only see articles in their section AND their assigned publication(s)
    $where .= " AND section = '" . $conn->real_escape_string($section) . "'";
    $pubs = array_filter(array_map('trim', explode(',', $publication)));
    if (count($pubs) > 1) {
        $pubClauses = array_map(fn($p) => "publications LIKE '%" . $conn->real_escape_string($p) . "%'", $pubs);
        $where .= " AND (" . implode(" OR ", $pubClauses) . ")";
    } else {
        $where .= " AND publications LIKE '%" . $conn->real_escape_string($publication) . "%'";
    }
} elseif ($position === 'Journalist') {
    // Journalists can only see their own articles
    $where .= " AND journalist_id = " . intval($userId);
}
// Admins, Editor-in-Chief, Managing Editor, General Editor see all articles

$paramTypes = "";
$paramValues = array();

if ($search !== "") {
    $where .= " AND (title LIKE ? OR id LIKE ?)";
}

if ($hide_imageless == 1) {
    $where .= " AND (imageless IS NULL OR imageless != 1)";
}

// Scraped-only: articles the scraper wrote carry a source-URL hash (independent of imageless).
if ($scrapedOnly == 1) {
    $where .= " AND news_scrape_url_hash IS NOT NULL AND news_scrape_url_hash <> ''";
}

// User/Author filter
if ($filterUser > 0) {
    $where .= " AND journalist_id = ?";
}

// Section filter - only allow for non-Section Editors
if ($filterSection !== "" && $position !== 'Section Editor') {
    $where .= " AND section = ?";
}

// Publication filter
if ($filterPublication !== "") {
    $where .= " AND publications LIKE ?";
}

// Date range filters
if ($filterDateFrom !== "") {
    $where .= " AND submission_date >= ?";
}

if ($filterDateTo !== "") {
    $where .= " AND submission_date <= ?";
}

// -----------------------------------------------------------------------------
// Count Query
// -----------------------------------------------------------------------------
$countSql = "SELECT COUNT(*) AS total FROM articles WHERE $where";
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
// Use SELECT * so query doesn't break if canonical/article_alias columns don't exist
$query = "SELECT *
          FROM articles
          WHERE $where
          ORDER BY $sortCol $sortDir
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

// Get connection to TEN_Management database for user lookups
$connManagement = getDBConnection();

// -----------------------------------------------------------------------------
// Process Rows
// -----------------------------------------------------------------------------
while ($row = $result->fetch_assoc()) {

    // Journalist lookup from ten_users table in TEN_Management database
    $journalistName = '';
    $sql2 = "SELECT full_name, username FROM ten_users WHERE id = ?";
    $stmt2 = $connManagement->prepare($sql2);
    $stmt2->bind_param('i', $row['journalist_id']);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    if ($userRow = $res2->fetch_assoc()) {
        $journalistName = $userRow['full_name'] . ' (' . $userRow['username'] . ')';
    }
    $stmt2->close();

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
                'url' => $url
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

    // Editing allowed based on role
    $canEdit = isAdmin() || in_array($position, ['Admin', 'Super Admin', 'Editor-in-Chief', 'Managing Editor', 'General Editor', 'Edition Editor-in-Chief', 'Section Editor']);
    $editable = $canEdit ? 1 : 0;

    // Add row
    $articles[] = array(
        'id'                => $row['id'],
        'title'             => $row['title'],
        'journalist_id'     => $row['journalist_id'],
        'journalist_name'   => $journalistName,
        'publications'      => $row['publications'],
        'publication_links' => $pubLinks,
        'state'             => $row['state'],
        'submission_date'   => $row['submission_date'],
        'editable'          => $editable,
        'preview_url'       => $previewUrl,
        'live_url'          => $liveUrl
    );
}

$stmt->close();
$connManagement->close();
$conn->close();

// OUTPUT JSON
echo json_encode(array(
    'page'      => $page,
    'limit'     => $limit,
    'total'     => $total,
    'results'   => $articles
));
