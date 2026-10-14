<?php
/** TEMPORARY: unpublish the two known bad Paris articles. Token-guarded. DELETE after. */
ini_set('display_errors', '0');
if (($_GET['t'] ?? '') !== 'unpub_9x2k7Q') { http_response_code(404); exit('not found'); }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_ten_admin.php';
require_once __DIR__ . '/lib/scraper_review.php';
header('Content-Type: application/json');

$ids = [35022, 35023];               // explicit, hardcoded targets
$a = getDBConnection_TENAdmin();
$out = ['targets' => $ids, 'before' => [], 'after' => []];

$inq = implode(',', $ids);
$r = $a->query("SELECT id, title, state, section, publications FROM articles WHERE id IN ($inq)");
while ($r && $x = $r->fetch_assoc()) $out['before'][] = $x;

// Extra guard: only touch these ids AND only if they are the tpe Breaking-News imageless failures.
$a->query("UPDATE articles SET state='draft'
           WHERE id IN ($inq) AND publications LIKE '%tpe%' AND section='Breaking News' AND imageless=1");
$out['affected'] = $a->affected_rows;

$r = $a->query("SELECT id, title, state FROM articles WHERE id IN ($inq)");
while ($r && $x = $r->fetch_assoc()) $out['after'][] = $x;
$a->close();

// Rebuild Paris front page + caches so the removed items drop off immediately.
try { scraper_trigger_publication_cache('tpe'); $out['cache'] = 'tpe regenerated'; }
catch (Throwable $e) { $out['cache_error'] = $e->getMessage(); }

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
