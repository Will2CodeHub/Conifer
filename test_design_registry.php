<?php
/**
 * Web-run self-test for the pure design-registry helpers.
 * Browse to /management/test_design_registry.php — expect "ALL PASS".
 * No DB, no login required (pure functions only). Delete after use if you like.
 */
require_once __DIR__ . '/lib/design_registry.php';
header('Content-Type: text/html; charset=utf-8');

$fail = 0;
function ck($cond, $msg) {
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . " — " . htmlspecialchars($msg) . "<br>";
    if (!$cond) { $fail++; }
}

ck(dl_block_allowed('masthead', 'front') === true, 'masthead allowed on front');
ck(dl_block_allowed('masthead', 'article') === false, 'masthead not allowed on article');
ck(dl_block_allowed('unknown_block', 'front') === false, 'unknown block rejected');

$v = dl_validate_layout(['blocks' => [['type' => 'masthead'], ['type' => 'nope']]], 'front');
ck($v['ok'] === false, 'validate flags an invalid block');
ck(count($v['layout']['blocks']) === 1, 'invalid block stripped, valid kept');
ck(isset($v['layout']['theme']['color_ink']), 'theme defaults filled in');

$desktop = ['theme' => dl_default_theme(), 'blocks' => [
    ['type' => 'site_header', 'settings' => []],
    ['type' => 'section_carousel', 'settings' => ['count' => 10]],
    ['type' => 'footer', 'settings' => []],
]];
$m = dl_merge_mobile($desktop, ['hidden' => ['section_carousel'], 'settings' => ['footer' => ['compact' => true]]]);
ck(count($m['blocks']) === 2, 'mobile diff hides the carousel');
ck($m['blocks'][1]['type'] === 'footer', 'remaining order preserved');
ck(($m['blocks'][1]['settings']['compact'] ?? null) === true, 'mobile per-block override applied');

$mo = dl_merge_mobile($desktop, ['order' => ['footer', 'site_header', 'section_carousel']]);
ck($mo['blocks'][0]['type'] === 'footer', 'mobile reorder applied');

echo $fail ? "<b style='color:#b00'>$fail FAILED</b>" : "<b style='color:#080'>ALL PASS</b>";
