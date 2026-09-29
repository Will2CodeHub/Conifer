<?php
/**
 * TEN Design System — block registry + pure layout helpers.
 *
 * Pure and dependency-free (no DB, no globals). This is the MASTER COPY.
 * It is copied VERBATIM to the site template at design/lib/design_registry.php
 * so the renderer and the editor share one definition of blocks + merge rules.
 * If you change this file, copy it to design/lib/ as well.
 */

/** Static (content) pages a publication's design can cover. */
function dl_static_pages(): array {
    return ['impressum', 'contact', 'about', 'privacy', 'terms', 'disclaimer'];
}

/** The pages a publication's design can cover. */
function dl_page_types(): array {
    return array_merge(['front', 'section', 'article'], dl_static_pages());
}

/** Human labels for the page selector. */
function dl_page_label(string $page): string {
    $map = [
        'front' => 'Front page', 'section' => 'Section page', 'article' => 'Article page',
        'impressum' => 'Impressum', 'contact' => 'Contact', 'about' => 'About us',
        'privacy' => 'Privacy policy', 'terms' => 'Terms', 'disclaimer' => 'Disclaimer',
    ];
    return $map[$page] ?? ucfirst($page);
}

/** Default theme tokens (fonts, colours, spacing). Emitted as CSS variables. */
function dl_default_theme(): array {
    return [
        'font_display'  => '"Playfair Display", Georgia, serif',
        'font_body'     => '"Lora", Georgia, serif',
        'font_ui'       => '"Inter", system-ui, sans-serif',
        'color_ink'     => '#14142b',
        'color_body'    => '#333333',
        'color_muted'   => '#6b6b6b',
        'color_accent'  => '#1a2b4a',
        'color_bg'      => '#ffffff',
        'color_button'  => '#14142b',
        'container_max' => '1200px',
        'gutter'        => '24px',
        'section_gap'   => '48px',
        'block_gap'     => '24px',
    ];
}

/**
 * The block registry: type => [label, pages it may appear on, default settings].
 * Every block maps to reusable data logic from the existing backend/* components.
 */
function dl_registry(): array {
    $all = dl_page_types();
    return [
        'site_header'      => ['label' => 'Site header',       'pages' => $all,                                'defaults' => ['show_search' => true, 'show_subscribe' => true]],
        'masthead'         => ['label' => 'Masthead',          'pages' => ['front'],                           'defaults' => ['tagline' => '']],
        'front_feature'    => ['label' => 'Front feature',     'pages' => ['front'],                           'defaults' => ['hero_source' => 'latest', 'lead_source' => 'latest', 'sidebar_count' => 5]],
        'front_sections'   => ['label' => 'Section blocks (all sections)', 'pages' => ['front'],               'defaults' => ['per_section' => 3]],
        'headlines_list'   => ['label' => 'Latest headlines',  'pages' => ['front', 'section'],                'defaults' => ['count' => 5, 'show_readtime' => true]],
        'section_title'    => ['label' => 'Section title',     'pages' => ['section'],                         'defaults' => ['uppercase' => true]],
        'section_lead'     => ['label' => 'Section lead',      'pages' => ['section'],                         'defaults' => []],
        'article_grid'     => ['label' => 'Article grid',      'pages' => ['section'],                         'defaults' => ['columns' => 3, 'per_page' => 12]],
        'article_header'   => ['label' => 'Article header',    'pages' => ['article'],                         'defaults' => []],
        'article_hero'     => ['label' => 'Article hero image','pages' => ['article'],                         'defaults' => ['ratio' => '16x9']],
        'article_body'     => ['label' => 'Article body',      'pages' => ['article'],                         'defaults' => []],
        'article_byline'   => ['label' => 'Byline',            'pages' => ['article'],                         'defaults' => []],
        'article_comments' => ['label' => 'Comments',          'pages' => ['article'],                         'defaults' => ['enabled' => true]],
        'section_carousel' => ['label' => 'Section carousel',  'pages' => ['front', 'section', 'article'],     'defaults' => ['section' => '', 'count' => 10]],
        'advert'           => ['label' => 'Advert slot',       'pages' => ['front', 'section', 'article'],     'defaults' => ['slot' => '']],
        'ticker'           => ['label' => 'News ticker',       'pages' => ['front'],                           'defaults' => []],
        'breaking_news'    => ['label' => 'Breaking news',     'pages' => ['front'],                           'defaults' => []],
        'rich_text'        => ['label' => 'Rich text',         'pages' => dl_static_pages(),                   'defaults' => ['content_key' => '']],
        'footer'           => ['label' => 'Footer',            'pages' => $all,                                'defaults' => []],
    ];
}

/** True when $type is a known block allowed on $page. */
function dl_block_allowed(string $type, string $page): bool {
    $r = dl_registry();
    return isset($r[$type]) && in_array($page, $r[$type]['pages'], true);
}

/**
 * Validate + clean a desktop layout for a page. Drops unknown/disallowed blocks,
 * fills theme defaults, and normalises each block to {type, settings}.
 * Returns ['ok'=>bool, 'errors'=>string[], 'layout'=>['theme'=>..,'blocks'=>..]].
 */
function dl_validate_layout(array $layout, string $page): array {
    $errors = [];
    if (!isset($layout['blocks']) || !is_array($layout['blocks'])) {
        return ['ok' => false, 'errors' => ['missing blocks array'], 'layout' => ['theme' => dl_default_theme(), 'blocks' => []]];
    }
    $clean = [];
    foreach ($layout['blocks'] as $b) {
        if (!isset($b['type']) || !dl_block_allowed($b['type'], $page)) {
            $errors[] = 'dropped invalid block: ' . ($b['type'] ?? '?');
            continue;
        }
        $clean[] = [
            'type'     => $b['type'],
            'settings' => (isset($b['settings']) && is_array($b['settings'])) ? $b['settings'] : [],
        ];
    }
    $theme = array_merge(dl_default_theme(), (isset($layout['theme']) && is_array($layout['theme'])) ? $layout['theme'] : []);
    return ['ok' => empty($errors), 'errors' => $errors, 'layout' => ['theme' => $theme, 'blocks' => $clean]];
}

/**
 * Apply a mobile diff to a full desktop layout and return a resolved full layout.
 * Diff keys: hidden[] (block types to drop), settings{type:overrides}, order[] (types),
 * theme{overrides}.
 */
function dl_merge_mobile(array $desktop, array $mobileDiff): array {
    $blocks = $desktop['blocks'] ?? [];

    $hidden = $mobileDiff['hidden'] ?? [];
    if ($hidden) {
        $blocks = array_values(array_filter($blocks, function ($b) use ($hidden) {
            return !in_array($b['type'], $hidden, true);
        }));
    }

    if (!empty($mobileDiff['settings']) && is_array($mobileDiff['settings'])) {
        foreach ($blocks as &$b) {
            if (isset($mobileDiff['settings'][$b['type']]) && is_array($mobileDiff['settings'][$b['type']])) {
                $b['settings'] = array_merge($b['settings'], $mobileDiff['settings'][$b['type']]);
            }
        }
        unset($b);
    }

    if (!empty($mobileDiff['order']) && is_array($mobileDiff['order'])) {
        $byType = [];
        foreach ($blocks as $b) { $byType[$b['type']][] = $b; }
        $ordered = [];
        foreach ($mobileDiff['order'] as $t) {
            if (!empty($byType[$t])) { $ordered[] = array_shift($byType[$t]); }
        }
        foreach ($byType as $rest) {
            foreach ($rest as $b) { $ordered[] = $b; }
        }
        if ($ordered) { $blocks = $ordered; }
    }

    $theme = array_merge($desktop['theme'] ?? dl_default_theme(), $mobileDiff['theme'] ?? []);
    return ['theme' => $theme, 'blocks' => $blocks];
}
