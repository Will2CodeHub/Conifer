<?php
/**
 * CRON: auto-publish up to 2 TME "Breaking News" articles per day.
 *
 * Self-limiting — publishes only (2 - already published today). Run a few times
 * a day so it catches fresh items and spaces the two out, e.g. every 3 hours:
 *   CLI : php /path/to/management/scraper/cron/breaking_publish.php
 *   HTTP: curl -s "https://theeyenewspapers.com/management/scraper/cron/breaking_publish.php?t=bpub_9x2k7Q"
 */
ini_set('display_errors', '0');
@set_time_limit(0);

$CRON_TOKEN = 'bpub_9x2k7Q';
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['t'] ?? '') !== $CRON_TOKEN)) { http_response_code(404); exit('not found'); }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/scraper_breaking.php';

$perDay = isset($_GET['n']) ? max(1, min(5, (int)$_GET['n'])) : 2;
$result = scraper_breaking_auto_publish($perDay);

if (!$isCli) { header('Content-Type: application/json'); }
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
