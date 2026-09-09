<?php
/** App-wide settings (currently: General Statistics defaults). Admin only. */
require_once '../config.php';
requireLogin();
require_once '../lib/app_settings.php';
require_once '../lib/stats_sites.php';
header('Content-Type: application/json');

if (!isAdmin() && !hasPermission('system.settings')) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized — admin only']); exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$resp = ['success'=>false,'message'=>''];

try {
    switch ($action) {
        case 'get_stats_defaults': {
            $sites = ten_stats_sites();
            $periods = ten_stats_periods();
            $pub = ten_get_setting('stats_default_publication', 'tme');
            if (!isset($sites[$pub])) $pub = 'tme';
            $per = ten_get_setting('stats_default_period', 'today');
            if (!isset($periods[$per])) $per = 'today';
            $resp = ['success'=>true,'publication'=>$pub,'period'=>$per];
            break;
        }
        case 'save_stats_defaults': {
            $sites = ten_stats_sites();
            $periods = ten_stats_periods();
            $pub = trim($_POST['publication'] ?? '');
            $per = trim($_POST['period'] ?? '');
            if (!isset($sites[$pub]))   throw new Exception('Unknown publication');
            if (!isset($periods[$per])) throw new Exception('Unknown time period');
            ten_set_setting('stats_default_publication', $pub);
            ten_set_setting('stats_default_period', $per);
            $resp = ['success'=>true,'message'=>'Saved'];
            break;
        }
        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $resp = ['success'=>false,'message'=>$e->getMessage()];
}
echo json_encode($resp);
