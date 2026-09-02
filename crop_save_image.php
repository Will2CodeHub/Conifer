<?php
session_start();
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Write a scaled WebP copy of a GD image into $destDir (created if needed),
 * fitting within maxW x maxH, preserving aspect ratio, never upscaling.
 * Mirrors the legacy resizeImage() placement folders/sizes.
 */
function scraper_make_image_size($srcImage, int $srcW, int $srcH, string $destDir, string $filename, int $maxW, int $maxH): void {
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
        @chmod($destDir, 0755);
    }
    if (!is_dir($destDir) || !is_writable($destDir)) {
        return; // non-fatal
    }
    $scale = min($maxW / $srcW, $maxH / $srcH, 1.0);
    $nw = max(1, (int)round($srcW * $scale));
    $nh = max(1, (int)round($srcH * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $nw, $nh, $srcW, $srcH);
    imagewebp($canvas, rtrim($destDir, '/') . '/' . $filename, 85);
    imagedestroy($canvas);
}

// Placement folders + sizes (match the legacy multi-size process).
$SCRAPER_PLACEMENTS = [
    'frontpage_headline' => [490, 310],
    'section_headline'   => [790, 500],
    'left_article'       => [381, 267],
    'right_article'      => [191, 134],
    'footer_images'      => [184, 129],
];

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['image'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No image data provided'
    ]);
    exit;
}

$imageData = $data['image'];
$attribution = $data['attribution'] ?? '';

// Extract base64 image data
if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
    $imageData = substr($imageData, strpos($imageData, ',') + 1);
    $type = strtolower($type[1]); // jpg, png, gif
    
    if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid image type'
        ]);
        exit;
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid image data format'
    ]);
    exit;
}

// Decode base64
$imageData = base64_decode($imageData);

if ($imageData === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to decode image data'
    ]);
    exit;
}

// Create image from string
$image = imagecreatefromstring($imageData);

if ($image === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to create image'
    ]);
    exit;
}

// Create directory structure: /article_images/year_folders/YYYY/
$year = date('Y');
$base_path = $_SERVER['DOCUMENT_ROOT'] . '/article_images/year_folders/' . $year . '/';

if (!file_exists($base_path)) {
    @mkdir($base_path, 0755, true);
    @chmod($base_path, 0755);
}

if (!is_dir($base_path) || !is_writable($base_path)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Cannot create or write to directory: ' . $base_path
    ]);
    exit;
}

// Generate filename: ten_YYYY_MM_DD_HH_MM_SS.webp
$filename = 'ten_' . date('Y_m_d_H_i_s') . '.webp';
$filepath = $base_path . $filename;

// Convert to WebP format with good quality
$success = imagewebp($image, $filepath, 90);

if ($success) {
    // Generate placement-size versions in subfolders (legacy folders/sizes).
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    foreach ($SCRAPER_PLACEMENTS as $folder => $dim) {
        scraper_make_image_size($image, $srcW, $srcH, $base_path . $folder, $filename, $dim[0], $dim[1]);
    }
}
imagedestroy($image);

if (!$success) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save image as WebP'
    ]);
    exit;
}

// Get the web-accessible URL
$image_url = 'https://theeyenewspapers.com/article_images/year_folders/' . $year . '/' . $filename;

// Save attribution to database if provided
$image_id = null;
try {
    // Use the old database connection from the document
    require_once $_SERVER['DOCUMENT_ROOT'].'/content/ten_db/pdo_ten.php';
    
    $pdoCore = Core::getInstance();
    $query = "INSERT INTO article_image_attribution (image, attribution, image_title, image_tags, date) VALUES (:image, :attribution, :image_title, :image_tags, NOW())";
    $pdoObject = $pdoCore->dbh->prepare($query);

    $image_title = '';
    $image_tags = '';

    $queryArray = array(
        ':image' => $image_url,
        ':attribution' => $attribution,
        ':image_title' => $image_title,
        ':image_tags' => $image_tags
    );
    
    $pdoObject->execute($queryArray);
    $image_id = $pdoCore->dbh->lastInsertId();
} catch (Exception $e) {
    // Non-fatal, just log it
    error_log("Failed to save attribution: " . $e->getMessage());
}

// Create HTML for image browser modal (for Tab 1)
$image_html = '<div class="image_block_item" style="display: inline-block; margin: 10px; text-align: center; border: 1px solid #ddd; padding: 10px; border-radius: 8px; background: #fff;">';
$image_html .= '<a href="' . htmlspecialchars($image_url) . '" target="_blank" title="' . htmlspecialchars($attribution) . '">';
$image_html .= '<img src="' . htmlspecialchars($image_url) . '" style="max-width: 150px; max-height: 150px; display: block; margin-bottom: 8px; border-radius: 4px;">';
$image_html .= '</a>';
$image_html .= '<button type="button" class="image_chosen_id btn btn-sm btn-primary" style="margin-top: 5px;">';
$image_html .= '<i class="fas fa-check"></i> Insert Image';
$image_html .= '</button>';
if ($attribution) {
    $image_html .= '<p style="font-size: 11px; color: #6b7280; margin-top: 5px; word-break: break-word;">' . htmlspecialchars($attribution) . '</p>';
}
$image_html .= '</div>';

// Log activity
logActivity('image_uploaded', 'image', $image_id, "Uploaded image: " . $filename);

echo json_encode([
    'status' => 'success',
    'url' => $image_url,
    'filename' => $filename,
    'filepath' => $filepath,
    'attribution' => $attribution,
    'image_html' => $image_html,
    'message' => 'Image uploaded successfully'
]);
?>
