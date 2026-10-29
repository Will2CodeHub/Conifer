<?php
/** One-off: create the ten_design_history table (per-item Save history). Admin only. */
require_once 'config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admin only'); }
require_once __DIR__ . '/config_ten_admin.php';

$conn = getDBConnection_TENAdmin();
if (!$conn) { exit('admin_ten connection failed'); }

$sql = "CREATE TABLE IF NOT EXISTS ten_design_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  publication VARCHAR(32) NOT NULL,
  item_kind VARCHAR(16) NOT NULL,
  item_id VARCHAR(64) NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  created_by VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_item (publication, item_kind, item_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

echo $conn->query($sql) ? "OK: ten_design_history ready" : ("ERR: " . $conn->error);
$conn->close();
