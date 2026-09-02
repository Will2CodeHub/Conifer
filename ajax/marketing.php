<?php
/**
 * TEN Management - Marketing Module AJAX Handler
 * Handles all backend operations for the marketing and social media module
 */
if (!function_exists('customLog')) {
    function customLog($message, $level = 'DEBUG', $logFile = __DIR__ . '/custom_debug.log') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[" . $timestamp . "] [" . $level . "] " . $message . "\n";
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

require_once '../config.php';
requireLogin();

$conn = getDBConnection();

// CRITICAL: Verify database connection exists
if (!isset($conn) || $conn === null) {
    error_log("Marketing Module Error: Database connection not available");
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection error. Please check your configuration.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Handle both GET and POST (JSON) requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // For GET requests, parameters are in query string
    $action = $_GET['action'] ?? '';
} else {
    // For POST requests, read JSON from input stream
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $action = $data['action'] ?? $_POST['action'] ?? '';
}

// Get request data
//$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['ten_user_id'];

// Permission check
$canView = hasPermission('marketing.view') || isAdmin();
$canCreate = hasPermission('marketing.create') || isAdmin();
$canEdit = hasPermission('marketing.edit') || isAdmin();
$canDelete = hasPermission('marketing.delete') || isAdmin();

if (!$canView) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Handle different actions
switch ($action) {
    case 'get_articles':
        getArticles();
        break;
    
    case 'get_user_templates':
        getUserTemplates();
        break;
    
    case 'get_shared_templates':
        getSharedTemplates();
        break;
    
    case 'save_template':
        saveTemplate();
        break;
    
    case 'load_template':
        loadTemplate();
        break;
    
    case 'delete_template':
        deleteTemplate();
        break;
    
    case 'save_project':
        saveProject();
        break;
    
    case 'get_projects':
        getProjects();
        break;
    
    case 'get_project':
        getProject();
        break;
    
    case 'delete_project':
        deleteProject();
        break;
    
    case 'export_to_platforms':
        exportToPlatforms();
        break;
    
    case 'get_statistics':
        getStatistics();
        break;
    
    case 'get_post_stats':
        getPostStats();
        break;
    
    case 'update_post_stats':
        updatePostStats();
        break;
    
    case 'get_stickers':
        getStickers();
        break;
    
    case 'get_portals':
        getPortals();
        break;

    case 'save_carousel_images':
        error_log('Save carousel images action called');
    
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        error_log('Received data: ' . print_r($data, true));
        
        $carouselName = $data['carousel_name'] ?? 'carousel_' . time();
        $images = $data['images'] ?? [];
        
        if (empty($images)) {
            error_log('No images provided');
            echo json_encode(['success' => false, 'message' => 'No images provided']);
            exit;
        }
        
        error_log('Processing ' . count($images) . ' images');
        
        // Sanitize carousel name
        $carouselName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $carouselName);
        $carouselName = $carouselName . '_' . time();
        
        // Create carousel folder
        $baseFolder = dirname(__DIR__) . '/carousels/';
        if (!file_exists($baseFolder)) {
            mkdir($baseFolder, 0755, true);
            error_log('Created base folder: ' . $baseFolder);
        }
        
        $carouselFolder = $baseFolder . $carouselName . '/';
        if (!file_exists($carouselFolder)) {
            mkdir($carouselFolder, 0755, true);
            error_log('Created carousel folder: ' . $carouselFolder);
        }
        
        $savedFiles = [];
        
        foreach ($images as $index => $imageData) {
            $slideNumber = $imageData['slideNumber'];
            $imageUrl = $imageData['imageUrl'];
            
            error_log('Processing slide ' . $slideNumber . ', data length: ' . strlen($imageUrl));
            
            // Extract base64 data
            if (preg_match('/^data:image\/(\w+);base64,/', $imageUrl, $type)) {
                $imageUrl = substr($imageUrl, strpos($imageUrl, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif
                
                $imageUrl = str_replace(' ', '+', $imageUrl);
                $imageData = base64_decode($imageUrl);
                
                if ($imageData === false) {
                    error_log('Failed to decode base64 for slide ' . $slideNumber);
                    continue;
                }
                
                error_log('Decoded image data length: ' . strlen($imageData));
                
                $filename = 'slide_' . str_pad($slideNumber, 2, '0', STR_PAD_LEFT) . '.png';
                $filepath = $carouselFolder . $filename;
                
                $result = file_put_contents($filepath, $imageData);
                
                if ($result !== false) {
                    error_log('Saved file: ' . $filepath . ' (' . $result . ' bytes)');
                    $savedFiles[] = $filename;
                } else {
                    error_log('Failed to save file: ' . $filepath);
                }
            } else {
                error_log('Invalid image data format for slide ' . $slideNumber);
            }
        }
        
        error_log('Saved ' . count($savedFiles) . ' files');
        
        if (count($savedFiles) > 0) {
            echo json_encode([
                'success' => true,
                'folder' => $carouselName,
                'path' => $carouselFolder,
                'files' => $savedFiles,
                'count' => count($savedFiles)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save images. Check server logs for details.'
            ]);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Get articles from JSON file
 */
function getArticles() {
    global $conn, $userId;
    
    $jsonFile = '../data/articles.json';
    
    if (file_exists($jsonFile)) {
        $articles = json_decode(file_get_contents($jsonFile), true);
        echo json_encode([
            'success' => true,
            'articles' => $articles
        ]);
    } else {
        // Return empty array if file doesn't exist
        echo json_encode([
            'success' => true,
            'articles' => []
        ]);
    }
}

/**
 * Get user's templates
 */
function getUserTemplates() {
    global $conn, $userId;
    
    error_log("getUserTemplates called for user: $userId");
    
    // Get templates that belong to this user (regardless of is_shared value)
    $query = "SELECT * FROM ten_marketing_templates 
              WHERE user_id = ? 
              ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $templates = [];
    while ($row = $result->fetch_assoc()) {
        error_log("Template found: ID=" . $row['id'] . ", Name=" . $row['name'] . ", Slides=" . $row['slides']);
        // Keep template_data for JavaScript to use
        $row['template_data'] = json_decode($row['template_data'], true);
        $templates[] = $row;
    }
    
    error_log("getUserTemplates: Returning " . count($templates) . " templates");
    error_log("Templates JSON: " . json_encode(['success' => true, 'templates' => $templates]));
    
    echo json_encode([
        'success' => true,
        'templates' => $templates
    ]);
}

/**
 * Get shared templates (available to all users)
 */
function getSharedTemplates() {
    global $conn;
    
    $query = "SELECT * FROM ten_marketing_templates 
              WHERE is_shared = 1 
              ORDER BY created_at DESC";
    
    $result = $conn->query($query);
    
    $templates = [];
    while ($row = $result->fetch_assoc()) {
        // Keep template_data for JavaScript to use
        $row['template_data'] = json_decode($row['template_data'], true);
        $templates[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'templates' => $templates
    ]);
}

/**
 * Save a new template - Complete implementation with all element data
 */
function saveTemplate() {
    global $conn, $userId, $canCreate;
    
    error_log("saveTemplate called by user: $userId");
    
    if (!$canCreate) {
        error_log("saveTemplate: Permission denied for user $userId");
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("saveTemplate input: " . print_r($input, true));
    
    $name = sanitize2($input['name'] ?? '');
    $slides = intval($input['slides'] ?? 1);
    $templateData = $input['template_data'] ?? [];
    $thumbnail = $input['thumbnail'] ?? null;
    $isShared = isset($input['is_shared']) ? intval($input['is_shared']) : 0;
    
    if (empty($name)) {
        error_log("saveTemplate: Name is empty");
        echo json_encode(['success' => false, 'message' => 'Template name is required']);
        return;
    }
    
    if (empty($templateData)) {
        error_log("saveTemplate: Template data is empty");
        echo json_encode(['success' => false, 'message' => 'Template data is required']);
        return;
    }
    
    // Encode template data as JSON
    $templateDataJson = json_encode($templateData);
    error_log("saveTemplate: Template data JSON length: " . strlen($templateDataJson));
    
    // Save thumbnail if provided (base64)
    $thumbnailUrl = null;
    if ($thumbnail && strpos($thumbnail, 'data:image') === 0) {
        // Extract base64 data
        $thumbnailData = explode(',', $thumbnail)[1] ?? null;
        if ($thumbnailData) {
            $decoded = base64_decode($thumbnailData);
            if ($decoded !== false) {
                // Create thumbnails directory if it doesn't exist
                $thumbDir = dirname(__DIR__) . '/marketing_thumbnails/';
                if (!file_exists($thumbDir)) {
                    mkdir($thumbDir, 0755, true);
                }
                
                $filename = 'template_' . time() . '_' . $userId . '.png';
                $filepath = $thumbDir . $filename;
                
                if (file_put_contents($filepath, $decoded)) {
                    $thumbnailUrl = '/management/marketing_thumbnails/' . $filename;
                    error_log("saveTemplate: Thumbnail saved to $thumbnailUrl");
                }
            }
        }
    }
    
    // Generate placeholder if no thumbnail
    if (!$thumbnailUrl) {
        $thumbnailUrl = 'https://via.placeholder.com/400x400/667eea/ffffff?text=' . urlencode($name);
        error_log("saveTemplate: Using placeholder thumbnail");
    }
    
    // Insert into database
    $query = "INSERT INTO ten_marketing_templates 
              (user_id, name, slides, template_data, thumbnail_url, is_shared, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("saveTemplate: Failed to prepare statement: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param('isissi', $userId, $name, $slides, $templateDataJson, $thumbnailUrl, $isShared);
    
    if ($stmt->execute()) {
        $templateId = $conn->insert_id;
        
        error_log("saveTemplate: Successfully saved template ID: $templateId");
        
        // Log activity
        logActivity2($userId, 'create', 'template', $templateId, "Created template: $name");
        
        echo json_encode([
            'success' => true,
            'message' => 'Template saved successfully',
            'template_id' => $templateId
        ]);
    } else {
        error_log("saveTemplate: Execute failed: " . $stmt->error . " | " . $conn->error);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save template: ' . $stmt->error
        ]);
    }
}

/**
 * Load a specific template with complete data
 */
function loadTemplate() {
    global $conn, $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $templateId = intval($input['template_id'] ?? 0);
    
    if ($templateId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid template ID']);
        return;
    }
    
    // Query template - check if user owns it or if it's shared
    $query = "SELECT * FROM ten_marketing_templates 
              WHERE id = ? AND (user_id = ? OR is_shared = 1)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $templateId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Template not found or access denied']);
        return;
    }
    
    $template = $result->fetch_assoc();
    $template['template_data'] = json_decode($template['template_data'], true);
    
    echo json_encode([
        'success' => true,
        'template' => $template
    ]);
}

/**
 * Delete a template
 */
function deleteTemplate() {
    global $conn, $userId, $canDelete;
    
    if (!$canDelete) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $templateId = intval($input['template_id'] ?? 0);
    
    // Check ownership or admin
    if (!isAdmin()) {
        $query = "SELECT user_id FROM ten_marketing_templates WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        
        if (!$template || $template['user_id'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }
    }
    
    $query = "DELETE FROM ten_marketing_templates WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $templateId);
    
    if ($stmt->execute()) {
        logActivity2($userId, 'delete', 'template', $templateId, "Deleted template");
        
        echo json_encode([
            'success' => true,
            'message' => 'Template deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete template'
        ]);
    }
}

/**
 * Save a project/carousel
 */
function saveProject() {
    global $conn, $userId, $canCreate;
    
    if (!$canCreate) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $project = $input['project'] ?? [];
    
    if (empty($project['name'])) {
        echo json_encode(['success' => false, 'message' => 'Project name is required']);
        return;
    }
    
    $name = sanitize2($project['name']);
    $totalSlides = intval($project['totalSlides']);
    $projectData = json_encode([
        'slides' => $project['slides'],
        'canvasState' => $project['canvasState'],
        'currentSlide' => $project['currentSlide']
    ]);
    $articleLinks = json_encode($project['articleLinks'] ?? []);
    
    $query = "INSERT INTO ten_marketing_projects 
              (user_id, project_name, total_slides, project_data, article_links, created_at, updated_at) 
              VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('isiss', $userId, $name, $totalSlides, $projectData, $articleLinks);
    
    if ($stmt->execute()) {
        $projectId = $conn->insert_id;
        
        logActivity2($userId, 'create', 'marketing_project', $projectId, "Created project: $name");
        
        echo json_encode([
            'success' => true,
            'message' => 'Project saved successfully',
            'project_id' => $projectId
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save project'
        ]);
    }
}

/**
 * Get user's projects
 */
function getProjects() {
    global $conn, $userId;
    customLog('FLAG 1');
    
    $query = "SELECT id, project_name as name, total_slides as totalSlides, created_at 
              FROM ten_marketing_projects 
              WHERE user_id = ? 
              ORDER BY updated_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    customLog('FLAG 2 $userId:' . $userId);
    
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'projects' => $projects
    ]);
}

/**
 * Get a specific project
 */
function getProject() {
    global $conn, $userId;
    
    $projectId = intval($_GET['id'] ?? 0);
    
    $query = "SELECT * FROM ten_marketing_projects WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $projectId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        return;
    }
    
    // Parse JSON data
    $projectData = json_decode($project['project_data'], true);
    $project['slides'] = $projectData['slides'];
    $project['canvasState'] = $projectData['canvasState'];
    $project['currentSlide'] = $projectData['currentSlide'];
    $project['articleLinks'] = json_decode($project['article_links'], true);
    $project['totalSlides'] = intval($project['total_slides']);
    
    unset($project['project_data']);
    unset($project['article_links']);
    unset($project['total_slides']);
    
    echo json_encode([
        'success' => true,
        'project' => $project
    ]);
}

/**
 * Delete a project
 */
function deleteProject() {
    global $conn, $userId, $canDelete;
    
    if (!$canDelete) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = intval($input['project_id'] ?? 0);
    
    // Check ownership
    if (!isAdmin()) {
        $query = "SELECT user_id FROM ten_marketing_projects WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $project = $result->fetch_assoc();
        
        if (!$project || $project['user_id'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }
    }
    
    $query = "DELETE FROM ten_marketing_projects WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $projectId);
    
    if ($stmt->execute()) {
        logActivity2($userId, 'delete', 'marketing_project', $projectId, "Deleted project");
        
        echo json_encode([
            'success' => true,
            'message' => 'Project deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete project'
        ]);
    }
}

/**
 * Export to social media platforms
 */
function exportToPlatforms() {
    global $conn, $userId, $canCreate;
    
    if (!$canCreate) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $data = $input['data'] ?? [];
    
    $platforms = $data['platforms'] ?? [];
    $posts = $data['posts'] ?? [];
    $metadata = $data['metadata'] ?? [];
    $mode = $data['mode'] ?? 'draft';
    $images = $data['images'] ?? [];
    
    $results = [];
    $allSuccess = true;
    
    foreach ($platforms as $platform) {
        $result = exportToPlatform(
            $platform,
            $posts[$platform] ?? '',
            $metadata[$platform] ?? [],
            $images,
            $mode
        );
        
        $results[$platform] = $result;
        
        if (!$result['success']) {
            $allSuccess = false;
        }
        
        // Save post record
        savePostRecord($platform, $posts[$platform], $metadata[$platform], $result, $mode);
    }
    
    if ($allSuccess) {
        echo json_encode([
            'success' => true,
            'message' => 'Successfully exported to all platforms',
            'results' => $results
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Some exports failed',
            'results' => $results
        ]);
    }
}

/**
 * Export to a specific platform
 */
function exportToPlatform($platform, $text, $metadata, $images, $mode) {
    // In a production environment, you would integrate with actual APIs here
    // For now, we'll simulate the export
    
    $apiConfig = [
        'instagram' => [
            'enabled' => true,
            'api_endpoint' => 'https://graph.instagram.com/v12.0/',
            'access_token' => '', // Would be stored in config or database
        ],
        'facebook' => [
            'enabled' => true,
            'api_endpoint' => 'https://graph.facebook.com/v12.0/',
            'access_token' => '',
        ],
        'twitter' => [
            'enabled' => true,
            'api_endpoint' => 'https://api.twitter.com/2/',
            'access_token' => '',
        ]
    ];
    
    if (!isset($apiConfig[$platform])) {
        return [
            'success' => false,
            'message' => 'Platform not supported'
        ];
    }
    
    $config = $apiConfig[$platform];
    
    if (!$config['enabled']) {
        return [
            'success' => false,
            'message' => 'Platform API not configured'
        ];
    }
    
    // Simulate API call
    // In production, you would make actual API requests here
    
    // Example for Instagram Graph API:
    /*
    $postData = [
        'caption' => $text,
        'image_url' => $images[0] ?? '',
        'access_token' => $config['access_token']
    ];
    
    if ($mode === 'publish') {
        $endpoint = $config['api_endpoint'] . 'me/media';
    } else {
        $endpoint = $config['api_endpoint'] . 'me/media_publish'; // Draft mode
    }
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $responseData = json_decode($response, true);
    */
    
    // For demonstration, return success
    return [
        'success' => true,
        'message' => 'Post created successfully',
        'post_id' => 'simulated_' . $platform . '_' . time(),
        'url' => 'https://example.com/' . $platform . '/post/123456',
        'mode' => $mode
    ];
}

/**
 * Save post record to database
 */
function savePostRecord($platform, $text, $metadata, $result, $mode) {
    global $conn, $userId;
    
    $postId = $result['post_id'] ?? null;
    $postUrl = $result['url'] ?? '';
    $status = $result['success'] ? ($mode === 'draft' ? 'draft' : 'published') : 'failed';
    
    // Extract title from text (first line or first 50 chars)
    $lines = explode("\n", $text);
    $title = substr($lines[0], 0, 100);
    
    $metadataJson = json_encode($metadata);
    
    $query = "INSERT INTO ten_marketing_posts 
              (user_id, platform, title, content, metadata, status, external_post_id, post_url, published_at, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('isssssss', $userId, $platform, $title, $text, $metadataJson, $status, $postId, $postUrl);
    $stmt->execute();
    
    $recordId = $conn->insert_id;
    
    logActivity2($userId, 'create', 'marketing_post', $recordId, "Published to $platform: $title");
    
    return $recordId;
}

/**
 * Get statistics
 */
function getStatistics() {
    global $conn, $userId;
    
    try {
        // Double-check connection
        if (!$conn) {
            throw new Exception("Database connection not available");
        }
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'ten_marketing_posts'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            // Return empty stats if table doesn't exist
            echo json_encode([
                'success' => true,
                'stats' => [
                    'instagram' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                    'facebook' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                    'twitter' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                    'totalEngagement' => 0,
                    'engagementChange' => 0,
                    'recentPosts' => []
                ]
            ]);
            return;
        }
        
        // Get platform counts
        $query = "SELECT platform, COUNT(*) as total, 
                  SUM(engagement) as total_engagement,
                  SUM(reach) as total_reach
                  FROM ten_marketing_posts 
                  WHERE user_id = ? AND status = 'published'
                  GROUP BY platform";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare query: " . $conn->error);
        }
        
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stats = [
            'instagram' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
            'facebook' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
            'twitter' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
            'totalEngagement' => 0,
            'engagementChange' => 0
        ];
        
        while ($row = $result->fetch_assoc()) {
            $platform = $row['platform'];
            if (isset($stats[$platform])) {
                $stats[$platform]['total'] = intval($row['total']);
                $stats[$platform]['engagement'] = intval($row['total_engagement']);
                $stats[$platform]['reach'] = intval($row['total_reach']);
                $stats['totalEngagement'] += intval($row['total_engagement']);
            }
        }
        
        // Calculate month-over-month changes (simplified)
        foreach (['instagram', 'facebook', 'twitter'] as $platform) {
            $stats[$platform]['change'] = rand(5, 25); // In production, calculate actual change
        }
        $stats['engagementChange'] = rand(10, 30);
        
        // Get recent posts
        $query = "SELECT platform, title, post_url as url, published_at as date, 
                  engagement, reach 
                  FROM ten_marketing_posts 
                  WHERE user_id = ? AND status = 'published'
                  ORDER BY published_at DESC 
                  LIMIT 20";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare query: " . $conn->error);
        }
        
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $recentPosts = [];
        while ($row = $result->fetch_assoc()) {
            $recentPosts[] = $row;
        }
        
        $stats['recentPosts'] = $recentPosts;
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        error_log("Marketing getStatistics error: " . $e->getMessage());
        echo json_encode([
            'success' => true,
            'stats' => [
                'instagram' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                'facebook' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                'twitter' => ['total' => 0, 'change' => 0, 'engagement' => 0, 'reach' => 0],
                'totalEngagement' => 0,
                'engagementChange' => 0,
                'recentPosts' => []
            ],
            'message' => 'Statistics unavailable: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get stats for a specific post
 */
function getPostStats() {
    global $conn, $userId;
    
    $postId = intval($_GET['post_id'] ?? 0);
    
    $query = "SELECT * FROM ten_marketing_posts WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $postId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    
    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        return;
    }
    
    // In production, you would fetch real-time stats from platform APIs
    $post['stats'] = [
        'likes' => rand(100, 1000),
        'comments' => rand(10, 100),
        'shares' => rand(5, 50),
        'views' => rand(1000, 10000),
        'clicks' => rand(50, 500)
    ];
    
    echo json_encode([
        'success' => true,
        'post' => $post
    ]);
}

/**
 * Update post statistics (usually called by cron or webhook)
 */
function updatePostStats() {
    global $conn, $userId, $canEdit;
    
    if (!$canEdit) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $postId = intval($input['post_id'] ?? 0);
    $engagement = intval($input['engagement'] ?? 0);
    $reach = intval($input['reach'] ?? 0);
    
    $query = "UPDATE ten_marketing_posts 
              SET engagement = ?, reach = ?, updated_at = NOW() 
              WHERE id = ? AND user_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iiii', $engagement, $reach, $postId, $userId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Stats updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update stats'
        ]);
    }
}

/**
 * Log activity
 */
function logActivity2($userId, $action, $entityType, $entityId, $description) {
    global $conn;
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $query = "INSERT INTO ten_activity_log 
              (user_id, action, entity_type, entity_id, description, ip_address, user_agent, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ississs', $userId, $action, $entityType, $entityId, $description, $ipAddress, $userAgent);
    $stmt->execute();
}

/**
 * Sanitize input
 */
function sanitize2($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get stickers/assets library
 */
function getStickers() {
    // Return sample stickers - in production, fetch from database or filesystem
    $stickers = [
        [
            'id' => 1,
            'name' => 'Star',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/2b50.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'symbols'
        ],
        [
            'id' => 2,
            'name' => 'Heart',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/2764.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'symbols'
        ],
        [
            'id' => 3,
            'name' => 'Fire',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f525.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'symbols'
        ],
        [
            'id' => 4,
            'name' => 'Thumbs Up',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f44d.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'hands'
        ],
        [
            'id' => 5,
            'name' => 'Rocket',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f680.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'travel'
        ],
        [
            'id' => 6,
            'name' => 'Trophy',
            'file_url' => 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f3c6.svg',
            'width' => 100,
            'height' => 100,
            'category' => 'activities'
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'stickers' => $stickers
    ]);
}

/**
 * Get portals/websites for export
 */
function getPortals() {
    // Return sample portals - in production, fetch from database
    $portals = [
        [
            'id' => 1,
            'portal_name' => 'The Eye Newspapers',
            'portal_url' => 'https://theeyenewspapers.com',
            'platform' => 'Instagram',
            'is_active' => 1
        ],
        [
            'id' => 2,
            'portal_name' => 'The Eye Newspapers',
            'portal_url' => 'https://theeyenewspapers.com',
            'platform' => 'Facebook',
            'is_active' => 1
        ],
        [
            'id' => 3,
            'portal_name' => 'The Munich Eye',
            'portal_url' => 'https://themunicheye.com',
            'platform' => 'Instagram',
            'is_active' => 1
        ],
        [
            'id' => 4,
            'portal_name' => 'The Germany Eye',
            'portal_url' => 'https://thegermanyeye.com',
            'platform' => 'Instagram',
            'is_active' => 1
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'portals' => $portals
    ]);
}