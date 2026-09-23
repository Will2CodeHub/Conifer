<?php
/**
 * CRON: precompute translations + AI curation ranking for every section that has
 * fresh content, so the Curate screen only reads pre-computed rows (instant loads).
 *
 * Run every ~15 min, right after the ingest scheduler. Two ways to invoke:
 *   CLI : php /path/to/management/scraper/cron/precompute.php
 *   HTTP: curl -s "https://theeyenewspapers.com/management/scraper/cron/precompute.php?t=TOKEN"
 */
ini_set('display_errors', '0');
@set_time_limit(0);

$CRON_TOKEN = 'p7r3c0mp';
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['t'] ?? '') !== $CRON_TOKEN)) { http_response_code(404); exit('not found'); }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/scraper_review.php';

// ?list=1 -> just report which sections need work (no processing).
if (!$isCli && ($_GET['list'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'sections' => scraper_sections_needing_precompute()]);
    exit;
}

// ?section=<id> -> process a single section for one bounded pass (returns 'more' if not done).
$one = (int)($_GET['section'] ?? 0);
if ($one > 0) {
    header('Content-Type: application/json');
    try { $r = scraper_precompute_section($one); echo json_encode(['ok' => true, 'section' => $one] + $r); }
    catch (Throwable $e) { echo json_encode(['ok' => false, 'section' => $one, 'error' => $e->getMessage()]); }
    exit;
}

$start = time();
$budget = $isCli ? 600 : 90; // seconds; must finish before PHP max_execution_time
$ids = scraper_sections_needing_precompute();
$done = []; $processed = 0;

foreach ($ids as $sid) {
    if ((time() - $start) > $budget) { $done[] = "stopped (time budget) with " . (count($ids) - $processed) . " section(s) left"; break; }
    try {
        // Keep passing over the section until it is translated AND ranked (a big
        // "Run now" ingest can be 200 items = several 60-item passes), within budget.
        $tr = 0; $rk = 0; $passes = 0;
        do {
            $r = scraper_precompute_section((int)$sid);
            $tr += (int)($r['translated'] ?? 0); $rk += (int)($r['ranked'] ?? 0); $passes++;
            $progress = !empty($r['translated']) && empty($r['busy']);
        } while (!empty($r['more']) && $progress && (time() - $start) <= $budget);
        $done[] = "section $sid: translated=$tr ranked=$rk passes=$passes" . (!empty($r['busy']) ? ' (busy)' : '') . (!empty($r['more']) ? ' (more)' : '');
    } catch (Throwable $e) {
        $done[] = "section $sid: ERROR " . $e->getMessage();
    }
    $processed++;
}

$summary = [
    'ok' => true,
    'ts' => date('c'),
    'sections_needing' => count($ids),
    'processed' => $processed,
    'detail' => $done,
];

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] precompute: {$processed}/" . count($ids) . " sections\n";
    foreach ($done as $line) echo "  - $line\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($summary);
}
