<?php
/**
 * Seed The Paris Eye (TPE) design: five pages (desktop + mobile diff), enable the
 * feature flag, and publish v1. Web-run once by an admin:
 *   /management/seed_tpe_design.php
 * Idempotent: re-running overwrites the TPE drafts and publishes another version.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
require_once __DIR__ . '/lib/design_layouts_db.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }

$PUB   = 'TPE';
$theme = dl_default_theme();

$pages = [
    'front' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',   'settings' => ['show_search' => true, 'show_subscribe' => true]],
            ['type' => 'masthead',      'settings' => ['tagline' => 'Independent journalism for the international community in France']],
            ['type' => 'front_feature', 'settings' => ['hero_source' => 'latest', 'lead_source' => 'latest', 'sidebar_count' => 5]],
            ['type' => 'section_carousel', 'settings' => ['section' => 'culture', 'count' => 10]],
            ['type' => 'footer',        'settings' => []],
        ]],
        'mobile' => ['hidden' => ['section_carousel'], 'theme' => ['gutter' => '16px', 'section_gap' => '32px']],
    ],
    'section' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',    'settings' => []],
            ['type' => 'section_title',  'settings' => ['uppercase' => true]],
            ['type' => 'section_lead',   'settings' => []],
            ['type' => 'headlines_list', 'settings' => ['count' => 5, 'show_readtime' => true]],
            ['type' => 'article_grid',   'settings' => ['columns' => 3, 'per_page' => 12]],
            ['type' => 'footer',         'settings' => []],
        ]],
        'mobile' => ['hidden' => [], 'theme' => ['gutter' => '16px']],
    ],
    'article' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',      'settings' => []],
            ['type' => 'article_header',   'settings' => []],
            ['type' => 'article_hero',     'settings' => ['ratio' => '16x9']],
            ['type' => 'article_body',     'settings' => []],
            ['type' => 'article_byline',   'settings' => []],
            ['type' => 'article_comments', 'settings' => ['enabled' => true]],
            ['type' => 'footer',           'settings' => []],
        ]],
        'mobile' => ['hidden' => [], 'theme' => ['gutter' => '16px']],
    ],
    'impressum' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header', 'settings' => []],
            ['type' => 'rich_text',   'settings' => ['content_key' => 'impressum']],
            ['type' => 'footer',      'settings' => []],
        ]],
        'mobile' => ['hidden' => [], 'theme' => ['gutter' => '16px']],
    ],
    'contact' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header', 'settings' => []],
            ['type' => 'rich_text',   'settings' => ['content_key' => 'contact']],
            ['type' => 'footer',      'settings' => []],
        ]],
        'mobile' => ['hidden' => [], 'theme' => ['gutter' => '16px']],
    ],
];

dl_seed_defaults($PUB, $pages);

$conn = getDBConnection_TENAdmin();
if (!$conn) { die('admin_ten connection failed'); }
$st = $conn->prepare("UPDATE publications SET design_enabled=1 WHERE publication=?");
$st->bind_param('s', $PUB);
$st->execute();
$affected = $st->affected_rows;
$st->close();
$conn->close();

$v = dl_publish($PUB, 'Initial Paris Eye design (Figma)', 'seed');

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Seed The Paris Eye</h1>";
echo "<p>Seeded 5 pages (desktop + mobile), design_enabled update matched {$affected} row(s), published <strong>v{$v}</strong>.</p>";
if ($affected === 0) {
    echo "<p style='color:#b00'>Warning: no publications row matched publication='TPE'. Check the acronym.</p>";
}
