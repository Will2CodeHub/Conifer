# TEN Scraper — Phase 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the scraper database schema and a permission-gated, sidebar-registered `module-scraper.php` shell that lists the two seeded projects (News Collation + Cafe/Restaurant stub) from the live TEN_Management database.

**Architecture:** Standalone PHP page following the existing `module-*.php` pattern, backed by new `ten_scraper_*` tables in the `TEN_Management` database. The sidebar is DB-driven (`ten_module_groups` → `ten_modules`), so a `ten_modules` row plus permission rows are all that's needed to surface the module. No Python yet — this phase is pure scaffolding.

**Tech Stack:** PHP 7/8 (mysqli, prepared statements), MySQL/MariaDB (utf8mb4, InnoDB), FontAwesome icons, existing `config.php` helpers (`requireLogin`, `hasPermission`, `isAdmin`, `getDBConnection`).

## Global Constraints

- New tables are prefixed `ten_scraper_`, live in the `TEN_Management` DB, and are **add-only** (never ALTER/DROP existing tables). — verbatim from spec §2/§3.
- Permission keys use dot notation: **`scraper.use`** (select + promote) and **`scraper.manage`** (configure). Page guard: `hasPermission('scraper.use') || isAdmin()`. — spec §13.
- Match the existing module layout exactly: `require_once 'config.php'; requireLogin();` → guard → DB queries → `<!DOCTYPE html>` with `include 'includes/sidebar.php';` and `include 'includes/header.php';` in the `<body>`.
- Deploy path is **WinSCP FTPS CLI only** — curl FTPS truncates uploads; there is **no SSH**. Test on `/management-staging/` before `/management/`.
- Schema + module/permission rows are applied **directly to the live DB** (via phpMyAdmin/Adminer), add-only.
- Secrets stay in `config.php` (gitignored). Never commit `config.php`.
- Safety posture (spec §2) is carried by the seeded default prompt text — it must instruct original composition, source attribution, and no source images.

## Testing approach for this phase

There is **no local PHP or local MySQL** and the DB is remote, so Phase 1 has **no automated unit tests**. Each task's verification is a concrete operator action with an exact expected result: run SQL in phpMyAdmin and read back a count, or WinSCP-deploy to `/management-staging/` and load a URL. Automated pytest begins in Phase 3 (Python worker). Where a step says "Operator:", it is an action William runs (SQL execution, WinSCP upload, browser check); the agent produces the exact SQL/code and the precise expected output to check against.

## File Structure

- `scraper/schema.sql` — the 8 `ten_scraper_*` tables + seed of the 2 projects (incl. the default News Collation prompt). One responsibility: scraper data model.
- `scraper/install_module.sql` — TEN_Management registration: `ten_modules` row, `ten_permissions` rows, `module_id` linkage, role grants. One responsibility: wiring the module into the existing nav/permission system.
- `scraper/lib/scraper_db.php` — thin data-access helpers used by the UI (fetch projects, fetch a project). One responsibility: scraper DB reads.
- `module-scraper.php` — the page shell: guard + layout + project tabs. One responsibility: render the scraper landing shell.
- `config.php` (modify, gitignored) — add `OPENAI_API_KEY`, `PEXELS_API_KEY`, `UNSPLASH_API_KEY` placeholder defines (no runtime effect this phase; consumed in later phases).

---

## Task 1: Scraper database schema + seeded projects

**Files:**
- Create: `scraper/schema.sql`

**Interfaces:**
- Produces: 8 tables — `ten_scraper_projects`, `ten_scraper_pub_sections`, `ten_scraper_sources`, `ten_scraper_feeds`, `ten_scraper_items`, `ten_scraper_drafts`, `ten_scraper_vpn_profiles`, `ten_scraper_runs`. Seeds 2 rows in `ten_scraper_projects` (ids referenced by the UI in Task 4 by `type`, not by hardcoded id).

- [ ] **Step 1: Write `scraper/schema.sql`**

```sql
-- TEN Scraper schema (Phase 1). Add-only. Apply to TEN_Management DB.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ten_scraper_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `type` enum('news_collation','email_collection') NOT NULL DEFAULT 'news_collation',
  `default_ai_provider` enum('anthropic','openai') NOT NULL DEFAULT 'anthropic',
  `default_ai_model` varchar(100) NOT NULL DEFAULT 'claude-sonnet-5',
  `default_prompt` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_pub_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `publication_key` varchar(50) NOT NULL,
  `ten_section` varchar(50) NOT NULL,
  `daily_count` int(11) NOT NULL DEFAULT 10,
  `cron_schedule` varchar(100) NOT NULL DEFAULT '0 6 * * *',
  `vpn_profile_id` int(11) DEFAULT NULL,
  `journalist_id` int(11) DEFAULT NULL,
  `ai_provider` enum('anthropic','openai') DEFAULT NULL,
  `ai_model` varchar(100) DEFAULT NULL,
  `prompt` text DEFAULT NULL,
  `auto_publish` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  UNIQUE KEY `uq_pub_section` (`project_id`,`publication_key`,`ten_section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pub_section_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `homepage_url` varchar(500) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pub_section` (`pub_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL,
  `feed_url` varchar(500) NOT NULL,
  `feed_type` enum('rss','html') NOT NULL DEFAULT 'rss',
  `source_category_label` varchar(100) DEFAULT NULL,
  `html_selectors` text DEFAULT NULL,
  `respect_robots` tinyint(1) NOT NULL DEFAULT 1,
  `robots_override_reason` varchar(500) DEFAULT NULL,
  `rate_limit_seconds` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `pub_section_id` int(11) NOT NULL,
  `source_url` varchar(1000) NOT NULL,
  `source_url_hash` varchar(64) NOT NULL,
  `title` varchar(1000) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `facts` mediumtext DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `cluster_id` varchar(64) DEFAULT NULL,
  `image_suggestions` text DEFAULT NULL,
  `status` enum('new','selected','promoting','promoted','discarded') NOT NULL DEFAULT 'new',
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_hash` (`pub_section_id`,`source_url_hash`),
  KEY `idx_status` (`pub_section_id`,`status`),
  KEY `idx_cluster` (`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_drafts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) NOT NULL,
  `generated_title` varchar(1000) DEFAULT NULL,
  `generated_body` longtext DEFAULT NULL,
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `meta_keywords` varchar(500) DEFAULT NULL,
  `ai_provider` varchar(50) DEFAULT NULL,
  `ai_model` varchar(100) DEFAULT NULL,
  `prompt_used` text DEFAULT NULL,
  `journalist_id` int(11) DEFAULT NULL,
  `article_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('draft','promoted','error') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_vpn_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` enum('protonvpn','wireguard','openvpn') NOT NULL DEFAULT 'protonvpn',
  `name` varchar(150) NOT NULL,
  `country` varchar(80) DEFAULT NULL,
  `config_ref` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `pub_section_id` int(11) DEFAULT NULL,
  `started` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished` datetime DEFAULT NULL,
  `items_found` int(11) NOT NULL DEFAULT 0,
  `items_new` int(11) NOT NULL DEFAULT 0,
  `status` enum('running','ok','error') NOT NULL DEFAULT 'running',
  `log` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pub_section` (`pub_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the two projects. The default News Collation prompt encodes the safety posture (spec §2).
INSERT INTO `ten_scraper_projects` (`name`,`type`,`default_ai_provider`,`default_ai_model`,`default_prompt`,`is_active`)
VALUES
('News Collation','news_collation','anthropic','claude-sonnet-5',
'You are an experienced staff journalist for {publication}, writing an ORIGINAL news article for the {section} section in {target_language}.\n\nYou are given the verified facts of a news event and a link to the source that reported it:\nTitle: {source_title}\nSummary: {source_summary}\nFacts: {source_facts}\nSource: {source_url}\n\nRules:\n1. Write your OWN original article from these facts. Do NOT paraphrase, translate, or track the structure of the source article — report the underlying news in your own words and structure.\n2. Facts are not owned; the source''s wording is. Never reproduce sentences or distinctive phrasing from the source.\n3. Attribute where appropriate (e.g. "according to {source_url}") and keep claims to what the facts support. If a fact is uncertain, say so rather than inventing detail. Never fabricate quotes, names, numbers, or events.\n4. Neutral, factual news register. No opinion, no editorialising, no first person.\n5. SEO: choose one clear target keyword from the topic. Produce an SEO title (<=60 chars), a meta description (<=155 chars), 3-6 comma-separated meta keywords, and use sensible H2/H3 subheadings in the body.\n6. Do NOT reference or embed any source image.\n\nReturn ONLY valid JSON with keys: title, body_html, meta_title, meta_description, meta_keywords.',
1),
('Cafe/Restaurant Email Collection','email_collection','anthropic','claude-sonnet-5', NULL, 1)
ON DUPLICATE KEY UPDATE `type`=VALUES(`type`), `is_active`=VALUES(`is_active`);
```

- [ ] **Step 2: Operator — apply the schema to the live DB**

Open phpMyAdmin (or Adminer) on the `TEN_Management` database → SQL tab → paste the full contents of `scraper/schema.sql` → Go.

- [ ] **Step 3: Operator — verify tables exist**

Run:
```sql
SELECT COUNT(*) AS t FROM information_schema.tables
WHERE table_schema = 'TEN_Management' AND table_name LIKE 'ten_scraper_%';
```
Expected: `t = 8`.

- [ ] **Step 4: Operator — verify projects seeded**

Run:
```sql
SELECT id, name, type FROM ten_scraper_projects ORDER BY id;
```
Expected: two rows — `News Collation / news_collation` and `Cafe/Restaurant Email Collection / email_collection`.

- [ ] **Step 5: Commit**

```bash
git add scraper/schema.sql
git commit -m "feat(scraper): add ten_scraper_* schema and seed projects"
```

---

## Task 2: Register module, permissions, and role grants

**Files:**
- Create: `scraper/install_module.sql`

**Interfaces:**
- Consumes: `ten_modules`, `ten_permissions`, `ten_role_permissions`, `ten_roles` (columns confirmed: `ten_role_permissions(role_id, permission_id)`; `ten_roles(role_key)`; `ten_module_groups` already contains the `content` group).
- Produces: a `ten_modules` row with `module_key='scraper'` and `required_permission='scraper.use'`; permissions `scraper.use` + `scraper.manage`; grants to `super_user` + `admin` roles. Task 4's page relies on `module_key='scraper'` being present so the sidebar renders the link.

- [ ] **Step 1: Write `scraper/install_module.sql`**

```sql
-- Register the scraper module in TEN_Management nav + permission system. Add-only, idempotent.

INSERT INTO `ten_modules`
  (`module_key`,`module_name`,`module_description`,`module_icon`,`module_url`,`module_group`,`is_enabled`,`is_system`,`display_order`,`required_permission`)
VALUES
  ('scraper','News Scraper','Collate sources and generate original articles','fa-newspaper','/management/module-scraper.php','content',1,0,50,'scraper.use')
ON DUPLICATE KEY UPDATE
  `module_name`=VALUES(`module_name`),
  `module_description`=VALUES(`module_description`),
  `module_icon`=VALUES(`module_icon`),
  `module_url`=VALUES(`module_url`),
  `module_group`=VALUES(`module_group`),
  `required_permission`=VALUES(`required_permission`);

INSERT INTO `ten_permissions`
  (`permission_key`,`permission_name`,`permission_description`,`module`,`permission_type`,`is_system`)
VALUES
  ('scraper.use','Use Scraper','Select and promote scraped articles','scraper','execute',0),
  ('scraper.manage','Manage Scraper','Configure projects, sources, prompts, models and VPN','scraper','manage',0)
ON DUPLICATE KEY UPDATE
  `permission_name`=VALUES(`permission_name`),
  `permission_description`=VALUES(`permission_description`);

-- Link permissions to the module id.
UPDATE `ten_permissions` p
  JOIN `ten_modules` m ON m.module_key = 'scraper'
  SET p.module_id = m.id
  WHERE p.permission_key IN ('scraper.use','scraper.manage');

-- Grant both permissions to super_user and admin roles (no-dupe insert).
INSERT INTO `ten_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id
FROM `ten_roles` r
JOIN `ten_permissions` p ON p.permission_key IN ('scraper.use','scraper.manage')
WHERE r.role_key IN ('super_user','admin')
  AND NOT EXISTS (
    SELECT 1 FROM `ten_role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
```

- [ ] **Step 2: Operator — apply to the live DB**

phpMyAdmin → `TEN_Management` → SQL → paste `scraper/install_module.sql` → Go.

- [ ] **Step 3: Operator — verify module + permissions registered**

Run:
```sql
SELECT module_key, module_url, required_permission FROM ten_modules WHERE module_key='scraper';
SELECT permission_key, module_id FROM ten_permissions WHERE permission_key LIKE 'scraper.%';
```
Expected: one module row pointing at `/management/module-scraper.php` with `required_permission='scraper.use'`; two permission rows, both with a non-NULL `module_id`.

- [ ] **Step 4: Operator — verify grants**

Run:
```sql
SELECT r.role_key, p.permission_key
FROM ten_role_permissions rp
JOIN ten_roles r ON r.id = rp.role_id
JOIN ten_permissions p ON p.id = rp.permission_id
WHERE p.permission_key LIKE 'scraper.%'
ORDER BY r.role_key, p.permission_key;
```
Expected: `scraper.use` and `scraper.manage` present for `admin` and `super_user` (4 rows, assuming both roles exist).

- [ ] **Step 5: Commit**

```bash
git add scraper/install_module.sql
git commit -m "feat(scraper): register module, permissions and role grants"
```

---

## Task 3: Scraper DB access helper

**Files:**
- Create: `scraper/lib/scraper_db.php`

**Interfaces:**
- Consumes: `getDBConnection()` from `config.php` (returns a `mysqli` for TEN_Management).
- Produces:
  - `scraper_get_projects(): array` — returns all active projects as assoc arrays with keys `id, name, type, default_ai_provider, default_ai_model, is_active`, ordered by `id`.
  - `scraper_get_project_by_type(string $type): ?array` — returns one project row or null.

- [ ] **Step 1: Write `scraper/lib/scraper_db.php`**

```php
<?php
/**
 * Scraper data-access helpers (Phase 1: reads only).
 * Reuses the TEN_Management connection from config.php.
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}

/**
 * Return all active scraper projects, ordered by id.
 * @return array<int,array<string,mixed>>
 */
function scraper_get_projects(): array {
    $conn = getDBConnection();
    $sql = "SELECT id, name, type, default_ai_provider, default_ai_model, is_active
            FROM ten_scraper_projects
            WHERE is_active = 1
            ORDER BY id ASC";
    $result = $conn->query($sql);
    $projects = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    $conn->close();
    return $projects;
}

/**
 * Return a single active project by its type, or null.
 */
function scraper_get_project_by_type(string $type): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, name, type, default_ai_provider, default_ai_model, is_active
         FROM ten_scraper_projects
         WHERE type = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}
```

- [ ] **Step 2: Verification is deferred to Task 4**

This file has no independently runnable test in this environment (no local PHP). It is exercised by `module-scraper.php` in Task 4; a PHP syntax error would surface as a 500 on that page. Proceed to commit — Task 4 is its verification.

- [ ] **Step 3: Commit**

```bash
git add scraper/lib/scraper_db.php
git commit -m "feat(scraper): add project read helpers"
```

---

## Task 4: `module-scraper.php` shell page

**Files:**
- Create: `module-scraper.php`

**Interfaces:**
- Consumes: `scraper_get_projects()` (Task 3); `config.php` helpers `requireLogin()`, `hasPermission()`, `isAdmin()`, `getUserLanguage()`; layout partials `includes/sidebar.php`, `includes/header.php`.
- Produces: a rendered page at `/management/module-scraper.php` with one tab per project; the News Collation tab shows a "configuration coming in Phase 2" placeholder, the email-collection tab shows a "coming soon" stub.

- [ ] **Step 1: Write `module-scraper.php`**

```php
<?php
require_once 'config.php';
require_once __DIR__ . '/scraper/lib/scraper_db.php';
requireLogin();

if (!hasPermission('scraper.use') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$projects = scraper_get_projects();
$canManage = hasPermission('scraper.manage') || isAdmin();
$activeType = $_GET['project'] ?? ($projects[0]['type'] ?? 'news_collation');
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Scraper — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .scraper-tabs { display:flex; gap:8px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; }
        .scraper-tab { padding:10px 18px; cursor:pointer; border:1px solid transparent; border-bottom:none;
                       border-radius:8px 8px 0 0; color:#374151; text-decoration:none; font-weight:600; font-size:14px; }
        .scraper-tab.active { background:#fff; border-color:#e5e7eb; color:#111827; }
        .scraper-panel { background:#fff; border:1px solid #e5e7eb; border-radius:0 8px 8px 8px; padding:24px; }
        .scraper-placeholder { color:#6b7280; font-size:14px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-wrapper" style="padding:24px;">
            <h1 style="font-size:22px;margin-bottom:6px;"><i class="fas fa-newspaper"></i> News Scraper</h1>
            <p class="scraper-placeholder" style="margin-bottom:20px;">
                Collate sources and generate original, SEO-optimised articles per publication and section.
            </p>

            <?php if (empty($projects)): ?>
                <div class="scraper-panel">
                    <p class="scraper-placeholder">No scraper projects found. Run <code>scraper/schema.sql</code> on the database.</p>
                </div>
            <?php else: ?>
                <div class="scraper-tabs">
                    <?php foreach ($projects as $p): ?>
                        <a class="scraper-tab <?php echo $p['type'] === $activeType ? 'active' : ''; ?>"
                           href="module-scraper.php?project=<?php echo urlencode($p['type']); ?>">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="scraper-panel">
                    <?php
                    $active = null;
                    foreach ($projects as $p) { if ($p['type'] === $activeType) { $active = $p; break; } }
                    if (!$active) { $active = $projects[0]; }
                    ?>
                    <h2 style="font-size:17px;margin-bottom:10px;"><?php echo htmlspecialchars($active['name']); ?></h2>
                    <?php if ($active['type'] === 'news_collation'): ?>
                        <p class="scraper-placeholder">
                            Source, section, schedule and prompt configuration arrives in Phase 2.
                            <?php if ($canManage): ?>You have manage access.<?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="scraper-placeholder">Cafe/Restaurant email collection — configuration coming soon.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
```

- [ ] **Step 2: Operator — deploy to staging**

WinSCP FTPS CLI upload to `/management-staging/`:
- `module-scraper.php`
- `scraper/lib/scraper_db.php`

(Ensure `/management-staging/config.php` and `includes/` exist there — staging must mirror `/management/`.)

- [ ] **Step 3: Operator — verify the page renders and lists projects**

Log in to the staging management tool, open `…/management-staging/module-scraper.php`.
Expected: sidebar + header render; heading "News Scraper"; two tabs — "News Collation" and "Cafe/Restaurant Email Collection"; clicking each swaps the panel text; no PHP error/500.

- [ ] **Step 4: Operator — verify the sidebar link appears**

On any staging page, confirm a "News Scraper" item appears in the **content** group of the sidebar and links to `module-scraper.php`.
Expected: link present for your (admin) account.

- [ ] **Step 5: Operator — verify permission gating**

Confirm `module-scraper.php` guard works: a user with neither `scraper.use` nor admin is redirected to `dashboard.php?error=unauthorized`. (If no such test user exists, note this and defer to Phase 2 role testing.)

- [ ] **Step 6: Commit**

```bash
git add module-scraper.php
git commit -m "feat(scraper): add module shell page with project tabs"
```

---

## Task 5: Add API-key placeholders to config

**Files:**
- Modify: `config.php` (gitignored — not committed)

**Interfaces:**
- Produces: `OPENAI_API_KEY`, `PEXELS_API_KEY`, `UNSPLASH_API_KEY` constants (empty placeholders). No runtime effect this phase; consumed in Phases 5–6.

- [ ] **Step 1: Add defines to `config.php`**

Immediately after the existing `define('ANTHROPIC_API_KEY', ...);` line, add:

```php
// Scraper AI + image providers (populate before Phase 5/6; rotate the OpenAI key shared during design first).
define('OPENAI_API_KEY', '');   // OpenAI (ChatGPT) — paste rotated key
define('PEXELS_API_KEY', '');   // Pexels image search
define('UNSPLASH_API_KEY', ''); // Unsplash image search
```

- [ ] **Step 2: Operator — deploy config to staging**

WinSCP-upload the edited `config.php` to `/management-staging/`. (Do not upload to `/management/` until later phases use these keys.)

- [ ] **Step 3: Operator — verify no regression**

Reload `…/management-staging/module-scraper.php`.
Expected: page still renders normally (empty constants are harmless). No commit — `config.php` is gitignored.

---

## Self-Review

**Spec coverage (Phase 1 scope only):**
- Schema for all subsystems (§4) → Task 1 ✓ (all 8 tables incl. `auto_publish`, robots/rate-limit fields, `source_url_hash`, `image_suggestions`).
- Two seeded project types incl. Cafe/Restaurant stub (§1, §12) → Task 1 ✓.
- Default prompt encodes safety posture (§2, §9) → Task 1 seed ✓ (original composition, attribution, no source image, no fabrication, SEO fields).
- Module registration + sidebar + two permissions (§13) → Task 2 ✓.
- Module shell page following existing pattern (§8) → Task 4 ✓.
- Config keys (§14) → Task 5 ✓.
- Deferred to later phases (correctly out of scope here): Python worker (§5, Phase 3), VPN (§6, Phase 3c), cron (§7, Phase 4), review/promote/AI (§8–10, Phase 5), images (§11, Phase 6), config UI (Phase 2).

**Placeholder scan:** No "TBD"/"handle appropriately" left. The only in-page "coming in Phase 2 / coming soon" strings are intentional UI copy for a scaffolding shell, not plan placeholders. The seed prompt is complete, real text.

**Type consistency:** `scraper_get_projects()` returns rows keyed `id,name,type,default_ai_provider,default_ai_model,is_active`; Task 4 reads `type` and `name` only — consistent. `$activeType` compared against `type` values `news_collation` / `email_collection`, which match the enum and the seed. `module_key='scraper'`, `required_permission='scraper.use'`, guard `scraper.use` — consistent across Tasks 2 and 4.

**Environment caveats surfaced:** no automated tests this phase (documented under "Testing approach"); all verification is operator SQL/deploy/browser steps with exact expected outputs.
