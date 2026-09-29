<?php
/**
 * TEN Design System — one-off migration (web-run once).
 * Browse to /management/run_design_system_migration.php while logged in as admin.
 *
 * Creates, in the admin_ten database (where `publications` lives, and which the
 * site renderer's PDO Core also reads):
 *   - ten_design_layouts   (per-publication working DRAFT layouts)
 *   - ten_design_versions  (published, versioned snapshots for publish/rollback)
 *   - publications.design_enabled  (feature flag; only TPE = 1 during development)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }

$conn = getDBConnection_TENAdmin();
if (!$conn) { die('Could not connect to admin_ten'); }

$done = [];
$fail = [];

$ddl = [];
$ddl['ten_design_layouts'] = "CREATE TABLE IF NOT EXISTS ten_design_layouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  publication VARCHAR(16) NOT NULL,
  page_type VARCHAR(32) NOT NULL,
  device ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
  layout_json MEDIUMTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(64) NULL,
  UNIQUE KEY uniq_pub_page_device (publication, page_type, device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ddl['ten_design_versions'] = "CREATE TABLE IF NOT EXISTS ten_design_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  publication VARCHAR(16) NOT NULL,
  version_no INT NOT NULL,
  label VARCHAR(120) NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  is_live TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(64) NULL,
  UNIQUE KEY uniq_pub_version (publication, version_no),
  KEY idx_pub_live (publication, is_live)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($ddl as $name => $sql) {
    if ($conn->query($sql)) { $done[] = "table $name"; }
    else { $fail[] = "table $name: " . $conn->error; }
}

// Add publications.design_enabled if it is not already there.
$colRes = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = '" . DB_NAME_TENAdmin . "'
    AND TABLE_NAME = 'publications'
    AND COLUMN_NAME = 'design_enabled'");
$colExists = $colRes ? (int)$colRes->fetch_assoc()['c'] : 0;
if ($colExists === 0) {
    if ($conn->query("ALTER TABLE publications ADD COLUMN design_enabled TINYINT(1) NOT NULL DEFAULT 0")) {
        $done[] = 'publications.design_enabled (added)';
    } else {
        $fail[] = 'publications.design_enabled: ' . $conn->error;
    }
} else {
    $done[] = 'publications.design_enabled (already present)';
}

$conn->close();

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Design system migration</h1>';
echo '<p><strong>OK:</strong><br>' . implode('<br>', array_map('htmlspecialchars', $done)) . '</p>';
if ($fail) {
    echo '<p style="color:#b00"><strong>FAILED:</strong><br>' . implode('<br>', array_map('htmlspecialchars', $fail)) . '</p>';
} else {
    echo '<p style="color:#080">All steps completed. You can delete this script.</p>';
}
