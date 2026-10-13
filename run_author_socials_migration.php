<?php
/**
 * Author socials — one-off migration (web-run once, admin).
 * Browse to /management/run_author_socials_migration.php while logged in.
 *
 * Adds three nullable link columns to TEN_Management.ten_users so the design
 * engine's author profile page (Figma Design B) can render X / LinkedIn /
 * Contact-Author links. Additive and reversible; safe to re-run (checks first).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }

$conn = getDBConnection();           // TEN_Management (where ten_users lives)
if (!$conn) { die('Could not connect to TEN_Management'); }

$cols = [
    'x_url'         => "ALTER TABLE ten_users ADD COLUMN x_url VARCHAR(255) NULL",
    'linkedin_url'  => "ALTER TABLE ten_users ADD COLUMN linkedin_url VARCHAR(255) NULL",
    'contact_email' => "ALTER TABLE ten_users ADD COLUMN contact_email VARCHAR(255) NULL",
];

$done = [];
$fail = [];
foreach ($cols as $name => $sql) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'ten_users' AND COLUMN_NAME = '" . $conn->real_escape_string($name) . "'");
    $exists = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($exists > 0) { $done[] = "ten_users.$name (already present)"; continue; }
    if ($conn->query($sql)) { $done[] = "ten_users.$name (added)"; }
    else { $fail[] = "ten_users.$name: " . $conn->error; }
}
$conn->close();

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Author socials migration</h1>';
echo '<p><strong>OK:</strong><br>' . implode('<br>', array_map('htmlspecialchars', $done)) . '</p>';
if ($fail) {
    echo '<p style="color:#b00"><strong>FAILED:</strong><br>' . implode('<br>', array_map('htmlspecialchars', $fail)) . '</p>';
} else {
    echo '<p style="color:#080">All steps completed. You can delete this script.</p>';
}
