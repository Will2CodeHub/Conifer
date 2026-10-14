<?php
// One-time migration: per-publication breaking-news toggle.
// Adds publications.breaking_news_enabled and seeds it ON for Munich (tme) + Paris (tpe).
// DELETE THIS FILE AFTER RUNNING.
require_once 'config.php';
require_once __DIR__ . '/scraper/lib/news_sites_db.php'; // pulls in config_ten_admin + getDBConnection_TENAdmin
requireLogin();
if (!isAdmin()) { die('Unauthorized'); }

header('Content-Type: text/plain; charset=utf-8');

$conn = getDBConnection_TENAdmin();
if (!$conn) { die("✗ Could not connect to the admin_ten database.\n"); }

// Publications whose breaking-news desk should start switched on.
$ON = ['tme', 'tpe'];

echo "TEN — breaking-news toggle migration\n";
echo "====================================\n";

// 1) Add the column if it isn't there yet (idempotent).
$col = $conn->query("SHOW COLUMNS FROM publications LIKE 'breaking_news_enabled'");
if ($col && $col->num_rows > 0) {
    echo "→ Column publications.breaking_news_enabled already exists, skipped.\n";
} else {
    if ($conn->query("ALTER TABLE publications ADD COLUMN breaking_news_enabled TINYINT(1) NOT NULL DEFAULT 0")) {
        echo "✓ Added column publications.breaking_news_enabled (default 0).\n";
    } else {
        die("✗ Failed to add column: " . $conn->error . "\n");
    }
}

// 2) Seed: ON for the desks that should run, OFF for everything else.
$in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $ON)) . "'";
if ($conn->query("UPDATE publications SET breaking_news_enabled=1 WHERE publication IN ($in)")) {
    echo "✓ Enabled breaking-news desk for: " . implode(', ', $ON) . " ({$conn->affected_rows} row(s) matched-updated).\n";
} else {
    echo "✗ Failed to enable desks: " . $conn->error . "\n";
}
if ($conn->query("UPDATE publications SET breaking_news_enabled=0 WHERE publication NOT IN ($in)")) {
    echo "✓ Disabled breaking-news desk for all other publications ({$conn->affected_rows} row(s) changed).\n";
} else {
    echo "✗ Failed to disable other desks: " . $conn->error . "\n";
}

// 3) Show the resulting state.
echo "\nCurrent breaking-news flags:\n";
$res = $conn->query("SELECT publication, title, breaking_news_enabled FROM publications ORDER BY breaking_news_enabled DESC, publication ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo sprintf("  %-8s %-28s %s\n", $r['publication'], mb_substr((string)$r['title'], 0, 28),
                     ((int)$r['breaking_news_enabled'] === 1 ? 'ON' : 'off'));
    }
}
$conn->close();

echo "\nDone. DELETE run_breaking_news_migration.php now.\n";
