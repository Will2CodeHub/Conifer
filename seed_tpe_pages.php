<?php
/**
 * Seed (or re-seed) The Paris Eye's About / Contact / Search page layouts, then
 * publish. NON-DESTRUCTIVE: unlike seed_tpe_design.php this does NOT delete the
 * publication's existing design rows — it upserts only these three page types
 * (dl_save_draft is INSERT … ON DUPLICATE KEY UPDATE per page), so William's
 * edits to the front page, blocks, templates etc. are preserved. Publishing then
 * snapshots ALL current drafts into a new live version.
 * Web-run by an admin: /management/seed_tpe_pages.php   Idempotent.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
require_once __DIR__ . '/lib/design_layouts_db.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }

// Resolve the exact publication code for Paris (matches BASE_SITE_ABBREVIATION).
$conn = getDBConnection_TENAdmin();
if (!$conn) { die('admin_ten connection failed'); }
$res = $conn->query("SELECT publication FROM publications WHERE url LIKE '%thepariseye%' OR title LIKE '%Paris%' ORDER BY id ASC LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;
$PUB = $row ? (string)$row['publication'] : 'tpe';
$conn->close();

$theme = dl_default_theme();
$mobile = ['hidden' => [], 'theme' => ['gutter' => '16px']];

$pages = [
    'about' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header', 'settings' => []],
            ['type' => 'about_page',  'settings' => []],
            ['type' => 'footer',      'settings' => []],
        ]],
        'mobile' => $mobile,
    ],
    'contact' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',  'settings' => []],
            ['type' => 'contact_page', 'settings' => ['action' => '/backend/contact_form_handler.php']],
            ['type' => 'footer',       'settings' => []],
        ]],
        'mobile' => $mobile,
    ],
    'search' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',    'settings' => []],
            ['type' => 'search_results', 'settings' => ['per_page' => 40]],
            ['type' => 'footer',         'settings' => []],
        ]],
        'mobile' => $mobile,
    ],
];

dl_seed_defaults($PUB, $pages);                       // upsert only these three pages
$v = dl_publish($PUB, 'Add About / Contact / Search page designs', 'seed_pages');

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Seed TPE — About / Contact / Search</h1>";
echo "<p>Publication code: <strong>" . htmlspecialchars($PUB) . "</strong></p>";
echo "<p>Upserted <strong>about</strong>, <strong>contact</strong>, <strong>search</strong> (desktop + mobile); published <strong>v{$v}</strong>. Other pages untouched.</p>";
echo "<p>Check: <a href='https://thepariseye.com/design/about'>/design/about</a> &middot; "
   . "<a href='https://thepariseye.com/design/contact'>/design/contact</a> &middot; "
   . "<a href='https://thepariseye.com/design/search?q=paris'>/design/search?q=paris</a></p>";
