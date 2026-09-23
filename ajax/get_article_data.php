<?php
session_start();
require_once '../config.php';
require_once '../config_ten_admin.php';

// Check login
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

// Get article ID
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

// Special case: id=0 means we only want dropdown data for filters, not article data
$dropdownDataOnly = ($id === 0);

if ($id < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid article ID']);
    exit;
}

// Get session variables - use ten_ prefixed session variables
$publication = $_SESSION["ten_publication"] ?? '';
$section = $_SESSION["ten_section"] ?? '';
$position = $_SESSION["ten_position"] ?? '';
$fullname = $_SESSION["ten_full_name"] ?? '';
$alias = $_SESSION["alias"] ?? '';
$journalist_id = $_SESSION["ten_user_id"] ?? 0;

$userId = $_SESSION['ten_user_id'];
$isUserAdmin = isAdmin();

$connArticles = null;

try {
    // Connect to database (now serving as the primary connection for all tables)
    $connArticles = getDBConnection_TENAdmin();
    
    // Publication / section scope from the user's assignment (comma-separated).
    $userPubs = array_values(array_filter(array_map('trim', explode(',', (string)$publication)), fn($x) => $x !== ''));
    $userSections = array_values(array_filter(array_map('trim', explode(',', (string)$section)), fn($x) => $x !== ''));

    // Role tiers. See-all roles have no pub/section restriction; pub-scoped
    // editors see all sections within their publication(s); Section Editors are
    // further limited to their section(s); Journalists see only their own.
    $seeAllRoles   = ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager'];
    $pubScopedRoles = ['Editor', 'Managing Editor', 'General Editor', 'Editor-in-Chief', 'Edition Editor-in-Chief'];

    $canViewAll       = $isUserAdmin || in_array($position, $seeAllRoles, true);
    $canViewPubScoped = in_array($position, $pubScopedRoles, true);
    $canViewSection   = ($position === 'Section Editor');
    $canViewOwn       = ($position === 'Journalist');

    if (!$dropdownDataOnly && !$canViewAll && !$canViewPubScoped && !$canViewSection && !$canViewOwn) {
        throw new Exception('Unauthorized to view this article.');
    }

    // SQL scope fragments (escaped; values come from the session, not the client).
    $pubScopeSql = '';
    if ($userPubs) {
        $pubScopeSql = '(' . implode(' OR ', array_map(fn($p) => "publications LIKE '%" . $connArticles->real_escape_string($p) . "%'", $userPubs)) . ')';
    }
    $sectionScopeSql = '';
    if ($userSections) {
        $sectionScopeSql = 'section IN (' . implode(',', array_map(fn($s) => "'" . $connArticles->real_escape_string($s) . "'", $userSections)) . ')';
    }

    // --- 1. Fetch Article Data (MySQLi) ---
    $row = null;
    $results_count = 0;

    // Skip article fetch if we only need dropdown data
    if (!$dropdownDataOnly) {
        $scope = '';
        if ($canViewAll) {
            $scope = '';
        } elseif ($canViewPubScoped) {
            $scope = $pubScopeSql ? " AND $pubScopeSql" : " AND 0";
        } elseif ($canViewSection) {
            $scope = ($pubScopeSql ? " AND $pubScopeSql" : " AND 0") . ($sectionScopeSql ? " AND $sectionScopeSql" : "");
        } else { // Journalist
            $scope = " AND journalist_id = " . intval($journalist_id);
        }

        $query = "SELECT * FROM articles WHERE id = ?" . $scope;
        $stmt = $connArticles->prepare($query);
        if (!$stmt) throw new Exception("Prepare failed (articles): " . $connArticles->error);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $results_count = $result->num_rows;
        if ($results_count == 1) {
            $row = $result->fetch_assoc();
        }
        $result->free();
        $stmt->close();

        if ($results_count != 1) {
            throw new Exception('Article not found or unauthorized.');
        }
    }

    // Extract article data (use defaults if dropdown data only)
    if ($dropdownDataOnly) {
        // Default values when only fetching dropdown data
        $title = '';
        $article_text = '';
        $article_publications = '';
        $article_tags = '';
        $evergreen = 0;
        $sponsored = 0;
        $featured = 0;
        $headline = 0;
        $note = '';
        $section_val = '';
        $section_subcat = '';
        $author = 0;
        $publish_now = 1;
        $article_alias = '';
        $canonical_pub = '';
        $state = 'draft';
        $meta_title = '';
        $meta_description = '';
        $meta_keywords = '';
        $publish_from = '';
        $publish_to = '';
        $time_from = '';
        $time_to = '';
        $article_publications_array = [];
    } else {
        // Extract from database row
        $title = $row['title'];
        $article_text = $row['article_text'];
        $article_publications = $row['publications'];
        $article_tags = $row['article_tags'] ?? $row['tags'] ?? '';
        $evergreen = $row['evergreen'];
        $sponsored = $row['sponsored'];
        $featured = $row['featured'];
        $headline = $row['frontpage_temp'];
        $note = $row['note'];
        $section_val = $row['section'];
        $section_subcat = $row['section_subcat'];
        $author = $row['journalist_id'];
        $publish_now = $row['publish_now'];
        $article_alias = $row['article_alias'] ?? $row['alias'] ?? '';
        $canonical_pub = $row['canonical'];
        $state = $row['state'] ?? 'draft';
        $meta_title = $row['meta_title'] ?? '';
        $meta_description = $row['meta_description'] ?? '';
        $meta_keywords = $row['meta_keywords'] ?? '';
        
        // Handle dates
        $publish_from = $row['publish_from'];
        $publish_to = $row['publish_to'];
        $time_from = $row['time_from'] ?? '';
        $time_to = $row['time_to'] ?? '';
        
        // Parse publications into array
        $article_publications_array = array_map('trim', explode(',', $article_publications));
    }

    // --- 3. Get Publications (MySQLi) ---
    $all_publications = [];
    
    // Editorial roles (Journalist, Section Editor, Editor, Managing/General/
    // Editor-in-Chief) only see the publication(s) they've been assigned.
    // Admin/Super/Manager see all publications.
    $editorialPubScoped = $canViewOwn || $canViewSection || $canViewPubScoped;
    if ($editorialPubScoped && $userPubs) {
        $ph = implode(',', array_fill(0, count($userPubs), '?'));
        $query = "SELECT * FROM publications WHERE pub_live = '1' AND publication IN ($ph) ORDER BY publication ASC";
        $stmt = $connArticles->prepare($query);
        if (!$stmt) throw new Exception("Prepare failed (publications): " . $connArticles->error);
        $stmt->bind_param(str_repeat('s', count($userPubs)), ...$userPubs);
        $stmt->execute();
        $result = $stmt->get_result();
        while($pubRow = $result->fetch_assoc()) {
            $is_selected = in_array($pubRow['publication'], $article_publications_array, true) || ($pubRow['publication'] == 'ten');
            $is_canonical = ($pubRow['publication'] == $canonical_pub);
            $all_publications[] = [
                'name' => $pubRow['publication'],
                'title' => $pubRow['title'],
                'url' => $pubRow['url'],
                'selected' => $is_selected,
                'canonical' => $is_canonical
            ];
        }
        $result->free();
        $stmt->close();

    } elseif ($canViewAll) {
        // Admin/Super/Manager see all publications
        $query = "SELECT * FROM publications WHERE pub_live = '1' ORDER BY publication ASC";
        $result = $connArticles->query($query);
        if (!$result) throw new Exception("Query failed (publications): " . $connArticles->error);
        while($pubRow = $result->fetch_assoc()) {
            $is_selected = in_array($pubRow['publication'], $article_publications_array, true) || ($pubRow['publication'] == 'ten');
            $is_canonical = ($pubRow['publication'] == $canonical_pub);
            $all_publications[] = [
                'name' => $pubRow['publication'],
                'title' => $pubRow['title'],
                'url' => $pubRow['url'],
                'selected' => $is_selected,
                'canonical' => $is_canonical
            ];
        }
        $result->free();
    }

    // --- 4. Get Sections (MySQLi) ---
    $all_sections = [];
    $all_subcategories = [];
    // Per-publication section map: { edition: [ {value,label}, ... ] }. Sections
    // are the section_item rows of admin_ten.main_menu for each publication's
    // edition, so the section dropdown can follow the chosen publication(s).
    $sections_by_pub = [];

    // Build sections_by_pub for every publication the user can see (derived from
    // $all_publications above). This drives per-publication filtering in the UI.
    if (!empty($all_publications)) {
        $secStmt = $connArticles->prepare(
            "SELECT name FROM main_menu WHERE edition = ? AND section_item = '1' AND parent_item = 0 AND name <> '' ORDER BY position ASC, name ASC"
        );
        if ($secStmt) {
            foreach ($all_publications as $pubEntry) {
                $ed = $pubEntry['name'];
                if (isset($sections_by_pub[$ed])) continue;
                $secStmt->bind_param('s', $ed);
                $secStmt->execute();
                $secRes = $secStmt->get_result();
                $list = [];
                while ($sr = $secRes->fetch_assoc()) {
                    $list[] = ['value' => $sr['name'], 'label' => $sr['name']];
                }
                $secRes->free();
                $sections_by_pub[$ed] = $list;
            }
            $secStmt->close();
        }
    }

    // Journalists and Section Editors WITH assigned section(s) only see those
    // section(s) — collapsing to one makes the Add Article form auto-select it,
    // so an assigned user never has to pick. Everyone else (Editors and above,
    // and unassigned Journalists/Section Editors) sees all sections.
    if (($canViewOwn || $canViewSection) && $userSections) {
        foreach ($userSections as $s) {
            $all_sections[] = ['value' => $s, 'label' => $s];
        }
    } elseif (!empty($sections_by_pub)) {
        // Union (deduped by name) of the sections across the publications the
        // user can see — used as the default list and the filter dropdown.
        $seen = [];
        foreach ($sections_by_pub as $list) {
            foreach ($list as $s) {
                if (isset($seen[$s['value']])) continue;
                $seen[$s['value']] = true;
                $all_sections[] = $s;
            }
        }
        usort($all_sections, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        // sub-categories (parent_item != 0) across visible editions
        $editionsIn = implode(',', array_map(fn($p) => "'" . $connArticles->real_escape_string($p['name']) . "'", $all_publications));
        if ($editionsIn !== '') {
            $subRes = $connArticles->query("SELECT DISTINCT name FROM main_menu WHERE section_item = '1' AND parent_item <> 0 AND edition IN ($editionsIn) AND name <> '' ORDER BY name ASC");
            while ($subRes && $sr = $subRes->fetch_assoc()) {
                $all_subcategories[] = ['value' => $sr['name'], 'label' => $sr['name']];
            }
        }
    } else {
        // Fallback (no publications resolved): keep prior global behaviour.
        $query = "SELECT DISTINCT name, parent_item FROM main_menu WHERE section_item = '1' AND id != 79 ORDER BY name ASC";
        $stmt = $connArticles->prepare($query);
        if (!$stmt) throw new Exception("Prepare failed (main_menu): " . $connArticles->error);
        $stmt->execute();
        $result = $stmt->get_result();
        while($menuRow = $result->fetch_assoc()) {
            if ($menuRow['parent_item'] == 0) {
                $all_sections[] = ['value' => $menuRow['name'], 'label' => $menuRow['name']];
            } else {
                $all_subcategories[] = ['value' => $menuRow['name'], 'label' => $menuRow['name']];
            }
        }
        $result->free();
        $stmt->close();
    }
    
    // --- 5. Get Journalists from TEN_Management database (MySQLi) ---
    $all_journalists = [];
    
    // Create connection to TEN_Management database where ten_users is located
    $connManagement = getDBConnection();
    
    // Get users who can be assigned as article authors:
    // - Journalists in the same section (for Section Editors)
    // - All Journalists (for higher roles)
    // - Plus all management roles (Managing Editor, Editor-in-Chief, Admin, etc.)
    
    if ($position === 'Journalist') {
        // Journalists can only author as themselves — return just their own row so
        // the author field auto-fills with their name and offers no other choice.
        $meStmt = $connManagement->prepare("SELECT id, full_name, username FROM ten_users WHERE id = ?");
        $meStmt->bind_param('i', $userId);
        $meStmt->execute();
        $meRes = $meStmt->get_result();
        if ($meRow = $meRes->fetch_assoc()) {
            $all_journalists[] = [
                'id' => $meRow['id'],
                'name' => $meRow['full_name'] . ' (' . $meRow['username'] . ')'
            ];
        }
        $meStmt->close();

    } elseif ($position === 'Section Editor' && !empty($section) && !empty($publication)) {
        // Section Editors see: Section Editors in their section + Editors + Administrators
        $query = "SELECT u.id, u.full_name, u.username, r.role_name
                  FROM ten_users u
                  JOIN ten_user_roles ur ON u.id = ur.user_id
                  JOIN ten_roles r ON ur.role_id = r.id
                  WHERE u.status = 'active'
                  AND (
                    (r.role_name = 'Section Editor' AND u.section = ? AND u.publication = ?)
                    OR r.role_name IN ('Editor', 'Administrator')
                  )
                  ORDER BY u.full_name ASC";
        
        $stmt = $connManagement->prepare($query);
        $stmt->bind_param('ss', $section, $publication);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($userRow = $result->fetch_assoc()) {
            $all_journalists[] = [
                'id' => $userRow['id'],
                'name' => $userRow['full_name'] . ' (' . $userRow['username'] . ')'
            ];
        }
        
        $stmt->close();
        
    } else {
        // Higher roles see all Editors + Section Editors + Administrators
        $query = "SELECT u.id, u.full_name, u.username, r.role_name
                  FROM ten_users u
                  JOIN ten_user_roles ur ON u.id = ur.user_id
                  JOIN ten_roles r ON ur.role_id = r.id
                  WHERE u.status = 'active'
                  AND r.role_name IN ('Editor', 'Section Editor', 'Administrator')
                  ORDER BY u.full_name ASC";
        
        $result = $connManagement->query($query);
        
        while($userRow = $result->fetch_assoc()) {
            $all_journalists[] = [
                'id' => $userRow['id'],
                'name' => $userRow['full_name'] . ' (' . $userRow['username'] . ')'
            ];
        }
        
        $result->free();
    }

    // Ensure the article's CURRENT author is always in the dropdown so it shows as
    // the selected author when an editor opens someone else's (e.g. a journalist's)
    // article — the lists above may not otherwise include journalists.
    if (!$dropdownDataOnly && !empty($author)) {
        $authorId = (int)$author;
        $present = false;
        foreach ($all_journalists as $j) { if ((int)$j['id'] === $authorId) { $present = true; break; } }
        if (!$present) {
            $as = $connManagement->prepare("SELECT id, full_name, username FROM ten_users WHERE id = ?");
            $as->bind_param('i', $authorId);
            $as->execute();
            if ($ar = $as->get_result()->fetch_assoc()) {
                array_unshift($all_journalists, [
                    'id' => $ar['id'],
                    'name' => $ar['full_name'] . ' (' . $ar['username'] . ')'
                ]);
            }
            $as->close();
        }
    }

    // Close TEN_Management connection
    $connManagement->close();
    
    // Close admin_ten connection
    $connArticles->close();
    
    // Prepare response
    $response = [
        'status' => 'success',
        'id' => $id,
        'title' => $title,
        'article' => $article_text,
        'article_alias' => $article_alias,
        'article_tags' => $article_tags,
        'section' => $section_val,
        'section_subcat' => $section_subcat,
        'author_id' => $author,
        'publications' => $article_publications,
        'canonical' => $canonical_pub,
        'meta_title' => $meta_title,
        'meta_description' => $meta_description,
        'meta_keywords' => $meta_keywords,
        'evergreen' => $evergreen,
        'featured' => $featured,
        'sponsored' => $sponsored,
        'headline' => $headline,
        'note' => $note,
        'publish_now' => $publish_now,
        'publish_from' => $publish_from,
        'publish_to' => $publish_to,
        'time_from' => $time_from,
        'time_to' => $time_to,
        'state' => $state,
        'all_publications' => $all_publications,
        'all_journalists' => $all_journalists,
        'all_sections' => $all_sections,
        'sections_by_pub' => $sections_by_pub,
        'all_subcategories' => $all_subcategories,
        'can_publish' => $isUserAdmin || in_array($position, ['Admin', 'Super Admin', 'Super User', 'Editor-in-Chief', 'Edition Editor-in-Chief', 'Managing Editor', 'General Editor', 'Section Editor', 'Administrator', 'Manager', 'Editor'])
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error loading article: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    if ($connArticles) {
        $connArticles->close();
    }
}
