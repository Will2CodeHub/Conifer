<?php
session_start();
require_once 'config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');

// Get last 3 years including current year
$currentYear = (int)date('Y');
$years = [$currentYear, $currentYear - 1, $currentYear - 2];

$images = [];

foreach ($years as $year) {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/article_images/year_folders/' . $year . '/';
    
    if (is_dir($dir)) {
        $files = scandir($dir, SCANDIR_SORT_DESCENDING); // Newest first
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filepath = $dir . $file;
            if (is_file($filepath)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['webp', 'jpg', 'jpeg', 'png', 'gif'])) {
                    $image_url = 'https://theeyenewspapers.com/article_images/year_folders/' . $year . '/' . $file;
                    
                    // Get attribution from database if exists
                    $attribution = '';
                    try {
                        require_once $_SERVER['DOCUMENT_ROOT'].'/content/ten_db/pdo_ten.php';
                        $pdoCore = Core::getInstance();
                        
                        $query = "SELECT attribution FROM article_image_attribution WHERE image = :image ORDER BY date DESC LIMIT 1";
                        $pdoObject = $pdoCore->dbh->prepare($query);
                        $queryArray = array(':image' => $image_url);
                        
                        if ($pdoObject->execute($queryArray)) {
                            $pdoObject->setFetchMode(PDO::FETCH_ASSOC);
                            if ($row = $pdoObject->fetch()) {
                                $attribution = $row['attribution'];
                            }
                        }
                    } catch (Exception $e) {
                        // Ignore errors
                    }
                    
                    $images[] = [
                        'url' => $image_url,
                        'filename' => $file,
                        'attribution' => $attribution,
                        'modified' => filemtime($filepath),
                        'year' => $year
                    ];
                }
            }
        }
    }
}

// Sort by modified date, newest first
usort($images, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

echo json_encode([
    'status' => 'success',
    'images' => $images,
    'count' => count($images)
]);
?>
