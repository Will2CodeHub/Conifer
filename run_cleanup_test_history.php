<?php
/** One-off: remove the test history rows created during verification. Admin only. */
require_once 'config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admin only'); }
require_once __DIR__ . '/config_ten_admin.php';
$conn = getDBConnection_TENAdmin();
if (!$conn) { exit('admin_ten connection failed'); }
$conn->query("DELETE FROM ten_design_history WHERE publication='tpe' AND (item_id='__hist_test__' OR (item_kind='page' AND item_id='front'))");
echo "OK: removed " . $conn->affected_rows . " test history rows";
$conn->close();
