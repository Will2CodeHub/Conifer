<?php
session_start();
require_once 'config.php';
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

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['url'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No image URL provided'
    ]);
    exit;
}

$imageUrl = $data['url'];

// Validate URL is from Pixabay
if (strpos($imageUrl, 'pixabay.com') === false && strpos($imageUrl, 'cdn.pixabay.com') === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid image source. Only Pixabay images are allowed.'
    ]);
    exit;
}

// Download the image
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $imageUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to download image from Pixabay: ' . $error
    ]);
    exit;
}

// Get image info
$imageInfo = getimagesizefromstring($imageData);
if (!$imageInfo) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid image data'
    ]);
    exit;
}

// Convert to base64 data URL
$mimeType = $imageInfo['mime'];
$base64 = base64_encode($imageData);
$dataUrl = "data:{$mimeType};base64,{$base64}";

// Log activity
logActivity('pixabay_image_downloaded', 'image', null, "Downloaded Pixabay image");

echo json_encode([
    'status' => 'success',
    'dataUrl' => $dataUrl,
    'mimeType' => $mimeType,
    'width' => $imageInfo[0],
    'height' => $imageInfo[1]
]);
?>
