# Per-Publication Design System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A universal, config-driven `/design/` page engine (identical on every site) whose per-publication layout, theme, spacing, blocks, and mobile variant are stored in the DB and edited from a new Design modal in TEN News Sites — first target The Paris Eye at `thepariseye.com/design/`.

**Architecture:** Three parts joined only by the shared DB. (A) A data layer + two tables in the shared DB. (B) A universal `/design/` renderer in the site-template docroot (deployed per site) that reads config and renders themed blocks. (C) A Design editor modal in the management app that writes config, publishes versioned snapshots, and rolls back. The editor writes; the renderer reads; there is no code coupling between the two codebases.

**Tech Stack:** PHP (legacy style matching the existing app; PDO via the `Core` singleton in `global_include.php`), vanilla JS, Apache `.htaccess`, MySQL/MariaDB, Google Fonts. No framework, no build step.

## Global Constraints

- **Universal `/design/` code:** every file under `/design/` except `/design/site/*` must be byte-identical across all sites. **No literal domains** — use `%{HTTP_HOST}` in `.htaccess` and `BASE_WEBSITE_PATH` / `$_SERVER['HTTP_HOST']` in PHP. Site identity comes only from `BASE_SITE_ABBREVIATION` (set by the per-site private folder).
- **Per-site edges only:** private folder (already exists), `/design/.htaccess` (host-agnostic, copy-once), `/design/site/` branding assets (`logo.png`, `logo-mobile.png`, `favicon.ico`, `og-default.jpg`).
- **Feature flag:** engine and editor act only for publications with `publications.design_enabled = 1`. During development that is **TPE only**. The renderer returns 404 for non-enabled pubs.
- **DB is the only seam.** No shared PHP includes between the management repo and the `/design/` engine. Small pure helpers (mobile-diff merge, registry allow-list) are intentionally duplicated in both, kept in sync by copying `design_registry.php`.
- **Two locations:**
  - Management repo (git, this working dir): `F:\Work\Websites\theeyenewspapers_website_template\management\` — data layer, migration/seed scripts, editor.
  - Site template (NOT git): `F:\Work\Websites\theeyenewspapers_website_template\` — the `/design/` engine, deployed by William to `thepariseye.com/design/`.
- **Verification:** no local PHP and no unit-test runner. Pure logic is checked by **web-run self-test scripts** that print `PASS`/`FAIL`; rendered pages and the modal are checked **in the browser** (built-in or Brave) after William deploys `/design/`. Never claim a rendered page or migration "works" until it has been loaded in a browser / run against the DB.
- **Match existing patterns:** AJAX endpoints do `require_once '../config.php'; requireLogin();`, dispatch on `entity.action`, gate writes with `isAdmin()`, call `logActivity(...)`, and return `json_encode(['success'=>bool, ...])`. Migration scripts are web-run PHP at repo root like `run_breaking_news_migration.php`.
- **DB ACCESS CORRECTION (supersedes the PDO shown in Phase A/C code below):** The `publications` table lives in the **`admin_ten`** database, reached from the management app via **`getDBConnection_TENAdmin()`** (mysqli), exactly as `scraper/lib/news_sites_db.php` does. All management-side design code (migration, `lib/design_layouts_db.php`, seed, `ajax/design_layout.php`) uses **mysqli against `admin_ten`**, NOT PDO `Core`. The new tables (`ten_design_layouts`, `ten_design_versions`) and the `publications.design_enabled` column are created **in `admin_ten`**, so the site renderer's PDO `Core` (which shares that database — it already reads `publications.breaking_news_enabled`) can read them. Only the Phase B `/design/` engine uses PDO `Core`. **Checkpoint to confirm at migration time:** verify the site's `Core` connection sees the new `admin_ten` tables.

---

## File Structure

**Management repo (git):**
- Create `run_design_system_migration.php` — creates tables + column.
- Create `lib/design_registry.php` — block allow-list, valid page types, default settings, `dl_merge_mobile()`, `dl_validate_layout()`. **Master copy** (also copied into `/design/lib/`).
- Create `lib/design_layouts_db.php` — draft CRUD + version snapshot/publish/rollback + seed.
- Create `seed_tpe_design.php` — seeds TPE's five pages (desktop + mobile diff), enables TPE, publishes v1.
- Create `test_design_registry.php` — web-run self-test for the pure helpers.
- Create `ajax/design_layout.php` — load / save_draft / publish / list_versions / rollback.
- Create `js/news_sites_design.js`, `css/news_sites_design.css` — the modal.
- Modify `module-news.php` — include modal markup + CSS/JS.
- Modify `js/news_sites.js` — add the per-row **Design** button + open handler.

**Site template (deployed to each site as `/design/`):**
- Create `design/.htaccess`, `design/index.php`, `design/router.php`
- Create `design/lib/bootstrap.php`, `design/lib/config.php`, `design/lib/theme.php`, `design/lib/registry.php`, `design/lib/data.php`, `design/lib/design_registry.php` (copy of the master)
- Create `design/blocks/*.php` (17 blocks — see Phase B)
- Create `design/assets/design.css`, `design/assets/design.js`
- Create `design/site/` with placeholder `logo.png`, `logo-mobile.png`, `favicon.ico`, `og-default.jpg` and a `README.txt` explaining the fixed names.

---

## Phase A — Schema & data layer (management repo)

### Task A1: DB migration

**Files:**
- Create: `run_design_system_migration.php`

**Interfaces:**
- Produces: tables `ten_design_layouts`, `ten_design_versions`; column `publications.design_enabled TINYINT(1) DEFAULT 0`.

- [ ] **Step 1: Write the migration script**

```php
<?php
// Web-run once: browse to /management/run_design_system_migration.php
require_once __DIR__ . '/config.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }
$pdo = Core::getInstance()->dbh;
$done = [];

$pdo->exec("CREATE TABLE IF NOT EXISTS ten_design_layouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  publication VARCHAR(16) NOT NULL,
  page_type VARCHAR(32) NOT NULL,
  device ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
  layout_json MEDIUMTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(64) NULL,
  UNIQUE KEY uniq_pub_page_device (publication, page_type, device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$done[] = 'ten_design_layouts';

$pdo->exec("CREATE TABLE IF NOT EXISTS ten_design_versions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$done[] = 'ten_design_versions';

// Add column if missing (information_schema check — no error if it exists)
$col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='publications' AND COLUMN_NAME='design_enabled'")->fetchColumn();
if ((int)$col === 0) {
  $pdo->exec("ALTER TABLE publications ADD COLUMN design_enabled TINYINT(1) NOT NULL DEFAULT 0");
  $done[] = 'publications.design_enabled (added)';
} else {
  $done[] = 'publications.design_enabled (already present)';
}

echo "Design system migration complete:<br>" . implode('<br>', array_map('htmlspecialchars', $done));
```

- [ ] **Step 2: Run & verify**

William browses to `/management/run_design_system_migration.php` while logged in as admin. Expected: page prints the three lines. Then confirm in the DB: `SHOW TABLES LIKE 'ten_design_%';` returns two rows and `SHOW COLUMNS FROM publications LIKE 'design_enabled';` returns one row.

- [ ] **Step 3: Commit**

```bash
git add run_design_system_migration.php
git commit -m "feat(design-system): DB migration for layouts, versions, design_enabled"
```

---

### Task A2: Block registry + pure helpers (master copy)

**Files:**
- Create: `lib/design_registry.php`
- Test: `test_design_registry.php`

**Interfaces:**
- Produces:
  - `dl_registry(): array` — `['block_type' => ['label'=>..., 'pages'=>['front',...], 'defaults'=>[...]]]`
  - `dl_page_types(): array` — `['front','section','article','impressum','contact']`
  - `dl_block_allowed(string $type, string $page): bool`
  - `dl_default_theme(): array` — the token defaults (fonts, colours, spacing)
  - `dl_validate_layout(array $layout, string $page): array` — returns `['ok'=>bool,'errors'=>[]]`; strips unknown block types, ensures each block has `type`+`settings`.
  - `dl_merge_mobile(array $desktop, array $mobileDiff): array` — applies `hidden`/`order`/per-block `settings`/`theme` overrides; returns a full resolved layout.

- [ ] **Step 1: Write `lib/design_registry.php`**

```php
<?php
// Pure, dependency-free. MASTER COPY. Also copied verbatim to /design/lib/design_registry.php.

function dl_page_types(): array {
    return ['front','section','article','impressum','contact'];
}

function dl_default_theme(): array {
    return [
        'font_display' => '"Playfair Display", Georgia, serif',
        'font_body'    => '"Lora", Georgia, serif',
        'font_ui'      => '"Inter", system-ui, sans-serif',
        'color_ink'    => '#14142b',
        'color_body'   => '#333333',
        'color_muted'  => '#6b6b6b',
        'color_accent' => '#1a2b4a',
        'color_bg'     => '#ffffff',
        'color_button' => '#14142b',
        'container_max'=> '1200px',
        'gutter'       => '24px',
        'section_gap'  => '48px',
        'block_gap'    => '24px',
    ];
}

function dl_registry(): array {
    $all = dl_page_types();
    return [
        'site_header'     => ['label'=>'Site header',      'pages'=>$all, 'defaults'=>['show_search'=>true,'show_subscribe'=>true]],
        'masthead'        => ['label'=>'Masthead',         'pages'=>['front'], 'defaults'=>['tagline'=>'']],
        'front_feature'   => ['label'=>'Front feature',    'pages'=>['front'], 'defaults'=>['hero_source'=>'latest','lead_source'=>'latest','sidebar_count'=>5]],
        'headlines_list'  => ['label'=>'Latest headlines', 'pages'=>['front','section'], 'defaults'=>['count'=>5,'show_readtime'=>true]],
        'section_title'   => ['label'=>'Section title',    'pages'=>['section'], 'defaults'=>['uppercase'=>true]],
        'section_lead'    => ['label'=>'Section lead',     'pages'=>['section'], 'defaults'=>[]],
        'article_grid'    => ['label'=>'Article grid',     'pages'=>['section'], 'defaults'=>['columns'=>3,'per_page'=>12]],
        'article_header'  => ['label'=>'Article header',   'pages'=>['article'], 'defaults'=>[]],
        'article_hero'    => ['label'=>'Article hero image','pages'=>['article'], 'defaults'=>['ratio'=>'16x9']],
        'article_body'    => ['label'=>'Article body',     'pages'=>['article'], 'defaults'=>[]],
        'article_byline'  => ['label'=>'Byline',           'pages'=>['article'], 'defaults'=>[]],
        'article_comments'=> ['label'=>'Comments',         'pages'=>['article'], 'defaults'=>['enabled'=>true]],
        'section_carousel'=> ['label'=>'Section carousel', 'pages'=>['front','section','article'], 'defaults'=>['section'=>'','count'=>10]],
        'advert'          => ['label'=>'Advert slot',      'pages'=>['front','section','article'], 'defaults'=>['slot'=>'']],
        'ticker'          => ['label'=>'News ticker',      'pages'=>['front'], 'defaults'=>[]],
        'breaking_news'   => ['label'=>'Breaking news',    'pages'=>['front'], 'defaults'=>[]],
        'rich_text'       => ['label'=>'Rich text',        'pages'=>['impressum','contact'], 'defaults'=>['content_key'=>'']],
        'footer'          => ['label'=>'Footer',           'pages'=>$all, 'defaults'=>[]],
    ];
}

function dl_block_allowed(string $type, string $page): bool {
    $r = dl_registry();
    return isset($r[$type]) && in_array($page, $r[$type]['pages'], true);
}

function dl_validate_layout(array $layout, string $page): array {
    $errors = [];
    if (!isset($layout['blocks']) || !is_array($layout['blocks'])) {
        return ['ok'=>false, 'errors'=>['missing blocks array'], 'layout'=>['theme'=>dl_default_theme(),'blocks'=>[]]];
    }
    $clean = [];
    foreach ($layout['blocks'] as $b) {
        if (!isset($b['type']) || !dl_block_allowed($b['type'], $page)) {
            $errors[] = 'dropped invalid block: ' . ($b['type'] ?? '?');
            continue;
        }
        $clean[] = ['type'=>$b['type'], 'settings'=>isset($b['settings']) && is_array($b['settings']) ? $b['settings'] : []];
    }
    $theme = array_merge(dl_default_theme(), isset($layout['theme']) && is_array($layout['theme']) ? $layout['theme'] : []);
    return ['ok'=>empty($errors), 'errors'=>$errors, 'layout'=>['theme'=>$theme, 'blocks'=>$clean]];
}

function dl_merge_mobile(array $desktop, array $mobileDiff): array {
    $blocks = $desktop['blocks'] ?? [];
    $hidden = $mobileDiff['hidden'] ?? [];
    if ($hidden) {
        $blocks = array_values(array_filter($blocks, function($b) use ($hidden) {
            return !in_array($b['type'], $hidden, true);
        }));
    }
    // per-block settings overrides keyed by block type
    if (!empty($mobileDiff['settings']) && is_array($mobileDiff['settings'])) {
        foreach ($blocks as &$b) {
            if (isset($mobileDiff['settings'][$b['type']])) {
                $b['settings'] = array_merge($b['settings'], $mobileDiff['settings'][$b['type']]);
            }
        }
        unset($b);
    }
    // optional reorder by list of types
    if (!empty($mobileDiff['order']) && is_array($mobileDiff['order'])) {
        $byType = [];
        foreach ($blocks as $b) { $byType[$b['type']][] = $b; }
        $ordered = [];
        foreach ($mobileDiff['order'] as $t) {
            if (!empty($byType[$t])) { $ordered[] = array_shift($byType[$t]); }
        }
        foreach ($byType as $rest) { foreach ($rest as $b) { $ordered[] = $b; } }
        if ($ordered) { $blocks = $ordered; }
    }
    $theme = array_merge($desktop['theme'] ?? dl_default_theme(), $mobileDiff['theme'] ?? []);
    return ['theme'=>$theme, 'blocks'=>$blocks];
}
```

- [ ] **Step 2: Write `test_design_registry.php` (web-run self-test)**

```php
<?php
require_once __DIR__ . '/lib/design_registry.php';
$fail = 0;
function ck($cond, $msg) { global $fail; echo ($cond?'PASS':'FAIL')." — $msg<br>"; if(!$cond)$fail++; }

ck(dl_block_allowed('masthead','front') === true, 'masthead allowed on front');
ck(dl_block_allowed('masthead','article') === false, 'masthead not on article');

$v = dl_validate_layout(['blocks'=>[['type'=>'masthead'],['type'=>'nope']]], 'front');
ck($v['ok'] === false, 'validate flags invalid block');
ck(count($v['layout']['blocks']) === 1, 'invalid block stripped');
ck(isset($v['layout']['theme']['color_ink']), 'theme defaults filled');

$desktop = ['theme'=>dl_default_theme(), 'blocks'=>[
  ['type'=>'site_header','settings'=>[]],
  ['type'=>'section_carousel','settings'=>['count'=>10]],
  ['type'=>'footer','settings'=>[]],
]];
$m = dl_merge_mobile($desktop, ['hidden'=>['section_carousel'], 'settings'=>['footer'=>['compact'=>true]]]);
ck(count($m['blocks']) === 2, 'mobile hides carousel');
ck($m['blocks'][1]['settings']['compact'] === true, 'mobile per-block override applied');

echo $fail? "<b>$fail FAILED</b>" : "<b>ALL PASS</b>";
```

- [ ] **Step 3: Run & verify**

Browse to `/management/test_design_registry.php`. Expected: `ALL PASS`.

- [ ] **Step 4: Commit**

```bash
git add lib/design_registry.php test_design_registry.php
git commit -m "feat(design-system): block registry + mobile-merge/validate helpers with self-test"
```

---

### Task A3: Draft CRUD + versions (publish/rollback)

**Files:**
- Create: `lib/design_layouts_db.php`

**Interfaces:**
- Consumes: `Core::getInstance()->dbh`, `lib/design_registry.php`.
- Produces:
  - `dl_load_draft(string $pub, string $page, string $device='desktop'): ?array` — decoded `layout_json` or `null`.
  - `dl_save_draft(string $pub, string $page, string $device, array $layout, ?string $user): void` — validates then upserts.
  - `dl_seed_defaults(string $pub, array $pages): void` — writes provided page layouts as drafts (used by seed script).
  - `dl_all_drafts(string $pub): array` — `[page][device] => layout` bundle.
  - `dl_publish(string $pub, ?string $label, ?string $user): int` — snapshots all drafts to a new live version_no.
  - `dl_list_versions(string $pub): array` — rows (id, version_no, label, is_live, created_at, created_by).
  - `dl_live_snapshot(string $pub): ?array` — decoded snapshot bundle where `is_live=1`.
  - `dl_rollback(string $pub, int $version_no, ?string $user): void` — set that version live + restore its bundle into drafts.

- [ ] **Step 1: Write `lib/design_layouts_db.php`**

```php
<?php
require_once __DIR__ . '/design_registry.php';

function dl_load_draft(string $pub, string $page, string $device='desktop'): ?array {
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT layout_json FROM ten_design_layouts WHERE publication=? AND page_type=? AND device=? LIMIT 1");
    $st->execute([$pub,$page,$device]);
    $j = $st->fetchColumn();
    return $j ? json_decode($j, true) : null;
}

function dl_save_draft(string $pub, string $page, string $device, array $layout, ?string $user): void {
    // desktop is a full layout (validated); mobile is a diff (stored as-is under a wrapper)
    if ($device === 'desktop') {
        $v = dl_validate_layout($layout, $page);
        $store = $v['layout'];
    } else {
        $store = $layout; // {hidden,order,settings,theme}
    }
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("INSERT INTO ten_design_layouts (publication,page_type,device,layout_json,updated_by)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE layout_json=VALUES(layout_json), updated_by=VALUES(updated_by)");
    $st->execute([$pub,$page,$device, json_encode($store), $user]);
}

function dl_seed_defaults(string $pub, array $pages): void {
    // $pages = [ page => [ 'desktop'=>layout, 'mobile'=>diff ] ]
    foreach ($pages as $page => $devices) {
        foreach ($devices as $device => $layout) {
            dl_save_draft($pub, $page, $device, $layout, 'seed');
        }
    }
}

function dl_all_drafts(string $pub): array {
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT page_type,device,layout_json FROM ten_design_layouts WHERE publication=?");
    $st->execute([$pub]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['page_type']][$r['device']] = json_decode($r['layout_json'], true);
    }
    return $out;
}

function dl_publish(string $pub, ?string $label, ?string $user): int {
    $pdo = Core::getInstance()->dbh;
    $bundle = dl_all_drafts($pub);
    $pdo->beginTransaction();
    try {
        $next = (int)$pdo->query("SELECT COALESCE(MAX(version_no),0)+1 FROM ten_design_versions
            WHERE publication=" . $pdo->quote($pub))->fetchColumn();
        $pdo->prepare("UPDATE ten_design_versions SET is_live=0 WHERE publication=?")->execute([$pub]);
        $ins = $pdo->prepare("INSERT INTO ten_design_versions (publication,version_no,label,snapshot_json,is_live,created_by)
            VALUES (?,?,?,?,1,?)");
        $ins->execute([$pub,$next,$label, json_encode($bundle), $user]);
        $pdo->commit();
        return $next;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function dl_list_versions(string $pub): array {
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT id,version_no,label,is_live,created_at,created_by
        FROM ten_design_versions WHERE publication=? ORDER BY version_no DESC");
    $st->execute([$pub]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function dl_live_snapshot(string $pub): ?array {
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND is_live=1 LIMIT 1");
    $st->execute([$pub]);
    $j = $st->fetchColumn();
    return $j ? json_decode($j, true) : null;
}

function dl_rollback(string $pub, int $version_no, ?string $user): void {
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND version_no=? LIMIT 1");
    $st->execute([$pub,$version_no]);
    $j = $st->fetchColumn();
    if (!$j) { throw new RuntimeException("version $version_no not found"); }
    $bundle = json_decode($j, true);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ten_design_versions SET is_live=0 WHERE publication=?")->execute([$pub]);
        $pdo->prepare("UPDATE ten_design_versions SET is_live=1 WHERE publication=? AND version_no=?")->execute([$pub,$version_no]);
        foreach ($bundle as $page => $devices) {
            foreach ($devices as $device => $layout) {
                dl_save_draft($pub, $page, $device, $layout, $user ?? 'rollback');
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
```

- [ ] **Step 2: Verify (deferred to A4)** — exercised by the seed script and the AJAX endpoint; no standalone test (DB-bound).

- [ ] **Step 3: Commit**

```bash
git add lib/design_layouts_db.php
git commit -m "feat(design-system): draft CRUD + versioned publish/rollback data layer"
```

---

### Task A4: Seed The Paris Eye design + enable flag + publish v1

**Files:**
- Create: `seed_tpe_design.php`

**Interfaces:**
- Consumes: `dl_seed_defaults`, `dl_publish`, `dl_default_theme`.
- Produces: TPE rows in `ten_design_layouts` (5 pages × desktop + mobile diff), `publications.design_enabled=1` for TPE, live v1 in `ten_design_versions`.

- [ ] **Step 1: Write `seed_tpe_design.php`**

Build the five page layouts from the Figma (theme = `dl_default_theme()` with TPE tagline). Front = `[site_header, masthead(tagline), front_feature, section_carousel, footer]`; section = `[site_header, section_title, section_lead, headlines_list, article_grid, footer]`; article = `[site_header, article_header, article_hero, article_body, article_byline, article_comments, footer]`; impressum/contact = `[site_header, rich_text, footer]`. Mobile diffs hide `section_carousel`/`article_grid` heavy items and tighten gutters.

```php
<?php
require_once __DIR__ . '/config.php';
requireLogin();
if (!isAdmin()) { die('Admin only'); }
require_once __DIR__ . '/lib/design_layouts_db.php';

$PUB = 'TPE';
$theme = dl_default_theme();
$theme_front = array_merge($theme, []); // room for per-page theme tweaks

$pages = [
  'front' => [
    'desktop' => ['theme'=>$theme, 'blocks'=>[
      ['type'=>'site_header','settings'=>['show_search'=>true,'show_subscribe'=>true]],
      ['type'=>'masthead','settings'=>['tagline'=>'Independent journalism for the international community in France']],
      ['type'=>'front_feature','settings'=>['hero_source'=>'latest','lead_source'=>'latest','sidebar_count'=>5]],
      ['type'=>'section_carousel','settings'=>['section'=>'culture','count'=>10]],
      ['type'=>'footer','settings'=>[]],
    ]],
    'mobile' => ['hidden'=>['section_carousel'], 'theme'=>['gutter'=>'16px','section_gap'=>'32px']],
  ],
  'section' => [
    'desktop' => ['theme'=>$theme, 'blocks'=>[
      ['type'=>'site_header','settings'=>[]],
      ['type'=>'section_title','settings'=>['uppercase'=>true]],
      ['type'=>'section_lead','settings'=>[]],
      ['type'=>'headlines_list','settings'=>['count'=>5,'show_readtime'=>true]],
      ['type'=>'article_grid','settings'=>['columns'=>3,'per_page'=>12]],
      ['type'=>'footer','settings'=>[]],
    ]],
    'mobile' => ['hidden'=>[], 'theme'=>['gutter'=>'16px']],
  ],
  'article' => [
    'desktop' => ['theme'=>$theme, 'blocks'=>[
      ['type'=>'site_header','settings'=>[]],
      ['type'=>'article_header','settings'=>[]],
      ['type'=>'article_hero','settings'=>['ratio'=>'16x9']],
      ['type'=>'article_body','settings'=>[]],
      ['type'=>'article_byline','settings'=>[]],
      ['type'=>'article_comments','settings'=>['enabled'=>true]],
      ['type'=>'footer','settings'=>[]],
    ]],
    'mobile' => ['hidden'=>[], 'theme'=>['gutter'=>'16px']],
  ],
  'impressum' => [
    'desktop' => ['theme'=>$theme, 'blocks'=>[
      ['type'=>'site_header','settings'=>[]],
      ['type'=>'rich_text','settings'=>['content_key'=>'impressum']],
      ['type'=>'footer','settings'=>[]],
    ]],
    'mobile' => ['hidden'=>[], 'theme'=>['gutter'=>'16px']],
  ],
  'contact' => [
    'desktop' => ['theme'=>$theme, 'blocks'=>[
      ['type'=>'site_header','settings'=>[]],
      ['type'=>'rich_text','settings'=>['content_key'=>'contact']],
      ['type'=>'footer','settings'=>[]],
    ]],
    'mobile' => ['hidden'=>[], 'theme'=>['gutter'=>'16px']],
  ],
];

dl_seed_defaults($PUB, $pages);
$pdo = Core::getInstance()->dbh;
$pdo->prepare("UPDATE publications SET design_enabled=1 WHERE publication=?")->execute([$PUB]);
$v = dl_publish($PUB, 'Initial Paris Eye design (Figma)', 'seed');
echo "Seeded TPE, enabled design, published v$v.";
```

- [ ] **Step 2: Run & verify**

Browse to `/management/seed_tpe_design.php` (admin). Expected: `Seeded TPE, enabled design, published v1.` Confirm: `SELECT page_type,device FROM ten_design_layouts WHERE publication='TPE';` → 10 rows; `SELECT version_no,is_live FROM ten_design_versions WHERE publication='TPE';` → `1,1`; `SELECT design_enabled FROM publications WHERE publication='TPE';` → `1`.

- [ ] **Step 3: Commit**

```bash
git add seed_tpe_design.php
git commit -m "feat(design-system): seed The Paris Eye layouts, enable flag, publish v1"
```

---

## Phase B — The `/design/` renderer (site template; deploy to `thepariseye.com/design/`)

> All Phase B files live under `F:\Work\Websites\theeyenewspapers_website_template\design\`. They are NOT in the management git repo. After each task, William deploys the folder to `thepariseye.com/design/` and we verify in the browser. Commit note: these are tracked informally (zip/rsync); no git commit step.

### Task B1: Bootstrap, routing, feature-flag guard

**Files:**
- Create: `design/.htaccess`, `design/index.php`, `design/router.php`, `design/lib/bootstrap.php`

**Interfaces:**
- Produces: `dl_boot(): array` returns `['pub'=>abbr, 'live'=>bool]` or exits 404; `dl_route(string $uri): array` returns `['page'=>..., 'params'=>[...]]`.

- [ ] **Step 1: `design/.htaccess` (host-agnostic)**

```apache
RewriteEngine On
RewriteBase /design/
# Route everything that is not a real file/dir through index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

- [ ] **Step 2: `design/lib/bootstrap.php`**

```php
<?php
// Universal. Site identity comes from global_include (private folder env).
require_once $_SERVER['DOCUMENT_ROOT'] . '/global_include.php';
require_once __DIR__ . '/design_registry.php'; // copied master

function dl_boot(): array {
    $pub = defined('BASE_SITE_ABBREVIATION') ? BASE_SITE_ABBREVIATION : '';
    $pdo = Core::getInstance()->dbh;
    $st = $pdo->prepare("SELECT design_enabled FROM publications WHERE publication=? LIMIT 1");
    $st->execute([$pub]);
    if ((int)$st->fetchColumn() !== 1) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $live = !(isset($_GET['preview']) && $_GET['preview'] == '1');
    return ['pub'=>$pub, 'live'=>$live];
}
```

- [ ] **Step 3: `design/router.php`**

```php
<?php
function dl_route(string $uri): array {
    $path = trim(parse_url($uri, PHP_URL_PATH), '/');       // e.g. "design/culture"
    $path = preg_replace('#^design/?#', '', $path);          // strip base
    if ($path === '' ) return ['page'=>'front','params'=>[]];
    if ($path === 'impressum') return ['page'=>'impressum','params'=>[]];
    if ($path === 'contact')   return ['page'=>'contact','params'=>[]];
    if (preg_match('#^(.+)-(\d{1,10})$#', $path, $m)) {
        return ['page'=>'article','params'=>['id'=>(int)$m[2],'slug'=>$m[1]]];
    }
    return ['page'=>'section','params'=>['section'=>$path]];
}
```

- [ ] **Step 4: `design/index.php` (skeleton)**

```php
<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/router.php';
$ctx = dl_boot();
$route = dl_route($_SERVER['REQUEST_URI']);
echo '<!doctype html><meta charset="utf-8"><h1>' . htmlspecialchars(BASE_SITE_NAME) .
     ' — design engine</h1><p>page: ' . htmlspecialchars($route['page']) .
     ' · mode: ' . ($ctx['live']?'live':'preview') . '</p>';
```

- [ ] **Step 5: Deploy & verify**

William deploys `design/` to `thepariseye.com/design/`. In the browser: `thepariseye.com/design/` → shows "The Paris Eye — design engine · page: front · mode: live". `.../design/culture` → page: section. `.../design/foo-123` → page: article. A non-enabled site's `/design/` → 404.

---

### Task B2: Config load, theme tokens, block dispatch loop

**Files:**
- Create: `design/lib/config.php`, `design/lib/theme.php`, `design/lib/registry.php`, `design/lib/design_registry.php` (copy master), `design/lib/data.php` (empty stubs for now)
- Modify: `design/index.php`

**Interfaces:**
- Consumes: bootstrap ctx, route.
- Produces:
  - `dl_resolve(array $ctx, string $page, string $device): array` → resolved `['theme'=>..,'blocks'=>..]` (draft or live, mobile-merged).
  - `dl_theme_css(array $theme): string` → `:root{ --font-display:..; --gutter:..; }`.
  - `dl_render_blocks(array $blocks, array $params, array $theme): string`.

- [ ] **Step 1: `design/lib/config.php`**

```php
<?php
require_once __DIR__ . '/design_registry.php';

function dl_resolve(array $ctx, string $page, string $device): array {
    $pub = $ctx['pub'];
    $pdo = Core::getInstance()->dbh;
    if ($ctx['live']) {
        // live snapshot bundle
        $st = $pdo->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND is_live=1 LIMIT 1");
        $st->execute([$pub]);
        $bundle = json_decode((string)$st->fetchColumn(), true) ?: [];
        $desktop = $bundle[$page]['desktop'] ?? ['theme'=>dl_default_theme(),'blocks'=>[]];
        $mobile  = $bundle[$page]['mobile'] ?? [];
    } else {
        $st = $pdo->prepare("SELECT device,layout_json FROM ten_design_layouts WHERE publication=? AND page_type=?");
        $st->execute([$pub,$page]);
        $desktop = ['theme'=>dl_default_theme(),'blocks'=>[]]; $mobile = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['device']==='desktop') $desktop = json_decode($r['layout_json'], true);
            else $mobile = json_decode($r['layout_json'], true);
        }
    }
    return $device === 'mobile' ? dl_merge_mobile($desktop, $mobile) : $desktop;
}

function dl_device(): string {
    // simple UA sniff; CSS still handles most responsiveness
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop';
}
```

- [ ] **Step 2: `design/lib/theme.php`**

```php
<?php
function dl_theme_css(array $theme): string {
    $map = [
        'font_display'=>'--font-display','font_body'=>'--font-body','font_ui'=>'--font-ui',
        'color_ink'=>'--color-ink','color_body'=>'--color-body','color_muted'=>'--color-muted',
        'color_accent'=>'--color-accent','color_bg'=>'--color-bg','color_button'=>'--color-button',
        'container_max'=>'--container-max','gutter'=>'--gutter','section_gap'=>'--section-gap','block_gap'=>'--block-gap',
    ];
    $out = ':root{';
    foreach ($map as $k=>$var) { if (isset($theme[$k])) { $out .= $var.':'.$theme[$k].';'; } }
    return $out . '}';
}
```

- [ ] **Step 3: `design/lib/registry.php`**

```php
<?php
function dl_render_blocks(array $blocks, array $params, array $theme): string {
    $html = '';
    foreach ($blocks as $b) {
        $file = __DIR__ . '/../blocks/' . basename($b['type']) . '.php';
        if (!is_file($file)) { continue; }
        $settings = $b['settings'] ?? [];
        $spacing = $settings['spacing'] ?? null;
        $style = '';
        if ($spacing) {
            if (!empty($spacing['margin_top']))    $style .= 'margin-top:'.$spacing['margin_top'].';';
            if (!empty($spacing['margin_bottom'])) $style .= 'margin-bottom:'.$spacing['margin_bottom'].';';
            if (!empty($spacing['padding']))       $style .= 'padding:'.$spacing['padding'].';';
        }
        $inner = (function() use ($file,$settings,$params,$theme){ ob_start(); include $file; return ob_get_clean(); })();
        $html .= '<div class="dl-block dl-'.htmlspecialchars($b['type']).'"'.($style?' style="'.$style.'"':'').'>'.$inner.'</div>';
    }
    return $html;
}
```

Each block file reads `$settings`, `$params`, `$theme` (in scope) and echoes HTML.

- [ ] **Step 4: Rewrite `design/index.php` to assemble the page**

```php
<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/theme.php';
require_once __DIR__ . '/lib/registry.php';
require_once __DIR__ . '/lib/data.php';

$ctx = dl_boot();
$route = dl_route($_SERVER['REQUEST_URI']);
$device = dl_device();
$layout = dl_resolve($ctx, $route['page'], $device);
$theme = $layout['theme'] ?? dl_default_theme();
$body = dl_render_blocks($layout['blocks'] ?? [], $route['params'], $theme);
$css = dl_theme_css($theme);
$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '');
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(BASE_SITE_NAME) ?></title>
<link rel="icon" href="/design/site/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Lora:ital@0;1&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/design/assets/design.css">
<style><?= $css ?></style>
</head><body><?= $body ?>
<script src="/design/assets/design.js"></script>
</body></html>
```

- [ ] **Step 5: Deploy & verify** — front page returns HTML with `:root{--font-display...}` in `<head>` and empty block wrappers (blocks are stubs until B4). No PHP errors.

---

### Task B3: Base stylesheet + JS

**Files:**
- Create: `design/assets/design.css`, `design/assets/design.js`

- [ ] **Step 1: `design/assets/design.css`** — mobile-first, token-driven. Include: reset; `body{font-family:var(--font-body);color:var(--color-body);background:var(--color-bg)}`; `.dl-container{max-width:var(--container-max);margin:0 auto;padding:0 var(--gutter)}`; `.dl-block{margin-bottom:var(--section-gap)}`; headings use `var(--font-display)` and `var(--color-ink)`; accent rules use `var(--color-accent)`; a `.dl-subscribe` dark button using `var(--color-button)`; the header/nav; `.dl-carousel` styles (reuse class names from existing `tme_carousel-*` conceptually but namespaced `dl-`); article-centered layout; grid utilities (`.dl-cols-3` via CSS grid); `@media (min-width:768px)` for desktop columns. All colours/fonts/spacing via variables only.

- [ ] **Step 2: `design/assets/design.js`** — carousel prev/next (ported from `insert_section_articles_carousel.php` logic), mobile nav toggle, search toggle. Vanilla, no deps.

- [ ] **Step 3: Deploy & verify** — CSS/JS load (200), no console errors.

---

### Task B4: Front-page blocks

**Files:**
- Create: `design/blocks/site_header.php`, `masthead.php`, `front_feature.php`, `headlines_list.php`
- Create data helpers in `design/lib/data.php`

**Interfaces (data.php):**
- `dl_latest_articles(int $limit): array` — reuse the query from `backend/latest_headlines.php`, filtered by `BASE_SITE_ABBREVIATION`, returns rows (id,title,url,image_url,section,snippet).
- `dl_article_image_url(array $row): string` — port the `year_folders` path logic from `insert_section_articles_carousel.php`.
- `dl_nav_items(): array` — reuse `backend/main_menu.php` source (the `main_menu` table) for nav labels/links.

- [ ] **Step 1: `data.php` helpers** — port the exact SQL from the named backend files; return arrays, no HTML.

- [ ] **Step 2: `site_header.php`** — logo (`/design/site/logo.png`) + serif wordmark (`BASE_SITE_NAME`) + nav (`dl_nav_items()`) + optional search + optional Subscribe button per `$settings`.

- [ ] **Step 3: `masthead.php`** — centered `BASE_SITE_NAME` in display font + italic `$settings['tagline']` + hairline rule.

- [ ] **Step 4: `front_feature.php`** — 3-col grid: hero (first `dl_latest_articles`), lead (next), `headlines_list` sidebar (`sidebar_count`). Matches Figma front layout.

- [ ] **Step 5: `headlines_list.php`** — "LATEST HEADLINES" heading with navy accent rule + list of `count` items; `show_readtime` adds "N min read".

- [ ] **Step 6: Deploy & verify** — `thepariseye.com/design/` visually matches the Figma front page (header, masthead, 3-col). Compare against the Figma screenshots in-browser.

---

### Task B5: Section-page blocks

**Files:**
- Create: `design/blocks/section_title.php`, `section_lead.php`, `article_grid.php`
- Add to `data.php`: `dl_section_articles(string $section, int $limit, int $offset=0): array` (port from `backend/section_articles.php`).

- [ ] **Step 1–3:** Implement the three blocks: `section_title` (big serif uppercase = `params['section']`), `section_lead` (hero+headline+summary of the top section article), `article_grid` (grid of `columns`×`per_page` section articles).
- [ ] **Step 4: Deploy & verify** — `thepariseye.com/design/culture` matches the Figma section page.

---

### Task B6: Article-page blocks (incl. no-image variant)

**Files:**
- Create: `design/blocks/article_header.php`, `article_hero.php`, `article_body.php`, `article_byline.php`, `article_comments.php`
- Add to `data.php`: `dl_article(int $id): ?array` (port single-article query from `backend/insert_full_article.php`; include byline resolution consistent with `ten_users` handling).

- [ ] **Step 1:** `article_header` — centered: grey section pill, display headline, grey serif standfirst, short divider, `date · N min read`.
- [ ] **Step 2:** `article_hero` — full-width hero image; **renders nothing when the article has no image** (the no-image variant); body then flows directly.
- [ ] **Step 3:** `article_body` — justified serif body from the article content.
- [ ] **Step 4:** `article_byline` + `article_comments` — byline block; comments box (respect `enabled`).
- [ ] **Step 5: Deploy & verify** — an article URL with an image matches the image Figma; pick an article with no image (or temporarily blank the image) → matches the no-image Figma.

---

### Task B7: Cross-cutting blocks + impressum/contact

**Files:**
- Create: `design/blocks/section_carousel.php`, `advert.php`, `ticker.php`, `breaking_news.php`, `footer.php`, `rich_text.php`
- Add to `data.php`: helpers ported from `insert_section_articles_carousel.php`, `frontpage_main_article_advert*.php`/`global_advert_insertion.php`, `get_ticker_data.php`, `insert_breaking_news_frontpage.php`, `footer_widget.php`.

- [ ] **Step 1:** `section_carousel` (namespaced `dl-carousel`, JS-driven), `advert` (slot lookup), `ticker`, `breaking_news` (respect `publications.breaking_news_enabled`), `footer` (columned).
- [ ] **Step 2:** `rich_text` — pull impressum/contact content by `content_key` (port from `backend/impressum.php`/`contact.php`).
- [ ] **Step 3: Deploy & verify** — carousel on the front page scrolls; `/design/impressum` and `/design/contact` render.

---

### Task B8: Branding assets + social/meta

**Files:**
- Create: `design/site/README.txt`, placeholder `logo.png`, `logo-mobile.png`, `favicon.ico`, `og-default.jpg`
- Modify: `design/index.php` head (og:image `/design/site/og-default.jpg`, canonical via `HTTP_HOST`).

- [ ] **Step 1:** Add placeholders + README documenting the fixed names and that each site drops its own files here.
- [ ] **Step 2:** Add OpenGraph/canonical meta using `HTTP_HOST` (no literal domain).
- [ ] **Step 3: Deploy & verify** — William replaces `logo.png` with the real Paris Eye eye-badge; header shows it; favicon loads.

---

## Phase C — Design editor modal (management repo)

### Task C1: AJAX endpoint

**Files:**
- Create: `ajax/design_layout.php`
- Test: extend `test_design_registry.php` is not enough (DB) — verify via browser JSON.

**Interfaces:**
- `entity=design`, actions: `load` (pub,page,device,mode) · `save_draft` (pub,page,device,layout_json) · `publish` (pub,label) · `list_versions` (pub) · `rollback` (pub,version_no). JSON `{success,...}`.

- [ ] **Step 1: Write `ajax/design_layout.php`**

```php
<?php
ini_set('display_errors','0');
require_once '../config.php';
require_once __DIR__ . '/../lib/design_layouts_db.php';
requireLogin();
header('Content-Type: application/json');

function dl_pub_enabled(string $pub): bool {
    $st = Core::getInstance()->dbh->prepare("SELECT design_enabled FROM publications WHERE publication=? LIMIT 1");
    $st->execute([$pub]); return (int)$st->fetchColumn() === 1;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pub = trim((string)($_POST['pub'] ?? $_GET['pub'] ?? ''));
$user = $_SESSION['username'] ?? ($_SESSION['user_email'] ?? 'unknown');

try {
    if ($pub === '' || !dl_pub_enabled($pub)) {
        echo json_encode(['success'=>false,'message'=>'Design not enabled for this publication']); exit;
    }
    switch ($action) {
        case 'load':
            $page = (string)($_POST['page'] ?? $_GET['page'] ?? 'front');
            $device = (string)($_POST['device'] ?? $_GET['device'] ?? 'desktop');
            echo json_encode(['success'=>true,'layout'=>dl_load_draft($pub,$page,$device)]);
            break;
        case 'save_draft':
            if (!isAdmin()) { echo json_encode(['success'=>false,'message'=>'Admin required']); exit; }
            $page = (string)$_POST['page']; $device = (string)$_POST['device'];
            $layout = json_decode((string)$_POST['layout_json'], true);
            if (!is_array($layout)) { echo json_encode(['success'=>false,'message'=>'Bad layout_json']); exit; }
            dl_save_draft($pub,$page,$device,$layout,$user);
            logActivity('design_save_draft','publication',0,"draft $pub/$page/$device");
            echo json_encode(['success'=>true]);
            break;
        case 'publish':
            if (!isAdmin()) { echo json_encode(['success'=>false,'message'=>'Admin required']); exit; }
            $v = dl_publish($pub, trim((string)($_POST['label'] ?? '')) ?: null, $user);
            logActivity('design_publish','publication',0,"publish $pub v$v");
            echo json_encode(['success'=>true,'version_no'=>$v]);
            break;
        case 'list_versions':
            echo json_encode(['success'=>true,'versions'=>dl_list_versions($pub)]);
            break;
        case 'rollback':
            if (!isAdmin()) { echo json_encode(['success'=>false,'message'=>'Admin required']); exit; }
            dl_rollback($pub, (int)$_POST['version_no'], $user);
            logActivity('design_rollback','publication',0,"rollback $pub to v".(int)$_POST['version_no']);
            echo json_encode(['success'=>true]);
            break;
        default:
            echo json_encode(['success'=>false,'message'=>"Unknown action: $action"]);
    }
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
```

- [ ] **Step 2: Verify** — logged-in, browse `ajax/design_layout.php?action=list_versions&pub=TPE` → JSON with v1. `?action=load&pub=TPE&page=front` → JSON layout. Non-enabled pub → `Design not enabled`.

- [ ] **Step 3: Commit**

```bash
git add ajax/design_layout.php
git commit -m "feat(design-system): design_layout AJAX endpoint (load/save/publish/rollback)"
```

---

### Task C2: Design button + modal shell

**Files:**
- Modify: `module-news.php` (include CSS/JS + modal container), `js/news_sites.js` (add Design button to each row, gated on `design_enabled`)
- Create: `css/news_sites_design.css`

**Interfaces:**
- Consumes: publication list rows include `design_enabled` (ensure `ns_list_publications()` returns it — modify `scraper/lib/news_sites_db.php` SELECT if needed).
- Produces: `window.openDesignModal(pubKey)` (defined in C3) invoked by the button.

- [ ] **Step 1:** Ensure `ns_list_publications()` includes `design_enabled` in its SELECT.
- [ ] **Step 2:** In `js/news_sites.js` row template (near the Feeds/Edit buttons ~line 70), add:
```js
(p.design_enabled == 1
  ? '<button class="ns-btn small" data-act="design">Design</button> '
  : '<button class="ns-btn small" data-act="design" disabled title="Design not enabled for this site">Design</button> ')
```
and a handler: `card.querySelector('[data-act="design"]').addEventListener('click', function(){ if(!this.disabled) openDesignModal(p.publication); });`
- [ ] **Step 3:** Add modal container markup to `module-news.php` and include `css/news_sites_design.css` + `js/news_sites_design.js`.
- [ ] **Step 4: Verify** — open TEN News Sites; TPE row shows an enabled **Design** button; other rows show it disabled. Clicking TPE opens an (empty) modal.
- [ ] **Step 5: Commit**
```bash
git add module-news.php js/news_sites.js css/news_sites_design.css scraper/lib/news_sites_db.php
git commit -m "feat(design-system): Design button + modal shell in TEN News Sites"
```

---

### Task C3: Editor — page/device tabs, block list, palette, settings, theme+spacing, preview

**Files:**
- Create: `js/news_sites_design.js`

**Interfaces:**
- Consumes: `ajax/design_layout.php`, the registry (mirror the block list + labels + valid pages as a JS const `DL_REGISTRY` kept in sync with `lib/design_registry.php`).
- Produces: `window.openDesignModal(pubKey)`.

- [ ] **Step 1:** Modal scaffold: top bar (page selector = 5 buttons, Desktop|Mobile tabs, Save draft, Publish, History), left block list, centre preview `<iframe src="https://<pub-host>/design/<page>?preview=1">`, right settings panel. (Store the pub's live host; for TPE = `thepariseye.com`.)
- [ ] **Step 2:** `load` the current page+device draft; render the ordered block list with drag-to-reorder (HTML5 drag events), visibility toggle, gear (select → settings), remove.
- [ ] **Step 3:** "+ Add block" palette filtered by `DL_REGISTRY[type].pages.includes(page)`.
- [ ] **Step 4:** Settings panel: per-block fields from `DL_REGISTRY[type].defaults` + a spacing group (margin_top/bottom/padding). With no block selected → theme panel: font inputs (display/body/ui), colour inputs (ink/body/muted/accent/bg/button), spacing (container_max/gutter/section_gap/block_gap). Editing the Mobile tab writes a diff (hidden list from visibility toggles + theme overrides + per-block settings).
- [ ] **Step 5:** Save draft → POST `save_draft` with assembled `layout_json`; on success refresh the preview iframe (`iframe.contentWindow.location.reload()`).
- [ ] **Step 6: Verify** — reorder blocks + change accent colour + gutter on TPE front, Save draft, preview reflects it; switch to Mobile tab, hide the carousel, Save, preview at mobile width omits it.
- [ ] **Step 7: Commit**
```bash
git add js/news_sites_design.js
git commit -m "feat(design-system): design editor — blocks, palette, theme+spacing, preview"
```

---

### Task C4: Publish, history, rollback UI

**Files:**
- Modify: `js/news_sites_design.js`

- [ ] **Step 1:** Publish button → optional label prompt → POST `publish` → toast "Published v{n}".
- [ ] **Step 2:** History panel → `list_versions` → list rows (version_no, created_at, created_by, label, [Live] badge) with Preview (open `/design/<page>?preview=0` — live) and Roll back (confirm → POST `rollback` → reload editor + preview).
- [ ] **Step 3: Verify** — change something, Publish → new version appears live at `thepariseye.com/design/` (no `?preview`); open History, Roll back to v1 → live view reverts and the editor draft matches v1.
- [ ] **Step 4: Commit**
```bash
git add js/news_sites_design.js
git commit -m "feat(design-system): publish, version history & rollback UI"
```

---

## Self-Review

**Spec coverage:** universal code + 3 edges (Global Constraints, B1/B8) ✓; DB seam + two tables (A1) ✓; draft CRUD + versions (A3) ✓; block registry mapped to backend logic (A2, B4–B7) ✓; theme tokens incl. spacing (A2, B2, C3) ✓; mobile-as-diff (A2 `dl_merge_mobile`, C3) ✓; renderer draft/live modes (B2 `dl_resolve`) ✓; feature flag TPE-only (A1, A4, B1, C2) ✓; editor modal with page/device/blocks/palette/theme/spacing/preview (C2–C3) ✓; publish + history + rollback (A3, C1, C4) ✓; Figma design language seeded (A4) + reproduced (B4–B6) ✓; `/design/site/` branding (B8) ✓; host-agnostic `.htaccess` (B1) ✓.

**Placeholder scan:** Blocks in B4–B7 give exact data sources (named backend files to port) + markup intent rather than full code — acceptable because each names its concrete query source and output; no "TBD"/"handle edge cases". CSS/JS (B3) specify exact classes/behaviour to port.

**Type consistency:** `dl_merge_mobile`, `dl_validate_layout`, `dl_default_theme`, `dl_load_draft`, `dl_save_draft`, `dl_all_drafts`, `dl_publish`, `dl_list_versions`, `dl_live_snapshot`, `dl_rollback`, `dl_resolve`, `dl_theme_css`, `dl_render_blocks`, `dl_route`, `dl_boot` — names used consistently across tasks. AJAX actions (`load/save_draft/publish/list_versions/rollback`) match C1↔C3↔C4.

**Note on repo split:** `lib/design_registry.php` (master, git) is copied verbatim to `design/lib/design_registry.php` (deployed). Any change to it must be copied to both — called out in Global Constraints.
