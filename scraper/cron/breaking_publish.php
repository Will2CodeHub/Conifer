<?php
/**
 * CRON: auto-publish up to 2 "Breaking News" articles per day, per publication.
 *
 * Self-limiting — publishes only (2 - already published today) for each. Run a
 * few times a day so it catches fresh items and spaces them out, e.g. every 3h:
 *   CLI : php /path/to/management/scraper/cron/breaking_publish.php
 *   HTTP: curl -s "https://theeyenewspapers.com/management/scraper/cron/breaking_publish.php?t=bpub_9x2k7Q"
 * Optional ?pub=tpe limits the run to one publication; ?n=3 overrides the daily cap.
 */
ini_set('display_errors', '0');
@set_time_limit(0);
ignore_user_abort(true); // finish even if the (browser) client disconnects

$CRON_TOKEN = 'bpub_9x2k7Q';
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['t'] ?? '') !== $CRON_TOKEN)) { http_response_code(404); exit('not found'); }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/scraper_breaking.php';

// Publications that run a Breaking News desk (key => display name for the AI prompt).
// Driven by the per-publication toggle set in the TEN News Sites module, so editors
// can switch a publication's breaking desk on/off without a code change/deploy.
$BREAKING_PUBS = ns_breaking_enabled_publications();

$perDay = isset($_GET['n']) ? max(1, min(5, (int)$_GET['n'])) : 2;
$only   = isset($_GET['pub']) ? strtolower(trim((string)$_GET['pub'])) : '';

$results = [];
foreach ($BREAKING_PUBS as $pubKey => $pubName) {
    if ($only !== '' && $only !== $pubKey) continue;
    $results[$pubKey] = scraper_breaking_auto_publish($pubKey, $perDay, $pubName);
}

if (!$isCli) { header('Content-Type: application/json'); }
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
