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
// Breaking-news articles live in the parallel admin_ten.articles_breaking_news
// table (the scraper routes Breaking News there so the site renders them in its
// Breaking News section). They share the articles column shape, so the list, the
// count and every editor action UNION both tables and tag each row with a `src`
// ('main' | 'breaking') that the front-end passes back to the edit/save/delete
// endpoints. Without this, breaking-news drafts are invisible and uneditable.
// -----------------------------------------------------------------------------

// Collect the WHERE bind values ONCE as plain arrays; the UNION binds the same
// $where placeholders for BOTH branches, so every value is supplied twice.
$whereTypes = '';
$whereVals  = array();

// State placeholder is first in $where, so collect it first.
if ($hasStateFilter) {
    $whereTypes .= 's';
    $whereVals[] = $filterState;
}
if ($search !== "") {
    $likeSearch = '%' . $search . '%';
    $whereTypes .= 'ss';
    $whereVals[] = $likeSearch;
    $whereVals[] = $likeSearch;
}
if ($filterUser > 0) {
    $whereTypes .= 'i';
    $whereVals[] = $filterUser;
}
if ($filterSection !== "" && $position !== 'Section Editor') {
    $whereTypes .= 's';
    $whereVals[] = $filterSection;
}
if ($filterPublication !== "") {
    $likePublication = '%' . $filterPublication . '%';
    $whereTypes .= 's';
    $whereVals[] = $likePublication;
}
if ($filterDateFrom !== "") {
    // Convert date format from YYYY-MM-DD (HTML5 date input) to database format
    $dateFrom = date('Y-m-d', strtotime($filterDateFrom));
    $whereTypes .= 's';
    $whereVals[] = $dateFrom;
}
if ($filterDateTo !== "") {
    $dateTo = date('Y-m-d 23:59:59', strtotime($filterDateTo));
    $whereTypes .= 's';
    $whereVals[] = $dateTo;
}

// mysqli bind_param needs references; turn a flat value array into refs to its slots.
$mkRefs = function (array &$arr) {
    $refs = array();
    foreach ($arr as $k => $v) { $refs[$k] = &$arr[$k]; }
    return $refs;
};

// Only fold in the breaking-news table when it actually exists — this list is
// used by every editor, so a missing table must degrade to the normal
// articles-only view rather than hard-error the whole page. Checked once.
$includeBreaking = false;
$chk = $conn->query("SHOW TABLES LIKE 'articles_breaking_news'");
if ($chk) {
    $includeBreaking = ($chk->num_rows > 0);
    $chk->free();
}

// -----------------------------------------------------------------------------
// Count Query (articles, plus the breaking table when present)
// -----------------------------------------------------------------------------
$countInner = "(SELECT a.id FROM articles a WHERE $where)";
if ($includeBreaking) {
    $countInner .= " UNION ALL (SELECT a.id FROM articles_breaking_news a WHERE $where)";
}
$countSql = "SELECT COUNT(*) AS total FROM ( $countInner ) t";
$stmt = $conn->prepare($countSql);
if (!$stmt) {
    error_log("get_articles.php count prepare failed: " . $conn->error);
    echo json_encode(['error' => 'db_error', 'message' => 'Count query failed: ' . $conn->error]);
    exit;
}

// Bind the WHERE values once per branch.
$countVals  = $includeBreaking ? array_merge($whereVals, $whereVals) : $whereVals;
$countTypes = $includeBreaking ? $whereTypes . $whereTypes : $whereTypes;
if ($countTypes !== '') {
    $args = array_merge(array($countTypes), $mkRefs($countVals));
    call_user_func_array(array($stmt, 'bind_param'), $args);
}

$stmt->execute();
$countRes = $stmt->get_result();
$totalRow = $countRes->fetch_assoc();
$total = (int) $totalRow['total'];
$stmt->close();

// -----------------------------------------------------------------------------
// Main Query (both tables)
// -----------------------------------------------------------------------------
// An explicit shared column list (NOT a.*) keeps the two SELECTs union-compatible
// even though the main articles table has extra columns the breaking table lacks.
// publish_from exists on articles but not necessarily on breaking, so the breaking
// branch supplies NULL for it. The LEFT JOIN pulls the author's display name from
// ten_users (cross-DB) so we can show it and ORDER BY it across pages.
$cols = "a.id, a.title, a.journalist_id, a.publications, a.canonical, a.url, "
      . "a.section, a.state, a.submission_date, a.modified_date, a.publish_now, "
      . "a.imageless, a.news_scrape_url_hash";

// Outer ORDER BY runs on the flattened union, so it uses unqualified column names.
$outerSortMap = array(
    'id'              => 'id',
    'title'           => 'title',
    'journalist_name' => 'author_full_name',
    'publications'    => 'publications',
    'section'         => 'section',
    'state'           => 'state',
    'modified_date'   => 'modified_date',
    'submission_date' => 'submission_date',
);
$outerSort = isset($outerSortMap[$sortCol]) ? $outerSortMap[$sortCol] : 'modified_date';

$mainInner = "(SELECT $cols, a.publish_from AS publish_from, 'main' AS src,
            u.full_name AS author_full_name, u.username AS author_username
     FROM articles a
     LEFT JOIN TEN_Management.ten_users u ON u.id = a.journalist_id
     WHERE $where)";
if ($includeBreaking) {
    $mainInner .= "
    UNION ALL
    (SELECT $cols, NULL AS publish_from, 'breaking' AS src,
            u.full_name AS author_full_name, u.username AS author_username
     FROM articles_breaking_news a
     LEFT JOIN TEN_Management.ten_users u ON u.id = a.journalist_id
     WHERE $where)";
}
$query = "SELECT * FROM ( $mainInner ) t
ORDER BY $outerSort $sortDir
LIMIT ?, ?";

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("get_articles.php prepare failed: " . $conn->error);
    echo json_encode(['error' => 'db_error', 'message' => 'Query failed: ' . $conn->error]);
    exit;
}

// Bind the WHERE values once per branch, then the LIMIT offset/limit.
$mainVals  = $includeBreaking
    ? array_merge($whereVals, $whereVals, array($offset, $limit))
    : array_merge($whereVals, array($offset, $limit));
$mainTypes = ($includeBreaking ? $whereTypes . $whereTypes : $whereTypes) . 'ii';
$args2 = array_merge(array($mainTypes), $mkRefs($mainVals));
call_user_func_array(array($stmt, 'bind_param'), $args2);

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
        'src'               => $row['src'] ?? 'main',   // 'main' | 'breaking' — routes editor actions
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
