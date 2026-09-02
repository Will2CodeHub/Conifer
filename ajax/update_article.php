<?php
session_start();
require_once '../config.php';
require_once '../config_ten_admin.php';

header('Content-Type: application/json');

// Check login
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get POST data
$articleId = isset($_POST['id']) ? intval($_POST['id']) : 0;
$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$articleText = isset($_POST['article_text']) ? $_POST['article_text'] : '';
$alias = isset($_POST['alias']) ? trim($_POST['alias']) : '';
$tags = isset($_POST['tags']) ? trim($_POST['tags']) : '';
$section = isset($_POST['section']) ? trim($_POST['section']) : '';
$sectionSubcat = isset($_POST['section_subcat']) ? trim($_POST['section_subcat']) : '';
$author = isset($_POST['author']) ? intval($_POST['author']) : 0;
$publications = isset($_POST['publications']) ? trim($_POST['publications']) : '';
$canonical = isset($_POST['canonical']) ? trim($_POST['canonical']) : '';
$evergreen = isset($_POST['evergreen']) ? intval($_POST['evergreen']) : 0;
$featured = isset($_POST['featured']) ? intval($_POST['featured']) : 0;
$sponsored = isset($_POST['sponsored']) ? intval($_POST['sponsored']) : 0;
$headline = isset($_POST['frontpage_temp']) ? trim($_POST['frontpage_temp']) : '';
$note = isset($_POST['note']) ? trim($_POST['note']) : '';
$publishNow = isset($_POST['publish_now']) ? intval($_POST['publish_now']) : 0;
$publishFrom = isset($_POST['publish_from']) ? trim($_POST['publish_from']) : null;
$publishTo = isset($_POST['publish_to']) ? trim($_POST['publish_to']) : null;
$action = isset($_POST['action']) ? $_POST['action'] : 'save'; // 'save', 'submit', 'publish'

// Validation
if ($articleId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid article ID']);
    exit;
}

if (empty($title)) {
    echo json_encode(['status' => 'error', 'message' => 'Title is required']);
    exit;
}

if (empty($articleText)) {
    echo json_encode(['status' => 'error', 'message' => 'Article content is required']);
    exit;
}

if (empty($publications)) {
    echo json_encode(['status' => 'error', 'message' => 'At least one publication must be selected']);
    exit;
}

try {
    // Get user info
    $userId = $_SESSION['ten_user_id'] ?? $_SESSION['user_id'] ?? 0;
    $isUserAdmin = isAdmin();
    $canPublish = $isUserAdmin || hasPermission('articles.publish') || hasPermission('publish_articles');
    
    // Determine state based on action
    $state = 'draft';
    switch ($action) {
        case 'submit':
            $state = 'under review';
            break;
        case 'publish':
            if (!$canPublish) {
                echo json_encode(['status' => 'error', 'message' => 'You do not have permission to publish articles']);
                exit;
            }
            $state = 'published';
            break;
        case 'save':
        default:
            $state = 'draft';
            break;
    }
    
    // Use PDO Core for database access
    $pdoCore = Core::getInstance();
    
    // Check if user has permission to edit this article
    $checkStmt = $pdoCore->dbh->prepare("SELECT journalist_id, state FROM articles WHERE id = ?");
    $checkStmt->execute([$articleId]);
    $checkStmt->setFetchMode(PDO::FETCH_ASSOC);
    $existingArticle = $checkStmt->fetch();
    
    if (!$existingArticle) {
        echo json_encode(['status' => 'error', 'message' => 'Article not found']);
        exit;
    }
    
    // Permission check
    if (!$isUserAdmin && $existingArticle['journalist_id'] != $userId) {
        echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit this article']);
        exit;
    }
    
    // Convert date formats if needed (dd-mm-yyyy to yyyy-mm-dd)
    if ($publishFrom && !empty($publishFrom)) {
        $parts = explode('-', $publishFrom);
        if (count($parts) == 3 && strlen($parts[2]) == 4) {
            $publishFrom = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
    } else {
        $publishFrom = null;
    }
    
    if ($publishTo && !empty($publishTo)) {
        $parts = explode('-', $publishTo);
        if (count($parts) == 3 && strlen($parts[2]) == 4) {
            $publishTo = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
    } else {
        $publishTo = null;
    }
    
    // Update article
    $query = "UPDATE articles SET
        title = ?,
        article_text = ?,
        article_alias = ?,
        article_tags = ?,
        section = ?,
        section_subcat = ?,
        journalist_id = ?,
        publications = ?,
        canonical = ?,
        evergreen = ?,
        featured = ?,
        sponsored = ?,
        frontpage_temp = ?,
        note = ?,
        publish_now = ?,
        publish_from = ?,
        publish_to = ?,
        state = ?,
        last_modified = NOW()
        WHERE id = ?";
    
    $stmt = $pdoCore->dbh->prepare($query);
    $success = $stmt->execute([
        $title,
        $articleText,
        $alias,
        $tags,
        $section,
        $sectionSubcat,
        $author,
        $publications,
        $canonical,
        $evergreen,
        $featured,
        $sponsored,
        $headline,
        $note,
        $publishNow,
        $publishFrom,
        $publishTo,
        $state,
        $articleId
    ]);
    
    if ($success) {
        // Log activity
        if (function_exists('logActivity')) {
            logActivity('article_updated', 'article', $articleId, "Updated article: $title (state: $state)");
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Article saved successfully',
            'article_id' => $articleId,
            'state' => $state
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save article']);
    }
    
} catch (PDOException $pe) {
    error_log("Error saving article: " . $pe->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $pe->getMessage()]);
} catch (Exception $e) {
    error_log("Error saving article: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
