<?php
session_start();
require_once 'config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Get POST data
$title = $_POST['article_title'] ?? '';
$article_text = $_POST['article_text'] ?? '';

// Validate required fields
if (empty($title)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Article title is required'
    ]);
    exit;
}

if (empty($article_text)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Article content is required'
    ]);
    exit;
}

// Get user info from session
$user_id = $_SESSION['ten_user_id'] ?? 0;
$user_name = $_SESSION['ten_username'] ?? 'Unknown';
$full_name = $_SESSION['ten_full_name'] ?? $user_name;

// Allow overriding journalist_id (for admins assigning articles to others)
$assigned_journalist_id = isset($_POST['journalist_id']) ? intval($_POST['journalist_id']) : 0;
if ($assigned_journalist_id > 0) {
    $user_id = $assigned_journalist_id;
}

// Get database connection
$conn = getDBConnection();

// Get user details for pen name
$stmt = $conn->prepare("SELECT full_name, email FROM ten_users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// If journalist was reassigned, fetch their name
if ($assigned_journalist_id > 0) {
    $conn2 = getDBConnection();
    $stmt2 = $conn2->prepare("SELECT full_name FROM ten_users WHERE id = ?");
    $stmt2->bind_param("i", $assigned_journalist_id);
    $stmt2->execute();
    $assigned_user = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    $conn2->close();
    if ($assigned_user) {
        $user = $assigned_user;
    }
}

$conn = getDBConnection_TENAdmin();

$created_by = $user['full_name'] ?? $full_name;

// Clean and sanitize the title
$clean_title = strip_tags($title);
$clean_title = trim($clean_title);

// Clean article text - allow safe HTML tags
$allowed_tags = '<p><br><strong><b><em><i><u><a><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre><span><div>';
$clean_article_text = strip_tags($article_text, $allowed_tags);

// Additional security: remove any script tags or javascript
$clean_article_text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $clean_article_text);
$clean_article_text = preg_replace('/on\w+\s*=\s*["\'].*?["\']/i', '', $clean_article_text);

// Set default values
$state = 'draft';
$section = isset($_POST['add_article_section']) ? trim($_POST['add_article_section']) : 'news';
$publication = isset($_POST['add_article_publications']) ? trim($_POST['add_article_publications']) : 'tme';
if (empty($section)) $section = 'news';
if (empty($publication)) $publication = 'tme';

// Check if we need to use the old database structure (admin_ten)
// Try to detect which database structure we're using
$tables_query = "SHOW TABLES LIKE 'articles'";
$tables_result = $conn->query($tables_query);

if ($tables_result && $tables_result->num_rows > 0) {
    // Using old admin_ten database structure
    try {
        // Check if articles table exists and get its structure
        $column_check = $conn->query("SHOW COLUMNS FROM articles LIKE 'submission_date'");
        
        if ($column_check && $column_check->num_rows > 0) {
            // Old structure with submission_date
            $insert_query = "INSERT INTO articles 
                (title, article_text, state, section, created_by, journalist_id, publications, submission_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("sssssis", 
                $clean_title, 
                $clean_article_text, 
                $state, 
                $section, 
                $created_by, 
                $user_id, 
                $publication
            );
            
            if ($stmt->execute()) {
                $article_id = $stmt->insert_id;
                $stmt->close();
                
                // Log activity
                logActivity('article_created', 'article', $article_id, "Created draft article: " . $clean_title);
                
                echo json_encode([
                    'status' => 'success',
                    'article_id' => $article_id,
                    'message' => '<p>Thank you ' . htmlspecialchars($created_by) . ', your article was uploaded, and is now in <strong>DRAFT</strong> status.</p>' .
                                '<p>This means only you can see the article and it is <strong style="color:red;">NOT YET PUBLISHED</strong>.</p>' .
                                '<p>You can now submit another draft article or view all your articles in the "View All Articles" tab.</p>'
                ]);
            } else {
                throw new Exception("Failed to insert article: " . $stmt->error);
            }
        } else {
            // Newer structure with created_at
            $insert_query = "INSERT INTO articles 
                (title, article_text, state, section, created_by, journalist_id, publications, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("sssssis", 
                $clean_title, 
                $clean_article_text, 
                $state, 
                $section, 
                $created_by, 
                $user_id, 
                $publication
            );
            
            if ($stmt->execute()) {
                $article_id = $stmt->insert_id;
                $stmt->close();
                
                echo json_encode([
                    'status' => 'success',
                    'article_id' => $article_id,
                    'message' => '<p>Thank you ' . htmlspecialchars($created_by) . ', your article was uploaded, and is now in <strong>DRAFT</strong> status.</p>' .
                                '<p>This means only you can see the article and it is <strong style="color:red;">NOT YET PUBLISHED</strong>.</p>' .
                                '<p>You can now submit another draft article or view all your articles in the "View All Articles" tab.</p>'
                ]);
            } else {
                throw new Exception("Failed to insert article: " . $stmt->error);
            }
        }
    } catch (Exception $e) {
        error_log("Article save error: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Error saving article: ' . $e->getMessage()
        ]);
    }
} else {
    // No articles table found
    echo json_encode([
        'status' => 'error',
        'message' => 'Articles table not found in database. Please check database configuration.'
    ]);
}

$conn->close();
?>
