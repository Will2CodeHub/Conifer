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
    return array_merge(['front', 'section', 'article', 'author', 'search'], dl_static_pages());
}

/** Human labels for the page selector. */
function dl_page_label(string $page): string {
    $map = [
        'front' => 'Front page', 'section' => 'Section page', 'article' => 'Article page',
        'author' => 'Author page', 'search' => 'Search',
        'impressum' => 'Impressum', 'contact' => 'Contact', 'about' => 'About us',
        'privacy' => 'Privacy policy', 'terms' => 'Terms', 'disclaimer' => 'Disclaimer',
    ];
    return $map[$page] ?? ucfirst($page);
}

/**
 * Built-in page types as [slug, title] rows for the Design Studio "Pages" column.
 * Thin wrapper over dl_page_types()/dl_page_label() so the hub and the renderer
 * agree on which pages are built in (and therefore not renameable/deletable).
 */
function dl_builtin_pages(): array {
    $out = [];
    foreach (dl_page_types() as $slug) {
        $out[] = ['slug' => $slug, 'title' => dl_page_label($slug)];
    }
    return $out;
}

/**
 * The built-in "mother" template every page inherits unless it names another.
 * Owns the locked header + footer blocks and the page-level style box. It is
 * built in (created_by=null) so it can never be renamed or deleted.
 */
function dl_builtin_template(): array {
    return [
        'id'         => 'tpl_default',
        'name'       => 'Default page',
        'created_by' => null,
        'blocks'     => [
            ['type' => 'site_header', 'settings' => ['show_search' => true, 'show_subscribe' => true]],
            ['type' => 'footer', 'settings' => []],
        ],
        'header'     => ['type' => 'site_header', 'settings' => ['show_search' => true, 'show_subscribe' => true]],
        'footer'     => ['type' => 'footer', 'settings' => []],
        'page_style' => [
            'container_max' => '1200px',
            'gutter'        => '24px',
            'margin'        => '0',
            'padding'       => '0',
            'background'    => '#ffffff',
        ],
    ];
}

/**
 * Default theme tokens — the full editorial typographic + spacing scale.
 * Emitted as CSS variables (see theme.php) and consumed by every block's CSS.
 * 3-font system: Lora (display/headlines), Merriweather (article body only),
 * Inter (UI / metadata / tags / captions). Zero border-radius on containers
 * and images (--radius). The AI "restyle" mode writes exactly these keys.
 */
function dl_default_theme(): array {
    return [
        // Fonts
        'font_display'  => '"Lora", Georgia, serif',
        'font_body'     => '"Merriweather", Georgia, serif',
        'font_ui'       => '"Inter", system-ui, sans-serif',
        // Colours (navy-ink monochrome)
        'color_ink'     => '#14142b',
        'color_body'    => '#2a2a33',
        'color_muted'   => '#6b6b6b',
        'color_accent'  => '#1a2b4a',
        'color_bg'      => '#ffffff',
        'color_button'  => '#14142b',
        // Headline scale (Lora)
        'h1_size'       => 'clamp(2.4rem, 4vw, 3.4rem)',
        'h1_weight'     => '700',
        'h1_line'       => '1.12',
        'h1_spacing'    => '-0.01em',
        'h2_size'       => 'clamp(1.8rem, 2.6vw, 2.3rem)',
        'h2_weight'     => '700',
        'h2_line'       => '1.18',
        'h3_size'       => '1.35rem',
        'h3_weight'     => '700',
        'h3_line'       => '1.25',
        // Body (Merriweather) + reading measure
        'body_size'     => '1.15rem',
        'body_line'     => '1.8',
        'body_measure'  => '44rem',
        // UI / metadata / tags (Inter)
        'ui_size'       => '0.8rem',
        'ui_weight'     => '600',
        'ui_spacing'    => '0.08em',
        'caption_size'  => '0.85rem',
        // Shape + spacing
        'radius'        => '0',
        'container_max' => '1200px',
        'gutter'        => '24px',
        'section_gap'   => '64px',
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
        'front_feature'    => ['label' => 'Front feature',     'pages' => ['front'],                           'defaults' => ['mid_count' => 2, 'sidebar_count' => 6, 'hero_ratio' => '3 / 2', 'mid_ratio' => '16 / 9']],
        'front_sections'   => ['label' => 'Section blocks (all sections)', 'pages' => ['front'],               'defaults' => ['per_section' => 3, 'sections' => '']],
        'headlines_list'   => ['label' => 'Latest headlines',  'pages' => ['front', 'section'],                'defaults' => ['count' => 5, 'show_readtime' => true]],
        'section_title'    => ['label' => 'Section title',     'pages' => ['section'],                         'defaults' => ['uppercase' => true]],
        'section_lead'     => ['label' => 'Section lead + headlines', 'pages' => ['section'],                  'defaults' => ['sidebar_count' => 6]],
        'article_grid'     => ['label' => 'Article grid',      'pages' => ['section'],                         'defaults' => ['columns' => 3, 'per_page' => 12]],
        'article_header'   => ['label' => 'Article header',    'pages' => ['article'],                         'defaults' => []],
        'article_hero'     => ['label' => 'Article hero image','pages' => ['article'],                         'defaults' => ['ratio' => '16x9']],
        'article_body'     => ['label' => 'Article body',      'pages' => ['article'],                         'defaults' => []],
        'article_byline'   => ['label' => 'Byline',            'pages' => ['article'],                         'defaults' => []],
        'article_related'  => ['label' => 'Related articles (3-up)', 'pages' => ['article'],                  'defaults' => ['count' => 3, 'heading' => '']],
        'article_quickreads' => ['label' => 'Quick reads (3-up)',  'pages' => ['article'],                       'defaults' => ['count' => 3, 'heading' => 'Quick Reads News']],
        'article_comments' => ['label' => 'Comments',          'pages' => ['article'],                         'defaults' => ['enabled' => true]],
        'author_profile'   => ['label' => 'Author profile',    'pages' => ['author'],                          'defaults' => []],
        'author_articles'  => ['label' => 'Author article list','pages' => ['author'],                         'defaults' => ['count' => 12, 'heading' => '']],
        'triple_box'       => ['label' => 'Triple box (insurance / clinics / events)', 'pages' => ['front', 'section'], 'defaults' => []],
        'section_carousel' => ['label' => 'Section carousel',  'pages' => ['front', 'section', 'article'],     'defaults' => ['section' => '', 'count' => 10]],
        'advert'           => ['label' => 'Advert slot',       'pages' => ['front', 'section', 'article'],     'defaults' => ['slot' => '']],
        'ticker'           => ['label' => 'News ticker',       'pages' => ['front'],                           'defaults' => []],
        'breaking_news'    => ['label' => 'Breaking news',     'pages' => ['front'],                           'defaults' => ['count' => 6]],
        'rich_text'        => ['label' => 'Rich text',         'pages' => dl_static_pages(),                   'defaults' => ['content_key' => '']],
        'about_page'       => ['label' => 'About page',        'pages' => ['about'],                           'defaults' => []],
        'contact_page'     => ['label' => 'Contact page',      'pages' => ['contact'],                         'defaults' => ['action' => '/backend/contact_form_handler.php']],
        'search_results'   => ['label' => 'Search results',    'pages' => ['search'],                          'defaults' => ['per_page' => 40]],
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
    $reg = dl_registry();
    $isBuiltinPage = in_array($page, dl_page_types(), true);
    foreach ($layout['blocks'] as $b) {
        $type = $b['type'] ?? '';
        // Named/custom blocks (blk_*) may be placed on any page. Built-in blocks
        // are checked against their allowed pages on built-in pages; on custom
        // pages any registered block type is allowed.
        $isNamed = is_string($type) && strpos($type, 'blk_') === 0;
        $ok = $isNamed
            || ($isBuiltinPage ? dl_block_allowed($type, $page) : isset($reg[$type]));
        if ($type === '' || !$ok) {
            $errors[] = 'dropped invalid block: ' . ($type !== '' ? $type : '?');
            continue;
        }
        $clean[] = [
            'type'     => $type,
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

/**
 * Placeholder reference ("manual"). Dependency-free static definitions of every
 * {{token}} the engine can fill, so the Design Studio can list them and the user
 * can copy them (e.g. for AI). Two parts:
 *   'shared' — page-level groups (article / author / site). Every token in a
 *              page's group is usable in ANY block on that page (the engine merges
 *              the shared context beneath each block — see design/lib/placeholders.php).
 *   'blocks' — the tokens each block's own context provides. Tokens with
 *              'loop'=>true live inside that block's repeating {{#each ...}} list.
 * Each item: ['token'=>'name', 'label'=>'Friendly name', 'example'=>'sample'].
 */
function dl_placeholder_defs(): array {
    $P = function (string $t, string $l, string $e = '', bool $loop = false): array {
        return ['token' => $t, 'label' => $l, 'example' => $e, 'loop' => $loop];
    };
    return [
        'shared' => [
            'article' => [
                'label' => 'Article — usable in any block on the Article page',
                'items' => [
                    $P('title', 'Article title', 'France unveils new budget'),
                    $P('standfirst', 'Standfirst / summary', 'A short summary of the story.'),
                    $P('section', 'Section name', 'POLITICS'),
                    $P('section_href', 'Section link', '/design/politics'),
                    $P('date', 'Date (published)', 'March 16 2026'),
                    $P('date_published', 'Date published', 'March 16 2026'),
                    $P('date_updated', 'Date last updated', 'March 18 2026'),
                    $P('read_time', 'Read time (minutes)', '4'),
                    $P('image', 'Lead image URL', 'https://…/photo.jpg'),
                    $P('url', 'Article link', '/design/read/123/slug'),
                    $P('byline', 'Byline (author line)', 'By Jane Doe'),
                    $P('author_name', 'Author name', 'Jane Doe'),
                    $P('author_role', 'Author role', 'Senior Reporter'),
                    $P('author_bio', 'Author bio', 'Jane writes about French politics.'),
                    $P('author_photo', 'Author photo URL', 'https://…/jane.jpg'),
                    $P('author_href', 'Author page link', '/design/author/5'),
                ],
            ],
            'author' => [
                'label' => 'Author — usable in any block on the Author page',
                'items' => [
                    $P('name', 'Author name', 'Jane Doe'),
                    $P('role', 'Role', 'Senior Reporter'),
                    $P('bio', 'Bio', 'Jane writes about French politics.'),
                    $P('photo', 'Photo URL', 'https://…/jane.jpg'),
                    $P('email', 'Email', 'jane@example.com'),
                    $P('x_url', 'X / Twitter URL', 'https://x.com/jane'),
                    $P('linkedin_url', 'LinkedIn URL', 'https://linkedin.com/in/jane'),
                ],
            ],
            'site' => [
                'label' => 'Site — usable in any block on any page',
                'items' => [
                    $P('site_name', 'Publication name', 'The Paris Eye'),
                    $P('logo', 'Logo URL', '/design/site/logo.png'),
                    $P('search_icon', 'Search icon URL', '/design/assets/search.svg'),
                    $P('subscribe_href', 'Subscribe link', '/design/contact'),
                    $P('contact_href', 'Contact link', '/design/contact'),
                ],
            ],
        ],
        'blocks' => [
            'masthead'        => ['label' => 'Masthead', 'items' => [$P('site_name', 'Publication name'), $P('tagline', 'Tagline')]],
            'section_title'   => ['label' => 'Section title', 'items' => [$P('name', 'Section name'), $P('cls', 'CSS class')]],
            'advert'          => ['label' => 'Advert slot', 'items' => [$P('slot', 'Slot id'), $P('label', 'Placeholder label')]],
            'ticker'          => ['label' => 'News ticker', 'items' => [$P('label', 'Ticker label'), $P('title', 'Item headline', '', true), $P('href', 'Item link', '', true)]],
            'rich_text'       => ['label' => 'Rich text', 'items' => [$P('title', 'Heading'), $P('body', 'Body HTML')]],
            'headlines_list'  => ['label' => 'Latest headlines', 'items' => [$P('headlines_label', 'Heading'), $P('title', 'Headline', '', true), $P('href', 'Link', '', true), $P('section', 'Section', '', true), $P('section_href', 'Section link', '', true), $P('meta', 'Meta (date · read time)', '', true)]],
            'breaking_news'   => ['label' => 'Breaking news', 'items' => [$P('heading', 'Heading'), $P('viewall_href', 'View-all link'), $P('viewall_label', 'View-all label'), $P('title', 'Headline', '', true), $P('href', 'Link', '', true), $P('read_time', 'Read time', '', true)]],
            'triple_box'      => ['label' => 'Triple box', 'items' => [$P('pkv_title', 'Box 1 title'), $P('pkv_href', 'Box 1 link'), $P('pkv_text', 'Box 1 text'), $P('clinics_title', 'Box 2 title'), $P('clinics_href', 'Box 2 link'), $P('clinics_text', 'Box 2 text'), $P('events_title', 'Box 3 title'), $P('events_href', 'Box 3 link')]],
            'site_header'     => ['label' => 'Top menu / header', 'items' => [$P('logo', 'Logo URL'), $P('site_name', 'Publication name'), $P('search_icon', 'Search icon'), $P('subscribe_label', 'Subscribe label'), $P('name', 'Menu item name', '', true), $P('href', 'Menu item link', '', true)]],
            'front_feature'   => ['label' => 'Front feature', 'items' => [$P('headlines_label', 'Sidebar heading'), $P('hero_ratio', 'Hero image ratio'), $P('hero.title', 'Hero title'), $P('hero.href', 'Hero link'), $P('hero.image', 'Hero image'), $P('hero.summary', 'Hero summary'), $P('hero.meta', 'Hero meta'), $P('title', 'Card title', '', true), $P('href', 'Card link', '', true), $P('image', 'Card image', '', true), $P('summary', 'Card summary', '', true)]],
            'front_sections'  => ['label' => 'Section blocks', 'items' => [$P('name', 'Section name', '', true), $P('href', 'Section link', '', true), $P('viewall_label', 'View-all label', '', true), $P('title', 'Card title', '', true), $P('snippet', 'Card snippet', '', true), $P('image', 'Card image', '', true)]],
            'section_lead'    => ['label' => 'Section lead', 'items' => [$P('headlines_label', 'Sidebar heading'), $P('lead.title', 'Lead title'), $P('lead.href', 'Lead link'), $P('lead.image', 'Lead image'), $P('lead.summary', 'Lead summary'), $P('title', 'Sidebar item', '', true), $P('href', 'Sidebar link', '', true), $P('meta', 'Sidebar meta', '', true)]],
            'section_carousel' => ['label' => 'More stories carousel', 'items' => [$P('title', 'Carousel title'), $P('href', 'Item link', '', true), $P('image', 'Item image', '', true), $P('section', 'Item section', '', true)]],
            'article_grid'    => ['label' => 'Article grid', 'items' => [$P('columns', 'Column count'), $P('title', 'Card title', '', true), $P('href', 'Card link', '', true), $P('image', 'Card image', '', true), $P('read_time', 'Read time', '', true)]],
            'article_header'  => ['label' => 'Article header', 'items' => [$P('section', 'Section'), $P('section_href', 'Section link'), $P('title', 'Article title'), $P('standfirst', 'Standfirst'), $P('date', 'Date'), $P('read_time', 'Read time (minutes)'), $P('meta', 'Date · read time (combined)')]],
            'article_hero'    => ['label' => 'Article hero image', 'items' => [$P('image', 'Image URL'), $P('title', 'Alt text / title')]],
            'article_body'    => ['label' => 'Article body', 'items' => [$P('body', 'Article body HTML')]],
            'article_byline'  => ['label' => 'Byline', 'items' => [$P('name', 'Author name'), $P('role', 'Author role'), $P('photo', 'Author photo'), $P('href', 'Author link'), $P('email', 'Email'), $P('linkedin_url', 'LinkedIn'), $P('byline', 'Byline text'), $P('read_all_label', '“Read all by…” label')]],
            'article_related' => ['label' => 'Related articles', 'items' => [$P('heading', 'Heading'), $P('title', 'Card title', '', true), $P('href', 'Card link', '', true), $P('image', 'Card image', '', true), $P('section', 'Card section', '', true), $P('meta', 'Card meta', '', true)]],
            'article_quickreads' => ['label' => 'Quick reads', 'items' => [$P('heading', 'Heading'), $P('title', 'Card title', '', true), $P('href', 'Card link', '', true), $P('section', 'Card section', '', true), $P('meta', 'Card meta', '', true)]],
            'article_comments' => ['label' => 'Comments', 'items' => [$P('heading', 'Heading'), $P('note', 'Note'), $P('placeholder', 'Input placeholder'), $P('guest_label', 'Guest label'), $P('button_label', 'Button label')]],
            'author_profile'  => ['label' => 'Author profile', 'items' => [$P('name', 'Name'), $P('role', 'Role'), $P('photo', 'Photo'), $P('crumb_label', 'Breadcrumb label'), $P('x_url', 'X / Twitter'), $P('linkedin_url', 'LinkedIn'), $P('email', 'Email'), $P('value', 'Bio paragraph', '', true)]],
            'author_articles' => ['label' => 'Author article list', 'items' => [$P('heading', 'Heading'), $P('title', 'Article title', '', true), $P('href', 'Article link', '', true), $P('standfirst', 'Standfirst', '', true), $P('section', 'Section', '', true), $P('image', 'Image', '', true), $P('meta', 'Meta', '', true)]],
            'footer'          => ['label' => 'Footer', 'items' => [$P('site_name', 'Publication name'), $P('blurb', 'Blurb'), $P('col1_label', 'Column 1 label'), $P('col2_label', 'Column 2 label'), $P('col3_label', 'Column 3 label'), $P('copyright', 'Copyright line'), $P('facebook', 'Facebook URL'), $P('twitter', 'X URL'), $P('instagram', 'Instagram URL'), $P('name', 'Link name', '', true), $P('url', 'Link URL', '', true)]],
            'about_page'      => ['label' => 'About page', 'items' => [$P('site_name', 'Publication name'), $P('contact_href', 'Contact link'), $P('subscribe_href', 'Subscribe link')]],
            'contact_page'    => ['label' => 'Contact page', 'items' => [$P('site_name', 'Publication name'), $P('action', 'Form action URL')]],
            'search_results'  => ['label' => 'Search results', 'items' => [$P('q', 'Query'), $P('action', 'Search URL'), $P('placeholder', 'Input placeholder'), $P('count_label', 'Result count label'), $P('title', 'Result title', '', true), $P('href', 'Result link', '', true), $P('teaser', 'Result teaser', '', true), $P('date', 'Result date', '', true)]],
        ],
    ];
}
