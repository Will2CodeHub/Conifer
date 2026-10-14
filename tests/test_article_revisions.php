<?php
/**
 * Unit tests for the pure edit-history helpers. No DB required.
 * Run: php tests/test_article_revisions.php
 */

// The lib references getDBConnection_TENAdmin() only inside functions we don't
// call here, so it's safe to include standalone for the pure helpers.
require_once __DIR__ . '/../lib/article_revisions.php';

$failures = 0;
function check($label, $cond) {
    global $failures;
    if ($cond) { echo "  PASS  $label\n"; }
    else { echo "  FAIL  $label\n"; $failures++; }
}

echo "ten_build_snapshot round-trips the fields + pub_flags\n";
$fields = [
    'title' => 'Héllo', 'article_text' => '<p>x</p>', 'journalist_id' => 42, 'state' => 'draft',
];
$pf = ['tpe' => ['frontpage' => 1, 'section_head' => 0, 'sponsored' => 0]];
$json = ten_build_snapshot($fields, $pf);
$decoded = json_decode($json, true);
check('title preserved (unicode not escaped)', $decoded['title'] === 'Héllo');
check('journalist_id preserved', $decoded['journalist_id'] === 42);
check('missing field defaults to empty string', $decoded['alias'] === '');
check('pub_flags nested map preserved', $decoded['pub_flags']['tpe']['frontpage'] === 1);
check('all snapshot fields present', count(array_intersect(ten_revision_fields(), array_keys($decoded))) === count(ten_revision_fields()));

echo "ten_revisions_to_prune keeps newest-N non-published + ALL published\n";
// Rows newest-first: ids 10..1. Mark a couple published.
$rows = [];
for ($id = 10; $id >= 1; $id--) {
    $rows[] = ['id' => $id, 'state' => ($id === 7 || $id === 3) ? 'published' : 'draft'];
}
// keepN = 3 non-published. Non-published newest-first: 10,9,8,(skip7),6,5,4,(skip3),2,1
// keep 10,9,8 ; delete 6,5,4,2,1 ; always keep 7 & 3.
$del = ten_revisions_to_prune($rows, 3);
sort($del);
check('deletes the right ids', $del === [1, 2, 4, 5, 6]);
check('never deletes a published id (7)', !in_array(7, $del, true));
check('never deletes a published id (3)', !in_array(3, $del, true));
check('keeps newest 3 non-published (10,9,8 not deleted)', !array_intersect([10, 9, 8], $del));

echo "ten_revisions_to_prune with fewer than N keeps everything\n";
$few = [['id' => 2, 'state' => 'draft'], ['id' => 1, 'state' => 'draft']];
check('nothing pruned under the cap', ten_revisions_to_prune($few, 30) === []);

echo "ten_revisions_to_prune keeps all when every row is published\n";
$allPub = [['id' => 3, 'state' => 'published'], ['id' => 2, 'state' => 'published'], ['id' => 1, 'state' => 'published']];
check('all-published never pruned', ten_revisions_to_prune($allPub, 1) === []);

echo "ten_diff_snapshots flags only changed fields\n";
$old = ten_build_snapshot(['title' => 'A', 'section' => 'News', 'journalist_id' => 1], []);
$new = ten_build_snapshot(['title' => 'B', 'section' => 'News', 'journalist_id' => 1], []);
$diff = ten_diff_snapshots(json_decode($old, true), json_decode($new, true));
check('changed field detected (title)', $diff['title']['changed'] === true);
check('unchanged field not flagged (section)', $diff['section']['changed'] === false);
check('unchanged field not flagged (journalist_id)', $diff['journalist_id']['changed'] === false);
check('diff carries old and new values', $diff['title']['old'] === 'A' && $diff['title']['new'] === 'B');

echo "ten_diff_snapshots compares pub_flags as structure\n";
$o = json_decode(ten_build_snapshot([], ['tpe' => ['frontpage' => 0, 'section_head' => 0, 'sponsored' => 0]]), true);
$n = json_decode(ten_build_snapshot([], ['tpe' => ['frontpage' => 1, 'section_head' => 0, 'sponsored' => 0]]), true);
$d = ten_diff_snapshots($o, $n);
check('pub_flags change detected', $d['pub_flags']['changed'] === true);

echo "\n" . ($failures === 0 ? "ALL TESTS PASSED" : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
