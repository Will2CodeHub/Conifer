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
    
    // Define role hierarchy for article access
    $highLevelRoles = [
        'Admin',
        'Super Admin',
        'Editor-in-Chief',
        'Managing Editor',
        'General Editor',
        'Edition Editor-in-Chief',
        // Actual DB role names from ten_roles table:
        'Administrator',
        'Manager',
        'Editor',
        'Super User'
    ];
    
    $sectionEditorRoles = ['Section Editor'];
    
    // Check permissions based on role
    $canViewAll = $isUserAdmin || in_array($position, $highLevelRoles);
    $canViewSection = in_array($position, $sectionEditorRoles);
    $canViewOwn = ($position === 'Journalist');
    
    // Allow dropdown data fetching for all logged-in users
    if ($dropdownDataOnly) {
        // Allow all logged-in users to fetch dropdown data
        $canViewAll = true;
    } elseif (!$canViewAll && !$canViewSection && !$canViewOwn) {
        throw new Exception('Unauthorized to view this article.');
    }
    
    // --- 1. Fetch Article Data (MySQLi) ---
    $row = null;
    $results_count = 0;
    
    // Skip article fetch if we only need dropdown data
    if (!$dropdownDataOnly) {
        if ($canViewAll || $isUserAdmin) {
            // Admin and high-level editors can view all articles
            $query = "SELECT * FROM articles WHERE id = ?";
            
            $stmt = $connArticles->prepare($query);
            if (!$stmt) throw new Exception("Prepare failed (articles - all): " . $connArticles->error);
            
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result(); 
            
            $results_count = $result->num_rows;
            if ($results_count == 1) {
                $row = $result->fetch_assoc();
            }
            $result->free();
            $stmt->close();
            
        } elseif ($canViewSection) {
            // Section Editors can only view articles in their section
            $query = "SELECT * FROM articles WHERE id = ? AND section = ?";
            
            $stmt = $connArticles->prepare($query);
            if (!$stmt) throw new Exception("Prepare failed (articles - section): " . $connArticles->error);
            
            $stmt->bind_param('is', $id, $section);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $results_count = $result->num_rows;
            if ($results_count == 1) {
                $row = $result->fetch_assoc();
            }
            $result->free();
            $stmt->close();
            
        } else {
            // Journalists can only view their own articles
            $query = "SELECT * FROM articles WHERE id = ? AND journalist_id = ?";
            
            $stmt = $connArticles->prepare($query);
            if (!$stmt) throw new Exception("Prepare failed (articles - own): " . $connArticles->error);
            
            $stmt->bind_param('ii', $id, $journalist_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $results_count = $result->num_rows;
            if ($results_count == 1) {
                $row = $result->fetch_assoc();
            }
            $result->free();
            $stmt->close();
        }
        
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
    
    // Section Editors and Journalists only see their assigned publication
    if (($position === 'Section Editor' || $position === 'Journalist') && !empty($publication)) {
        // Query to get the publication details for their assigned publication
        $query = "SELECT * FROM publications WHERE pub_live = '1' AND publication = ? ORDER BY publication ASC";
        
        $stmt = $connArticles->prepare($query);
        if (!$stmt) throw new Exception("Prepare failed (publications): " . $connArticles->error);
        
        $stmt->bind_param('s', $publication);
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
        // High-level roles see all publications
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
    
    // Section Editors — and Journalists who have been ASSIGNED a section — only
    // see their own section. Collapsing the list to one option makes the Add
    // Article form auto-select it (module-articles.php), so an assigned
    // journalist never has to pick a section. A journalist with no assigned
    // section falls through to the "all sections" branch and chooses.
    if (($position === 'Section Editor' || $position === 'Journalist') && !empty($section)) {
        // Only show their assigned section
        $all_sections[] = [
            'value' => $section,
            'label' => $section
        ];
    } elseif ($canViewAll || $position === 'Journalist') {
        // High-level roles and unassigned Journalists see all sections
        $query = "SELECT DISTINCT name, parent_item FROM main_menu WHERE section_item = '1' AND id != 79 ORDER BY name ASC";
        
        $stmt = $connArticles->prepare($query);
        if (!$stmt) throw new Exception("Prepare failed (main_menu): " . $connArticles->error);
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($menuRow = $result->fetch_assoc()) {
            if ($menuRow['parent_item'] == 0) {
                $all_sections[] = [
                    'value' => $menuRow['name'],
                    'label' => $menuRow['name']
                ];
            } else {
                $all_subcategories[] = [
                    'value' => $menuRow['name'],
                    'label' => $menuRow['name']
                ];
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
    
    if ($position === 'Section Editor' && !empty($section) && !empty($publication)) {
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
        'all_subcategories' => $all_subcategories,
        'can_publish' => $isUserAdmin || in_array($position, ['Admin', 'Super Admin', 'Editor-in-Chief', 'Managing Editor', 'Section Editor', 'Administrator', 'Manager', 'Editor'])
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error loading article: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
    if ($connArticles) {
        $connArticles->close();
    }
}
