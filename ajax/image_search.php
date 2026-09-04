<?php
/**
 * Free-image search for the Article editor toolbar.
 *
 *  action=providers  -> the image sources available for the checkboxes.
 *  action=search     -> run the selected sources; cache results in the session;
 *                       return thumbnails + source labels (no client URLs kept).
 *  action=download   -> download one cached result BY INDEX (never a client URL,
 *                       plus a provider-host allowlist) and return a data URL so
 *                       Cropper.js can crop it. Same SSRF-safe model as the scraper.
 */
ini_set('display_errors', '0');
set_time_limit(60);

require_once '../config.php';
require_once __DIR__ . '/../scraper/lib/ImageSearch.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'providers':
            $avail = scraper_image_providers_available();
            $out = [];
            foreach ($avail as $key => $label) $out[] = ['key' => $key, 'label' => $label];
            echo json_encode(['status' => 'success', 'providers' => $out]);
            break;

        case 'search':
            $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
            if ($q === '') { echo json_encode(['status' => 'error', 'message' => 'Enter something to search for.']); break; }
            $raw = trim($_POST['providers'] ?? $_GET['providers'] ?? '');
            $providers = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen')) : null;
            $results = scraper_image_search($q, 8, $providers);
            $_SESSION['ten_img_search'] = $results; // cached for download-by-index
            $reg = scraper_image_provider_registry();
            $items = [];
            foreach ($results as $i => $r) {
                $items[] = [
                    'index'         => $i,
                    'thumb'         => $r['thumb'],
                    'provider'      => $r['provider'],
                    'provider_label'=> $reg[$r['provider']]['label'] ?? ucfirst($r['provider']),
                    'title'         => $r['title'],
                    'attribution'   => $r['attribution'],
                    'license'       => $r['license'],
                    'source_page'   => $r['source_page'],
                ];
            }
            echo json_encode(['status' => 'success', 'items' => $items, 'count' => count($items)]);
            break;

        case 'download':
            $index = (int)($_POST['index'] ?? $_GET['index'] ?? -1);
            $results = $_SESSION['ten_img_search'] ?? [];
            if ($index < 0 || !isset($results[$index]) || empty($results[$index]['url'])) {
                echo json_encode(['status' => 'error', 'message' => 'Image not found — run the search again.']);
                break;
            }
            $url = $results[$index]['url'];
            $attribution = $results[$index]['attribution'] ?? '';
            if (!scraper_image_host_allowed($url)) {
                echo json_encode(['status' => 'error', 'message' => 'Image source not permitted.']);
                break;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TENNewsBot/1.0)',
            ]);
            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$data || $code < 200 || $code >= 300) {
                echo json_encode(['status' => 'error', 'message' => 'Download failed (HTTP ' . $code . ')']);
                break;
            }
            $info = getimagesizefromstring($data);
            if (!$info) { echo json_encode(['status' => 'error', 'message' => 'Not a valid image']); break; }
            echo json_encode([
                'status' => 'success',
                'dataUrl' => 'data:' . $info['mime'] . ';base64,' . base64_encode($data),
                'attribution' => $attribution,
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => "Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
