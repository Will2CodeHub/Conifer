<?php
/**
 * Seed The Paris Eye (TPE) design: front + section + article + static pages
 * (desktop + mobile diff), enable the feature flag, and publish a version.
 * Web-run by an admin: /management/seed_tpe_design.php
 * Idempotent: re-running overwrites the TPE drafts and publishes a new version.
 * The front page mirrors the live Paris content (ticker, breaking news, feature,
 * carousel, and all section blocks).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
require_once __DIR__ . '/lib/design_layouts_db.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }

$PUB   = 'TPE';
$theme = dl_default_theme();
$mtheme = ['gutter' => '16px', 'section_gap' => '32px'];

function tpe_static_page($theme, $key) {
    return [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header', 'settings' => []],
            ['type' => 'rich_text',   'settings' => ['content_key' => $key]],
            ['type' => 'footer',      'settings' => []],
        ]],
        'mobile' => ['hidden' => [], 'theme' => ['gutter' => '16px']],
    ];
}

$pages = [
    'front' => [
        'desktop' => ['theme' => $theme, 'blocks' => [
            ['type' => 'site_header',    'settings' => ['show_search' => true, 'show_subscribe' => true]],
            ['type' => 'masthead',       'settings' => ['tagline' => 'Independent journalism for the international community in France']],
            ['type' => 'ticker',         'settings' => []],
            ['type' => 'breaking_news',  'settings' => []],
            ['type' => 'front_feature',  'settings' => ['sidebar_count' => 5]],
            ['type' => 'section_carousel','settings' => ['section' => '', 'count' => 10]],
            ['type' => 'front_sections', 'settings' => ['per_section' => 3]],
            ['type' => 'footer',         'settings' => []],
        ]],
        // Mobile: lighter — drop the carousel, trim spacing.
        'mobile' => ['hidden' => ['section_carousel'], 'theme' => $mtheme],
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
    'impressum'  => tpe_static_page($theme, 'impressum'),
    'contact'    => tpe_static_page($theme, 'contact'),
    'about'      => tpe_static_page($theme, 'about'),
    'privacy'    => tpe_static_page($theme, 'privacy'),
    'terms'      => tpe_static_page($theme, 'terms'),
    'disclaimer' => tpe_static_page($theme, 'disclaimer'),
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

$v = dl_publish($PUB, 'Paris Eye design — live content parity', 'seed');

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Seed The Paris Eye</h1>";
echo "<p>Seeded " . count($pages) . " pages (desktop + mobile), design_enabled matched {$affected} row(s), published <strong>v{$v}</strong>.</p>";
if ($affected === 0) {
    echo "<p style='color:#b00'>Warning: no publications row matched publication='TPE'.</p>";
}
