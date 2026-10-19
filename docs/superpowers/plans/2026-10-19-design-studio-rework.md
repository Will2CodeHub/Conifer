# Design Studio Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the modal design editor with a full-page, three-screen Design Studio (Home hub, Page Editor, Block Editor + full HTML editor) that supports multiple named templates, custom static pages, global named blocks, and per-session undo.

**Architecture:** A new standalone management page `design-studio.php?pub=<acronym>` hosts a vanilla-JS SPA that swaps between three screens using `history.pushState`. All persistence goes through the existing `ajax/design_layout.php` → `lib/design_layouts_db.php` → `admin_ten` (mysqli) seam; new concepts (pages, templates, named blocks) are stored as meta `page_type` rows (`_pages`, `_templates`, extended `_blocks`) so the existing publish/version snapshot covers them with no new tables. The `/design/` renderer (site template, not in git) gains custom-slug routing and template composition.

**Tech Stack:** PHP (mysqli, `getDBConnection_TENAdmin()`), vanilla ES5-style JS (no build step, matches `news_sites_design.js`), Font Awesome, the `/design/` PHP render engine. No framework, no bundler.

## Global Constraints

- **Verification model (no local PHP runtime):** JS is syntax-gated with `node --check <file>`. PHP endpoints and all runtime/behaviour are verified **in-browser on The Paris Eye (TPE)** after deploy — there is no PHP unit-test harness in this repo. "Verify" steps below are `node --check` for JS and scripted in-browser acceptance checks for behaviour.
- **Feature gate:** every server action and the studio page require `isAdmin()` **and** `design_enabled=1` for the publication (TPE only during the build). Copy the gate from `ajax/design_layout.php` (`dl_pub_enabled()`).
- **DB:** design tables live in `admin_ten`, reached with `getDBConnection_TENAdmin()` (mysqli). Never use PDO on the management side.
- **No new DB tables.** New data is meta `page_type` rows in `ten_design_layouts`: `_pages`, `_templates`, and the existing `_blocks` (extended). Publish already snapshots all `page_type` rows into `ten_design_versions`.
- **Registry sync:** `management/lib/design_registry.php` and `design/lib/design_registry.php` are mirror copies — any registry change is applied to both, verbatim.
- **Deploy:** WinSCP CLI **via PowerShell only** (`& "C:\Program Files (x86)\WinSCP\WinSCP.com" "/script=<file>"`). Saved sessions: `tenuser@138.201.252.211`→`/public_html/management` (management repo), `tpeuser@138.201.252.211`→`/public_html` (TPE site, holds `/design/`). `/design/` files are NOT in git. Write the WinSCP script with a single-quoted heredoc and literal `F:\...` paths.
- **Commit attribution:** end every commit message with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **JS style:** match `news_sites_design.js` — IIFE module, `var`, `function` declarations, no ES modules, `esc()`/`clone()` helpers.
- **Placeholder protection:** `{{…}}` tokens are non-editable as text but styleable; reuse the existing token mechanism (`tokenizeDom`, `makeTok`, `wysTemplateHtml`) from `news_sites_design.js` verbatim where possible — it is already verified.

---

## File Structure

**Management repo (in git):**
- `design-studio.php` — NEW. Standalone page shell: gate, `<head>`, mount point `#dsApp`, script/style includes. Thin.
- `css/design_studio.css` — NEW. All studio styles (home, page editor, block editor, HTML editor).
- `js/design_studio/core.js` — NEW. App bootstrap, state `ds`, screen router (`dsGo(screen,params)` + `popstate`), shared `api()`/`esc()`/`clone()`/`toast()`, device toggle.
- `js/design_studio/home.js` — NEW. Design Home: two columns, thumbnails, create page/template/block, Publish/History/Rollback panel.
- `js/design_studio/page_editor.js` — NEW. Page/template editor: blocks rail, drag reorder, add/remove, locked header/footer, live preview, autosave, Back, session undo wiring.
- `js/design_studio/block_editor.js` — NEW. Block editor: main-pane render, element-click selection, mounts the HTML editor, Back, session undo wiring.
- `js/design_studio/html_editor.js` — NEW. The full HTML editor module: toolbar (format/B-I-U-S/lists/align/font/size/colour/link/placeholder/clear/source), columns add/resize, placeholder protection. Ports the verified WYSIWYG core from `news_sites_design.js`.
- `js/design_studio/undo.js` — NEW. `DsUndo` factory: per-editor in-memory snapshot stack with `push/undo/canUndo/clear`.
- `ajax/design_layout.php` — MODIFY. Add actions: `list_pages`, `list_blocks`, `list_templates`, `save_pages`, `save_templates`, `save_blocks_meta`, `create_page`, `create_template`, `create_block`, `rename_item`.
- `lib/design_layouts_db.php` — MODIFY. Add helpers for `_pages`/`_templates`/named-`_blocks` read/create/rename + ownership rule.
- `lib/design_registry.php` — MODIFY (mirror to `design/lib/design_registry.php`). Add `tpl_default` built-in template definition + helper to list built-in page types with labels.
- `module-news.php` — MODIFY. Design button → `design-studio.php?pub=<acronym>` in a new tab; remove `#dlDesignRoot` and the `news_sites_design.js` include.

**Site template (`design/`, NOT in git), deployed by William:**
- `design/router.php` — MODIFY. Resolve custom `<slug>` → its page; 404 unknown.
- `design/lib/registry.php` or `design/index.php` — MODIFY. Compose page = template.header + page blocks + template.footer; apply template `page_style`.
- `design/lib/design_registry.php` — MODIFY (mirror of management copy).

---

## Phasing

- **Phase 1 (Tasks 1–3):** Server data model + AJAX for pages/templates/named blocks. Deployable, testable via URL, no UI yet.
- **Phase 2 (Tasks 4–6):** Studio shell, screen router, Design Home (two columns + thumbnails + create flows + Publish/History).
- **Phase 3 (Tasks 7–8):** Page Editor (structure-only: add/remove/move, locked header/footer, live) + session undo.
- **Phase 4 (Tasks 9–11):** Block Editor + full HTML editor (toolbar, columns, placeholder protection) + session undo.
- **Phase 5 (Tasks 12–13):** `/design/` router + template composition; retire the modal and switch the Design button.

Each task ends deployed to TPE and verified in-browser before the next.

---

## Task 1: Data-model helpers for templates, pages, named blocks

**Files:**
- Modify: `lib/design_layouts_db.php`
- Modify: `lib/design_registry.php` (+ mirror `design/lib/design_registry.php`)

**Interfaces:**
- Consumes: `getDBConnection_TENAdmin()`, existing `dl_load_draft()`, `dl_save_draft()`.
- Produces:
  - `dl_builtin_template(): array` — the `tpl_default` template `{id:'tpl_default', name:'Default page', created_by:null, header:array, footer:array, page_style:array}`.
  - `dl_load_templates(string $pub): array` — decoded `_templates` meta `{templates:[...]}`; seeds `tpl_default` if absent.
  - `dl_load_pages(string $pub): array` — decoded `_pages` meta `{pages:[...]}` (empty array if none).
  - `dl_load_blocks_meta(string $pub): array` — decoded `_blocks` meta `{templates:{...}, settings:{...}, names:{type:{name,created_by}}}` (the `names` key is new; absent = built-ins only).
  - `dl_create_page(string $pub, string $slug, string $title, string $template, string $user): array` — validates slug `^[a-z0-9-]{1,40}$`, unique vs built-ins + existing `_pages`; seeds `<slug>` desktop draft from the template's body skeleton; appends to `_pages`; returns the new page row.
  - `dl_create_template(string $pub, string $name, string $user): array` — new id `tpl_<rand6>`, cloned from `tpl_default`; appends to `_templates`; returns the row.
  - `dl_create_named_block(string $pub, string $name, string $user): string` — new block type `blk_<rand6>`, empty template seeded into `_blocks.templates`, `_blocks.names[type]={name,created_by:user}`; returns the type.
  - `dl_rename_item(string $pub, string $kind, string $id, string $name, string $user): void` — `$kind ∈ {page,template,block}`; throws if the item is a built-in or `created_by !== $user` (and user not super-admin).

- [ ] **Step 1: Add the built-in template to the registry (both copies)**

In `lib/design_registry.php`, add:

```php
/** The built-in "mother" template every page inherits unless it names another. */
function dl_builtin_template(): array {
    return [
        'id'         => 'tpl_default',
        'name'       => 'Default page',
        'created_by' => null,           // built-in => not renameable/deletable
        'header'     => ['type' => 'site_header', 'settings' => ['show_search' => true, 'show_subscribe' => true]],
        'footer'     => ['type' => 'footer', 'settings' => []],
        'page_style' => ['container_max' => '1200px', 'gutter' => '20px', 'margin' => '0', 'padding' => '0', 'background' => '#ffffff'],
    ];
}

/** Built-in page types with human labels, for the Home "Pages" column. */
function dl_builtin_pages(): array {
    return [
        ['slug' => 'front', 'title' => 'Front page'],
        ['slug' => 'section', 'title' => 'Section page'],
        ['slug' => 'article', 'title' => 'Article page'],
        ['slug' => 'author', 'title' => 'Author page'],
        ['slug' => 'impressum', 'title' => 'Impressum'],
        ['slug' => 'contact', 'title' => 'Contact'],
        ['slug' => 'about', 'title' => 'About us'],
        ['slug' => 'privacy', 'title' => 'Privacy policy'],
        ['slug' => 'terms', 'title' => 'Terms'],
        ['slug' => 'disclaimer', 'title' => 'Disclaimer'],
    ];
}
```

- [ ] **Step 2: Mirror the registry change to the site copy**

Copy the two new functions verbatim into `design/lib/design_registry.php` (keep the files identical).

- [ ] **Step 3: Add the meta read helpers to `design_layouts_db.php`**

```php
/** Decoded _templates meta; always includes the built-in tpl_default first. */
function dl_load_templates(string $pub): array {
    $raw = dl_load_draft($pub, '_templates', 'desktop');
    $tpls = ($raw && isset($raw['templates']) && is_array($raw['templates'])) ? $raw['templates'] : [];
    $hasDefault = false;
    foreach ($tpls as $t) { if (($t['id'] ?? '') === 'tpl_default') { $hasDefault = true; break; } }
    if (!$hasDefault) { array_unshift($tpls, dl_builtin_template()); }
    return ['templates' => $tpls];
}

/** Decoded _pages meta ({pages:[...]}), empty list if none stored. */
function dl_load_pages(string $pub): array {
    $raw = dl_load_draft($pub, '_pages', 'desktop');
    return ['pages' => ($raw && isset($raw['pages']) && is_array($raw['pages'])) ? $raw['pages'] : []];
}

/** Decoded _blocks meta, guaranteeing templates/settings/names keys exist. */
function dl_load_blocks_meta(string $pub): array {
    $raw = dl_load_draft($pub, '_blocks', 'desktop') ?: [];
    return [
        'templates' => is_array($raw['templates'] ?? null) ? $raw['templates'] : [],
        'settings'  => is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
        'names'     => is_array($raw['names'] ?? null) ? $raw['names'] : [],
    ];
}
```

- [ ] **Step 4: Add the create helpers**

```php
function dl_create_template(string $pub, string $name, string $user): array {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Template name required'); }
    $meta = dl_load_templates($pub);
    $row = dl_builtin_template();
    $row['id'] = 'tpl_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $row['name'] = $name;
    $row['created_by'] = $user;
    $meta['templates'][] = $row;
    dl_save_draft($pub, '_templates', 'desktop', $meta, $user);
    return $row;
}

function dl_create_page(string $pub, string $slug, string $title, string $template, string $user): array {
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $slug)) { throw new RuntimeException('Slug must be lowercase letters, numbers, hyphens'); }
    foreach (dl_builtin_pages() as $b) { if ($b['slug'] === $slug) { throw new RuntimeException('That slug is a built-in page'); } }
    $pages = dl_load_pages($pub);
    foreach ($pages['pages'] as $p) { if (($p['slug'] ?? '') === $slug) { throw new RuntimeException('A page with that slug already exists'); } }
    // Seed an empty body layout (header/footer come from the template at render time).
    dl_save_draft($pub, $slug, 'desktop', ['theme' => [], 'blocks' => []], $user);
    $row = ['slug' => $slug, 'title' => trim($title) ?: $slug, 'template' => $template ?: 'tpl_default', 'order' => count($pages['pages']) + 1, 'created_by' => $user];
    $pages['pages'][] = $row;
    dl_save_draft($pub, '_pages', 'desktop', $pages, $user);
    return $row;
}

function dl_create_named_block(string $pub, string $name, string $user): string {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Block name required'); }
    $type = 'blk_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $meta = dl_load_blocks_meta($pub);
    $meta['templates'][$type] = '<div class="dl-block-custom"><p>New block — edit me</p></div>';
    $meta['names'][$type] = ['name' => $name, 'created_by' => $user];
    dl_save_draft($pub, '_blocks', 'desktop', $meta, $user);
    return $type;
}
```

- [ ] **Step 5: Add the rename + ownership rule**

```php
/** $kind: page|template|block. Rejects built-ins and items the user doesn't own. */
function dl_rename_item(string $pub, string $kind, string $id, string $name, string $user): void {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Name required'); }
    if ($kind === 'template') {
        if ($id === 'tpl_default') { throw new RuntimeException('Built-in templates cannot be renamed'); }
        $meta = dl_load_templates($pub); $found = false;
        foreach ($meta['templates'] as &$t) {
            if (($t['id'] ?? '') === $id) {
                if (($t['created_by'] ?? null) === null || $t['created_by'] !== $user) { throw new RuntimeException('You can only rename items you created'); }
                $t['name'] = $name; $found = true; break;
            }
        } unset($t);
        if (!$found) { throw new RuntimeException('Template not found'); }
        dl_save_draft($pub, '_templates', 'desktop', $meta, $user); return;
    }
    if ($kind === 'page') {
        $meta = dl_load_pages($pub); $found = false;
        foreach ($meta['pages'] as &$p) {
            if (($p['slug'] ?? '') === $id) {
                if (($p['created_by'] ?? null) !== $user) { throw new RuntimeException('You can only rename pages you created'); }
                $p['title'] = $name; $found = true; break;
            }
        } unset($p);
        if (!$found) { throw new RuntimeException('Page not found (built-in pages cannot be renamed)'); }
        dl_save_draft($pub, '_pages', 'desktop', $meta, $user); return;
    }
    // block
    $meta = dl_load_blocks_meta($pub);
    if (!isset($meta['names'][$id])) { throw new RuntimeException('Only blocks you created can be renamed'); }
    if (($meta['names'][$id]['created_by'] ?? null) !== $user) { throw new RuntimeException('You can only rename blocks you created'); }
    $meta['names'][$id]['name'] = $name;
    dl_save_draft($pub, '_blocks', 'desktop', $meta, $user);
}
```

- [ ] **Step 6: Verify syntax by code review**

No local PHP runtime. Re-read the diff: confirm every new function closes braces, uses `getDBConnection_TENAdmin()` only via `dl_load_draft`/`dl_save_draft` (which manage their own connections), and that `dl_save_draft` accepts the meta `page_type` (it already special-cases non-desktop/non-`_blocks` as raw store — `_pages`/`_templates` go through the `desktop` branch and will be validated; **confirm** `dl_validate_layout` tolerates a `{templates:[]}` / `{pages:[]}` shape, and if not, add `_pages`/`_templates` to the `$page === '_blocks'` raw-store guard in `dl_save_draft`).

Fix found: change the guard in `dl_save_draft` to `if (in_array($page, ['_blocks','_pages','_templates'], true))` so meta rows are stored as-is (not run through layout validation).

- [ ] **Step 7: Commit**

```bash
git add lib/design_layouts_db.php lib/design_registry.php
git commit -m "feat(design): data helpers for templates, custom pages, named blocks

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: AJAX actions for pages/templates/blocks

**Files:**
- Modify: `ajax/design_layout.php`

**Interfaces:**
- Consumes: Task 1 helpers; existing `dl_pub_enabled()`, `isAdmin()`, `logActivity()`, `$user`.
- Produces: JSON responses for `list_pages`, `list_blocks`, `list_templates`, `save_pages`, `save_templates`, `save_blocks_meta`, `create_page`, `create_template`, `create_block`, `rename_item`.

- [ ] **Step 1: Add read actions to the `switch($action)`**

```php
case 'list_pages':
    echo json_encode(['success' => true, 'builtin' => dl_builtin_pages(), 'pages' => dl_load_pages($pub)['pages']]);
    break;
case 'list_templates':
    echo json_encode(['success' => true, 'templates' => dl_load_templates($pub)['templates']]);
    break;
case 'list_blocks':
    // Built-in registry labels come from the JS mirror; here return named blocks + types that have stored templates.
    $meta = dl_load_blocks_meta($pub);
    echo json_encode(['success' => true, 'names' => $meta['names'], 'types' => array_keys($meta['templates'])]);
    break;
```

- [ ] **Step 2: Add the create actions (admin-gated)**

```php
case 'create_page':
    if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
    $row = dl_create_page($pub, (string)($_POST['slug'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['template'] ?? 'tpl_default'), $user);
    logActivity('design_create_page', 'publication', 0, "page $pub/{$row['slug']}");
    echo json_encode(['success' => true, 'page' => $row]);
    break;
case 'create_template':
    if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
    $row = dl_create_template($pub, (string)($_POST['name'] ?? ''), $user);
    logActivity('design_create_template', 'publication', 0, "tpl $pub/{$row['id']}");
    echo json_encode(['success' => true, 'template' => $row]);
    break;
case 'create_block':
    if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
    $type = dl_create_named_block($pub, (string)($_POST['name'] ?? ''), $user);
    logActivity('design_create_block', 'publication', 0, "block $pub/$type");
    echo json_encode(['success' => true, 'type' => $type]);
    break;
case 'rename_item':
    if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
    dl_rename_item($pub, (string)($_POST['kind'] ?? ''), (string)($_POST['id'] ?? ''), (string)($_POST['name'] ?? ''), $user);
    echo json_encode(['success' => true]);
    break;
```

- [ ] **Step 3: Add the meta save passthroughs (admin-gated)**

```php
case 'save_pages':
case 'save_templates':
    if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Admin permission required']); exit; }
    $meta = json_decode((string)($_POST['meta_json'] ?? ''), true);
    if (!is_array($meta)) { echo json_encode(['success' => false, 'message' => 'Invalid meta_json']); exit; }
    dl_save_draft($pub, $action === 'save_pages' ? '_pages' : '_templates', 'desktop', $meta, $user);
    echo json_encode(['success' => true]);
    break;
```

(`_blocks` continues to be saved through the existing `save_draft` path used by the HTML editor — no separate `save_blocks_meta` action is needed; drop it from the spec's list. Note this deviation in the commit.)

- [ ] **Step 4: Require the registry file where the new helpers live**

Confirm `ajax/design_layout.php` already `require_once`s `lib/design_layouts_db.php` (it does, line 11), which itself requires `design_registry.php`. The new `dl_builtin_pages()` is therefore in scope. No extra require needed.

- [ ] **Step 5: Verify (in-browser, after Task 3 deploy)**

Deferred to Task 3 (deploy together). Record expected: `GET ajax/design_layout.php?action=list_templates&pub=tpe` returns `{"success":true,"templates":[{"id":"tpl_default",...}]}`.

- [ ] **Step 6: Commit**

```bash
git add ajax/design_layout.php
git commit -m "feat(design): AJAX for list/create/rename of pages, templates, blocks

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Deploy Phase 1 and verify the data model in-browser

**Files:** none (deploy + verify).

- [ ] **Step 1: Write the WinSCP deploy script** (PowerShell), uploading `lib/design_layouts_db.php`, `lib/design_registry.php`, `ajax/design_layout.php` to `tenuser:/public_html/management`, and `design/lib/design_registry.php` to `tpeuser:/public_html/design/lib`.

- [ ] **Step 2: Deploy** via `& "C:\Program Files (x86)\WinSCP\WinSCP.com" "/script=<file>"`.

- [ ] **Step 3: Verify in the built-in browser** (log into the mgmt app; if the pane bounces to login.php, use Claude-in-Chrome on William's Brave — see memory `browser-testing-login`). Hit:
  - `…/management/ajax/design_layout.php?action=list_templates&pub=tpe` → expect `tpl_default`.
  - `…/action=list_pages&pub=tpe` → expect the 10 built-in pages, empty `pages`.
  - `POST action=create_template&pub=tpe&name=Test` → expect a `tpl_xxxxxx` row; re-run `list_templates` to confirm it persisted; then delete it by re-saving `_templates` without it (manual cleanup) OR leave it (harmless).

- [ ] **Step 4: Record results** in the plan checkboxes; do not proceed until `list_templates`/`list_pages` return the expected JSON.

---

## Task 4: Studio shell + screen router (`core.js`)

**Files:**
- Create: `design-studio.php`
- Create: `css/design_studio.css`
- Create: `js/design_studio/core.js`

**Interfaces:**
- Produces (global, on `window.DS`):
  - `ds` state object `{pub, host, device, screen, params}`.
  - `dsGo(screen, params)` — screen ∈ `home|page|block`; sets `ds.screen`, pushes history, renders.
  - `api(action, data) -> Promise` — POST to `ajax/design_layout.php` with `pub`.
  - `esc(s)`, `clone(o)`, `toast(msg)`.
  - Mount: `#dsApp` with child containers `#dsHome`, `#dsPage`, `#dsBlock` (only the active one visible).
- Consumes: `?pub=` query param; publication `host` fetched once via a new lightweight `api('pub_info')` OR passed from `module-news.php` (decide: add `pub_info` action returning `{host}` from `publications.url`). **Use `pub_info`.**

- [ ] **Step 1: Add `pub_info` action** to `ajax/design_layout.php`:

```php
case 'pub_info':
    $conn = getDBConnection_TENAdmin(); $host = '';
    if ($conn) {
        $s = $conn->prepare("SELECT url FROM publications WHERE publication=? LIMIT 1");
        $s->bind_param('s', $pub); $s->execute();
        $host = (string)($s->get_result()->fetch_assoc()['url'] ?? ''); $s->close(); $conn->close();
    }
    $host = preg_replace('#^https?://#', '', $host); $host = preg_replace('#/.*$#', '', $host);
    echo json_encode(['success' => true, 'host' => $host]);
    break;
```

- [ ] **Step 2: Write `design-studio.php`** — gate + shell:

```php
<?php
require_once 'config.php';
requireLogin();
if (!isAdmin()) { header('Location: dashboard.php?error=unauthorized'); exit(); }
$pub = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['pub'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Design Studio — <?php echo htmlspecialchars($pub); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/design_studio.css?v=<?php echo @filemtime(__DIR__.'/css/design_studio.css'); ?>">
</head>
<body>
  <div id="dsApp" data-pub="<?php echo htmlspecialchars($pub); ?>">
    <div id="dsHome" class="ds-screen"></div>
    <div id="dsPage" class="ds-screen" hidden></div>
    <div id="dsBlock" class="ds-screen" hidden></div>
  </div>
  <script src="js/design_studio/undo.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/undo.js'); ?>"></script>
  <script src="js/design_studio/html_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/html_editor.js'); ?>"></script>
  <script src="js/design_studio/page_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/page_editor.js'); ?>"></script>
  <script src="js/design_studio/block_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/block_editor.js'); ?>"></script>
  <script src="js/design_studio/home.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/home.js'); ?>"></script>
  <script src="js/design_studio/core.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/core.js'); ?>"></script>
</body>
</html>
```

(core.js loads last so the other modules have registered their render functions on `window.DS` before bootstrap.)

- [ ] **Step 3: Write `js/design_studio/core.js`** — state, api, router, bootstrap:

```javascript
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var ds = DS.ds = { pub: "", host: "", device: "desktop", screen: "home", params: {} };

  DS.esc = function (s) { return String(s==null?"":s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];}); };
  DS.clone = function (o) { return JSON.parse(JSON.stringify(o)); };
  DS.toast = function (msg) { var t=document.createElement("div"); t.className="ds-toast"; t.textContent=msg; document.body.appendChild(t); setTimeout(function(){t.remove();},2400); };

  DS.api = function (action, data) {
    var body = new URLSearchParams(); body.set("action", action); body.set("pub", ds.pub);
    Object.keys(data||{}).forEach(function(k){ body.set(k, data[k]); });
    return fetch("ajax/design_layout.php", {method:"POST", body:body})
      .then(function(r){return r.json();})
      .then(function(j){ if(!j.success) throw new Error(j.message||"Request failed"); return j; });
  };

  var SCREENS = { home: "dsHome", page: "dsPage", block: "dsBlock" };
  function show(screen) {
    Object.keys(SCREENS).forEach(function(k){ document.getElementById(SCREENS[k]).hidden = (k !== screen); });
  }
  DS.dsGo = function (screen, params, fromPop) {
    ds.screen = screen; ds.params = params || {};
    if (!fromPop) { history.pushState({screen:screen, params:ds.params}, "", "?pub="+encodeURIComponent(ds.pub)+"#"+screen); }
    show(screen);
    if (screen === "home") { DS.renderHome(); }
    else if (screen === "page") { DS.renderPageEditor(ds.params); }
    else if (screen === "block") { DS.renderBlockEditor(ds.params); }
  };
  window.addEventListener("popstate", function (e) {
    var s = (e.state && e.state.screen) || "home";
    DS.dsGo(s, (e.state && e.state.params) || {}, true);
  });

  function boot() {
    ds.pub = document.getElementById("dsApp").getAttribute("data-pub") || "";
    DS.api("pub_info", {}).then(function (j) { ds.host = j.host; DS.dsGo("home", {}, true); history.replaceState({screen:"home",params:{}}, "", "?pub="+encodeURIComponent(ds.pub)+"#home"); })
      .catch(function (e) { document.getElementById("dsHome").innerHTML = '<p style="padding:24px;color:#b91c1c">'+DS.esc(e.message)+'</p>'; document.getElementById("dsHome").hidden=false; });
  }
  if (document.readyState !== "loading") { boot(); } else { document.addEventListener("DOMContentLoaded", boot); }
})();
```

- [ ] **Step 4: Write minimal `css/design_studio.css`** — full-viewport layout, `.ds-screen{min-height:100vh}`, `.ds-toast` (copy from `news_sites_design.css` `.dl-toast`), and placeholder styles for home/editors (filled in later tasks). Enough that the shell renders without FOUC.

- [ ] **Step 5: Stub the render functions** so core boots before the real screens exist. In each of `home.js`, `page_editor.js`, `block_editor.js`, register a stub: `window.DS.renderHome = window.DS.renderHome || function(){ document.getElementById("dsHome").textContent = "Home"; };` (replaced by the real one in later tasks — define the real one with plain assignment there).

- [ ] **Step 6: Verify JS syntax**

```bash
node --check js/design_studio/core.js
```
Expected: no output (pass). Repeat for each JS file as it's created.

- [ ] **Step 7: Commit**

```bash
git add design-studio.php css/design_studio.css js/design_studio/core.js ajax/design_layout.php
git commit -m "feat(design): Design Studio shell + screen router + pub_info

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Design Home — two columns + thumbnails (`home.js`)

**Files:**
- Create/replace: `js/design_studio/home.js`
- Modify: `css/design_studio.css`

**Interfaces:**
- Consumes: `DS.api`, `DS.ds`, `DS.dsGo`, `DS.esc`; AJAX `list_pages`, `list_templates`, `list_blocks`; the JS block-label registry (port `REG` + `PAGE_LABELS` from `news_sites_design.js` into a shared `js/design_studio/registry.js` **or** inline in `home.js`). **Decide: create `js/design_studio/registry.js`** holding `DS.REG`, `DS.PAGE_LABELS`, `DS.PAGES` (ported verbatim from `news_sites_design.js` lines 9–40) and include it before `home.js` in `design-studio.php`.
- Produces: `DS.renderHome()`.

- [ ] **Step 1: Create `js/design_studio/registry.js`** by copying `PAGES`, `PAGE_LABELS`, `REG` from `news_sites_design.js:9-40` onto `DS.PAGES/PAGE_LABELS/REG`. Add its `<script>` before `home.js` in `design-studio.php`.

- [ ] **Step 2: Write `DS.renderHome()`** — top bar + two columns:

```javascript
window.DS.renderHome = function () {
  var root = document.getElementById("dsHome"); var ds = DS.ds;
  root.innerHTML =
    '<header class="ds-topbar"><h1>Design — '+DS.esc(ds.pub)+'</h1>'+
      '<div class="ds-dev"><button data-dev="desktop" class="'+(ds.device==="desktop"?"on":"")+'">Desktop</button>'+
      '<button data-dev="mobile" class="'+(ds.device==="mobile"?"on":"")+'">Mobile</button></div>'+
      '<div class="ds-grow"></div>'+
      '<button class="ds-btn" id="dsPublish">Publish</button>'+
      '<button class="ds-btn" id="dsHistory">History</button></header>'+
    '<div class="ds-hub">'+
      '<section class="ds-col"><h2>Pages</h2><div class="ds-cards" id="dsPageCards"></div></section>'+
      '<section class="ds-col"><h2>Blocks</h2><div class="ds-cards" id="dsBlockCards"></div></section>'+
    '</div>';
  root.querySelectorAll(".ds-dev button").forEach(function(b){ b.addEventListener("click",function(){ ds.device=b.dataset.dev; DS.renderHome(); }); });
  root.querySelector("#dsPublish").addEventListener("click", DS.publish);
  root.querySelector("#dsHistory").addEventListener("click", DS.showHistory);
  loadPageCards(); loadBlockCards();
};
```

- [ ] **Step 3: Implement `loadPageCards()`** — a thumbnail card per page, template card first, trailing create cards:

```javascript
function card(thumbSrc, title, onClick, extraClass) {
  var d = document.createElement("div"); d.className = "ds-card "+(extraClass||"");
  d.innerHTML = '<div class="ds-thumb">'+(thumbSrc?'<iframe loading="lazy" src="'+DS.esc(thumbSrc)+'"></iframe>':'')+'</div>'+
                '<div class="ds-card-name">'+DS.esc(title)+'</div>';
  d.addEventListener("click", onClick); return d;
}
function thumbUrl(slug) { return "https://"+DS.ds.host+"/design/?preview=1&page="+encodeURIComponent(slug)+"&device="+DS.ds.device+"&thumb=1"; }

function loadPageCards() {
  var wrap = document.getElementById("dsPageCards"); wrap.innerHTML = "";
  Promise.all([DS.api("list_pages",{}), DS.api("list_templates",{})]).then(function (r) {
    var pages = r[0], tpls = r[1];
    wrap.appendChild(card(null, "Page templates", function(){ DS.openTemplatePicker(tpls.templates); }, "ds-card-tpl"));
    pages.builtin.forEach(function(p){ wrap.appendChild(card(thumbUrl(p.slug), p.title, function(){ DS.dsGo("page", {slug:p.slug, kind:"page"}); })); });
    pages.pages.forEach(function(p){ wrap.appendChild(card(thumbUrl(p.slug), p.title, function(){ DS.dsGo("page", {slug:p.slug, kind:"page"}); })); });
    wrap.appendChild(card(null, "+ New page", function(){ DS.openNewPageDialog(tpls.templates); }, "ds-card-new"));
    wrap.appendChild(card(null, "+ New template", function(){ DS.openNewTemplateDialog(); }, "ds-card-new"));
  }).catch(function(e){ wrap.innerHTML = '<p class="ds-err">'+DS.esc(e.message)+'</p>'; });
}
```

- [ ] **Step 4: Implement `loadBlockCards()`** — built-in blocks (from `DS.REG`) + named blocks (from `list_blocks`) + "+ New block":

```javascript
function blockThumb(type){ return "https://"+DS.ds.host+"/design/?preview=1&block="+encodeURIComponent(type)+"&thumb=1"; }
function loadBlockCards() {
  var wrap = document.getElementById("dsBlockCards"); wrap.innerHTML = "";
  DS.api("list_blocks",{}).then(function (r) {
    Object.keys(DS.REG).forEach(function(type){ wrap.appendChild(card(blockThumb(type), DS.REG[type].label, function(){ DS.dsGo("block",{type:type}); })); });
    Object.keys(r.names||{}).forEach(function(type){ wrap.appendChild(card(blockThumb(type), r.names[type].name, function(){ DS.dsGo("block",{type:type}); })); });
    wrap.appendChild(card(null, "+ New block", function(){ DS.openNewBlockDialog(); }, "ds-card-new"));
  }).catch(function(e){ wrap.innerHTML = '<p class="ds-err">'+DS.esc(e.message)+'</p>'; });
}
```

- [ ] **Step 5: Implement the create dialogs + Publish/History** — `DS.openNewPageDialog`, `DS.openNewTemplateDialog`, `DS.openNewBlockDialog`, `DS.openTemplatePicker`, `DS.publish`, `DS.showHistory`. Port `publish`/`showHistory`/version-list logic from `news_sites_design.js` (`doPublish`, `showHistory`, `rollback`). Dialogs are small prompt-style overlays that call `create_page`/`create_template`/`create_block` then re-render Home (or `dsGo` into the new item). New-page dialog fields: Title, Slug (auto-from-title), Template `<select>` from templates.

- [ ] **Step 6: Style the hub** in `design_studio.css` — `.ds-hub{display:grid;grid-template-columns:1fr 1fr;gap:24px}`, `.ds-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}`, `.ds-card` with `.ds-thumb{aspect-ratio:16/10;overflow:hidden;pointer-events:none}` and a scaled iframe (`transform:scale(.3);transform-origin:top left;width:333%;height:333%`), hover lift, `.ds-card-new` dashed.

- [ ] **Step 7: Add `&thumb=1` handling to the preview** — note for Task 12: `/design/` should, when `thumb=1`, render at desktop width with pointer events off (static). Until Task 12, thumbnails still render (just full-size scaled); acceptable.

- [ ] **Step 8: Verify** `node --check` on `home.js` and `registry.js`. Deploy with Task 6.

- [ ] **Step 9: Commit**

```bash
git add js/design_studio/home.js js/design_studio/registry.js css/design_studio.css design-studio.php
git commit -m "feat(design): Design Home two-column hub with page/block thumbnails

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Deploy Phase 2 and verify Home in-browser

**Files:** none (deploy + verify).

- [ ] **Step 1: Deploy** `design-studio.php`, `css/design_studio.css`, all `js/design_studio/*.js` to `tenuser:/public_html/management` (create the `js/design_studio/` dir on the server).
- [ ] **Step 2: Open** `…/management/design-studio.php?pub=tpe` logged in. Verify: top bar, two columns, page cards with thumbnails (built-ins render), block cards, create cards present.
- [ ] **Step 3: Verify** creating a template and a page via the dialogs: new cards appear; re-opening the studio shows them persisted. Verify a non-admin or non-TPE pub is blocked.
- [ ] **Step 4: Verify** Publish produces a new version and History lists it (reuse of existing flow).

---

## Task 7: Page Editor — structure-only (`page_editor.js`)

**Files:**
- Create/replace: `js/design_studio/page_editor.js`
- Modify: `css/design_studio.css`

**Interfaces:**
- Consumes: `DS.api` (`load`, `save_draft`), `DS.ds`, `DS.dsGo`, `DS.REG`, `DS.clone`; `DsUndo` (Task 8 — define the undo wiring now but the factory lands in Task 8; until then use a local no-op `{push:function(){},undo:function(){},canUndo:function(){return false;},clear:function(){}}` and swap in Task 8).
- Produces: `DS.renderPageEditor(params)` where `params = {slug, kind:'page'|'template', id?}`.

- [ ] **Step 1: Layout** — top bar (Back, page name, Preview ↗, Undo, device), left blocks rail (add palette + current blocks list with ↑/↓ + 🗑), center live preview iframe (`/design/?preview=1&page=<slug>&device=<d>`). For `kind:'page'`, the header/footer are rendered by the template and shown **locked** (greyed, no ↑/↓/🗑, not draggable). For `kind:'template'`, header/footer **are** editable (still add/remove/move only) plus a page-style panel (margins/padding/max-width/bg).

- [ ] **Step 2: Load** the page draft via `DS.api("load",{page:slug, device})`; store as `ds.pageState = {slug, device, layout:{theme,blocks}}`. Render the blocks list from `layout.blocks` using `DS.REG[type].label`.

- [ ] **Step 3: Add block** — palette lists `DS.REG` entries whose `pages` include this slug (built-in pages) or all blocks (custom pages); clicking appends `{type, settings:clone(DS.REG[type].defaults)}` to `layout.blocks`, pushes an undo snapshot, re-renders list, live-adds to preview via postMessage `{action:'reorder'|'add'}` (reuse the existing preview bridge contract; a full `refreshPreview()` reload is acceptable where live-add isn't wired).

- [ ] **Step 4: Move / remove** — ↑/↓ swap adjacent entries; 🗑 removes. Each mutation: push undo snapshot, re-render list, update preview. Drag-and-drop reorder: implement with HTML5 DnD on the list items (set `draggable`, `dragstart`/`dragover`/`drop` reorder `layout.blocks`), header/footer items excluded from DnD for `kind:'page'`.

- [ ] **Step 5: Autosave** — debounced 600ms `DS.api("save_draft",{page:slug, device, layout_json:JSON.stringify(layout)})`. For `kind:'template'`, save the template's header/footer/page_style into `_templates` via `save_templates` (load-modify-write the template row), not into a page draft.

- [ ] **Step 6: Back / Preview** — Back → `history.back()` (or `DS.dsGo("home")`). Preview ↗ → `window.open("https://"+ds.host+"/design/?preview=1&page="+slug+"&device="+device, "_blank")`.

- [ ] **Step 7: Verify** `node --check js/design_studio/page_editor.js`.

- [ ] **Step 8: Commit**

```bash
git add js/design_studio/page_editor.js css/design_studio.css
git commit -m "feat(design): Page Editor — add/remove/move blocks, locked header/footer, live

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: Session undo (`undo.js`) + wire into Page Editor

**Files:**
- Create/replace: `js/design_studio/undo.js`
- Modify: `js/design_studio/page_editor.js`

**Interfaces:**
- Produces: `window.DsUndo = function(getState, setState){ return {push, undo, canUndo, clear}; }` — in-memory, deep-cloned snapshots.

- [ ] **Step 1: Write `undo.js`**

```javascript
(function () {
  "use strict";
  window.DsUndo = function (getState, setState) {
    var stack = [];
    return {
      push: function () { try { stack.push(JSON.stringify(getState())); if (stack.length > 100) stack.shift(); } catch (e) {} },
      undo: function () { if (stack.length === 0) return false; var s = stack.pop(); try { setState(JSON.parse(s)); return true; } catch (e) { return false; } },
      canUndo: function () { return stack.length > 0; },
      clear: function () { stack = []; }
    };
  };
})();
```

Semantics: call `push()` **before** mutating. `undo()` restores the most recent pre-mutation snapshot and applies it immediately via `setState` (which must re-render the preview).

- [ ] **Step 2: Wire into Page Editor** — on entering, `ds.pageUndo = DsUndo(function(){return ds.pageState.layout;}, function(l){ds.pageState.layout=l; renderBlockList(); refreshPreview();});`. Call `ds.pageUndo.push()` at the start of add/move/remove/drop. Undo button calls `ds.pageUndo.undo()` and toasts "Nothing to undo" when `!canUndo()`. Clear on Back.

- [ ] **Step 3: Verify** `node --check` both files.

- [ ] **Step 4: Deploy Phase 3** (page_editor.js, undo.js, css) and verify in-browser: add/move/remove reflects live, header/footer locked on a built-in page and editable on a template, Undo reverts the last action immediately, autosave persists across reload.

- [ ] **Step 5: Commit**

```bash
git add js/design_studio/undo.js js/design_studio/page_editor.js
git commit -m "feat(design): per-session undo stack wired into the Page Editor

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: HTML editor module — toolbar core (`html_editor.js`)

**Files:**
- Create/replace: `js/design_studio/html_editor.js`
- Modify: `css/design_studio.css`

**Interfaces:**
- Produces: `DS.HtmlEditor = { mount(container, opts), getHtml(), setHtml(tpl), onChange(cb), focusElement(selector) }` where `opts = {host, blockType, fields}`.
- Reuses verbatim from `news_sites_design.js`: the token machinery (`tokenizeDom`, `makeTok`, `tokInfo`, `tokHumanize`, `FIELD_LABELS`, `softenTemplateImages`, `wysTemplateHtml`, `splitStyle`), the range-based formatters (`wrapInline`, `formatBlockSel`, `listSel`, `alignSel`, `clearFormat`), and the link dialog (`openLinkDialog`, `applyLink`). Port these into `html_editor.js` unchanged (they are already verified — see memory `ten-design-system`).

- [ ] **Step 1: Port the WYSIWYG core** — copy the above functions and the `#dlWysFrame` contenteditable-iframe setup (`loadWys`, `buildWysChrome`, `toggleSource`, `insertToken`, `fillInsertMenu`) from `news_sites_design.js` into `html_editor.js`, renaming the toolbar container ids to the studio's (`#dsHtmlTb`, `#dsHtmlFrame`, `#dsHtmlSrc`). Keep the token round-trip exactly.

- [ ] **Step 2: Expose `DS.HtmlEditor.mount(container, opts)`** that injects `<div class="ds-html"><div id="dsHtmlTb"></div><div class="ds-html-hint">…</div><div class="ds-html-stage"><iframe id="dsHtmlFrame"></iframe><textarea id="dsHtmlSrc" hidden></textarea></div></div>` into `container`, builds the toolbar, and loads `opts` template via `setHtml`.

- [ ] **Step 3: Verify** `node --check js/design_studio/html_editor.js`.

- [ ] **Step 4: Commit**

```bash
git add js/design_studio/html_editor.js css/design_studio.css
git commit -m "feat(design): HTML editor module (ports verified WYSIWYG core)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: HTML editor — font/size/colour, placeholder dropdown, columns

**Files:**
- Modify: `js/design_studio/html_editor.js`
- Modify: `css/design_studio.css`

**Interfaces:**
- Consumes: the Task 9 `wrapInline`/range helpers.
- Produces: toolbar controls `fontFamily`, `fontSize`, `textColor`, `bgColor`, `insertPlaceholder`, `addColumn`/`resizeColumn`; all operate on the current selection/range and emit `onChange`.

- [ ] **Step 1: Font family + size dropdowns** — add two `<select>`s to the toolbar. On change, apply to the selection by wrapping in `<span style="font-family:…">` / `font-size` via a `wrapInlineStyle(prop, val)` helper (extends `wrapInline` to set a style instead of a tag). Font list = site fonts (from `DS.ds` theme, if available) + a standard web-safe set. Size list = `12,14,16,18,20,24,28,32,40,48` px.

```javascript
function wrapInlineStyle(prop, val) {
  var sel = wys.win.getSelection(); if (!sel.rangeCount) return;
  var r = sel.getRangeAt(0); if (r.collapsed) return;
  var span = wys.doc.createElement("span"); span.style[prop] = val;
  span.appendChild(r.extractContents()); r.insertNode(span);
  // re-select the wrapped content
  var nr = wys.doc.createRange(); nr.selectNodeContents(span); sel.removeAllRanges(); sel.addRange(nr);
  wysChanged();
}
```

- [ ] **Step 2: Colour pickers** — text colour and background/highlight colour `<input type="color">` in the toolbar; on `input`, `wrapInlineStyle("color", value)` / `wrapInlineStyle("background-color", value)`.

- [ ] **Step 3: Placeholder dropdown** — a toolbar `<select>` "Insert placeholder…" listing all field types. Build the list from `opts.fields` (the block's own fields via the existing `fieldsFor(type)` / `BLOCK_PLACEHOLDERS`) **plus** a global set: `date`, `publication_name`, `journalist_name`, `position`, `section`, `article_title`, `article_content`, `standfirst`. On pick, `insertToken("{{"+key+"}}")` (reuses the token insert → renders a protected chip).

```javascript
var GLOBAL_FIELDS = [
  ["date","Today’s date"],["publication_name","Publication name"],["journalist_name","Journalist name"],
  ["position","Journalist position/role"],["section","Section name"],["article_title","Article title"],
  ["article_content","Article body"],["standfirst","Standfirst"]
];
```

- [ ] **Step 4: Columns** — toolbar "Add column" wraps the current block content (or inserts) into `<div class="ds-cols" style="display:grid;grid-template-columns:1fr 1fr;gap:20px"><div>…</div><div></div></div>`; a further "Add column" appends a `1fr` and a new child div. **Resize**: when the caret is inside a `.ds-cols`, show draggable width handles between columns in the iframe that rewrite `grid-template-columns` live. Store on the block template HTML (round-trips as normal markup). Keep it simple: handles write `fr` values; a "reset columns" clears to equal.

- [ ] **Step 5: Enforce placeholder protection** — confirm (from the ported core) that toolbar **style** actions applied to a selection containing a `.dl-tok` chip style the wrapper around it (the chip stays `contenteditable=false` and its `data-tok` text is untouched), and that **text** edits can't alter a chip. Add a guard in `wrapInlineStyle`: if the range is exactly a single `.dl-tok`, wrap it (style only) rather than extracting its contents. Verify a placeholder can be coloured/resized but not retyped.

- [ ] **Step 6: Verify** `node --check js/design_studio/html_editor.js`.

- [ ] **Step 7: Commit**

```bash
git add js/design_studio/html_editor.js css/design_studio.css
git commit -m "feat(design): HTML editor font/size/colour, placeholder dropdown, columns

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 11: Block Editor (`block_editor.js`) + undo

**Files:**
- Create/replace: `js/design_studio/block_editor.js`
- Modify: `css/design_studio.css`

**Interfaces:**
- Consumes: `DS.HtmlEditor`, `DS.api` (`load`/`save_draft` on `_blocks`), `DsUndo`, `DS.REG`, `DS.ds`.
- Produces: `DS.renderBlockEditor(params)` where `params = {type}`.

- [ ] **Step 1: Layout** — top bar (Back, block name, Preview ↗, Undo), **main pane** showing the block rendered live in an iframe (`/design/?preview=1&block=<type>`), and **below, full width, the HTML editor** mounted via `DS.HtmlEditor.mount`.

- [ ] **Step 2: Load the block template** — fetch the `_blocks` store via `DS.api("load",{page:"_blocks", device:"desktop"})`; get `templates[type]` (or, for a built-in with no stored override, fetch the built-in default via `https://host/design/?block=<type>&tpl_default=1` as the current editor does). Feed into `DS.HtmlEditor.setHtml(tpl)`.

- [ ] **Step 3: Element click → focus** — the main-pane preview posts a `select` message with an element selector (reuse the `/design/assets/design.js` bridge); on receipt, `DS.HtmlEditor.focusElement(selector)` scrolls/selects that element's markup in the editor and highlights it in the main pane.

- [ ] **Step 4: Live reflect + save** — `DS.HtmlEditor.onChange(function(html){ ... })`: update the main-pane preview (postMessage live where possible, else reload the block iframe), push an undo snapshot, debounced-save the `_blocks` store with the new `templates[type]=html` via the existing `_blocks` save path (`save_draft` page `_blocks`, merging templates+settings+names so nothing is dropped — reuse `saveGlobalsSilent` logic from `news_sites_design.js`).

- [ ] **Step 5: Undo** — `ds.blockUndo = DsUndo(getHtml/setHtml)`; Undo button reverts the editor + preview immediately.

- [ ] **Step 6: Back / Preview** — Back → `history.back()`; Preview ↗ → new tab `/design/?preview=1&block=<type>`.

- [ ] **Step 7: Verify** `node --check js/design_studio/block_editor.js`.

- [ ] **Step 8: Deploy Phase 4** and verify in-browser on TPE: open a block, click an element → HTML editor focuses it; edit text → main pane updates immediately; font/size/colour/link/placeholder toolbar all work; a placeholder chip can be coloured but not retyped; add/resize columns; Undo reverts; Preview ↗ opens a correct new tab; reload shows the edit persisted; create a New block from Home and edit it.

- [ ] **Step 9: Commit**

```bash
git add js/design_studio/block_editor.js css/design_studio.css
git commit -m "feat(design): Block Editor with full-width HTML editor + undo

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 12: `/design/` router + template composition (site template)

**Files (NOT in git; deployed by William to tpeuser):**
- Modify: `design/router.php`
- Modify: `design/index.php` / `design/lib/registry.php` / `design/lib/config.php`

**Interfaces:**
- Consumes: `_pages`, `_templates` meta from the live snapshot/draft (loaded like `_blocks` is today via `dl_load_block_templates`).
- Produces: custom-slug routing + per-page template composition + `thumb=1` static render.

- [ ] **Step 1: Load template + pages meta** at render start (alongside the existing `_blocks` load) into `$GLOBALS['dl_templates']` and `$GLOBALS['dl_pages']` (live snapshot in production, draft in `?preview=1`).

- [ ] **Step 2: Route custom slugs** — in `router.php`, if the requested path isn't a built-in route, look it up in `dl_pages`; if found, render `page_type=<slug>`; else 404.

- [ ] **Step 3: Compose with the template** — resolve the page's template (page's `template` field in `_pages`, else `tpl_default`); render `template.header`, then the page's own blocks, then `template.footer`; apply `template.page_style` as CSS vars / container styles. Built-in pages that have no `_pages` entry use `tpl_default`.

- [ ] **Step 4: `thumb=1`** — when present, render at desktop width, disable interactivity (`pointer-events:none` on body via an injected style), suppress cookie/consent so thumbnails are clean.

- [ ] **Step 5: Deploy to tpeuser** (William or via tpeuser WinSCP session) and verify: a custom page created in the studio renders at `/design/<slug>` with the template's header/footer; built-in pages still render; thumbnails are clean.

- [ ] **Step 6: (No git commit — not a repo.)** Record the deployed file list in the plan.

---

## Task 13: Retire the modal, switch the Design button

**Files:**
- Modify: `module-news.php`

- [ ] **Step 1: Repoint the Design button** — in `module-news.php` (and `js/news_sites.js` if it builds the row buttons), change the Design action to `window.open('design-studio.php?pub='+encodeURIComponent(acronym), '_blank')`. Keep the `design_enabled` greying.

- [ ] **Step 2: Remove the modal** — delete `#dlDesignRoot` from `module-news.php` and the `news_sites_design.js` `<script>` include. (Keep `news_sites_design.js` in the repo until the studio is fully verified, then delete it in a follow-up — note in commit.)

- [ ] **Step 3: Verify** `node --check` (no JS changed in this file beyond include removal). Deploy; verify the Design button opens the studio in a new tab and the old modal no longer loads.

- [ ] **Step 4: Commit**

```bash
git add module-news.php js/news_sites.js
git commit -m "feat(design): Design button opens the new Design Studio; retire modal

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage**

| Spec section | Task(s) |
|---|---|
| §3.1 standalone page, new tab | 4, 13 |
| §3.2 three-screen SPA + Back | 4 (router), 7/11 (Back) |
| §3.3 server seam, new actions | 1, 2 |
| §4.1 Home two columns + thumbnails | 5 |
| §4.1 create page/template/block cards | 5 |
| §4.2 Page Editor add/remove/move only | 7 |
| §4.2 locked header/footer | 7 |
| §4.2 new page from template | 1 (create_page), 5 (dialog), 12 (render) |
| §4.2 template editing + page style | 7 |
| §4.3 Block Editor + element click | 11 |
| §5 HTML editor toolbar (font/size/colour/link/placeholder/source) | 9, 10 |
| §5.2 columns add/resize | 10 |
| §5.3 placeholder protection | 9 (token core), 10 (style-not-text guard) |
| §6 data model (_pages/_templates/_blocks, no new tables) | 1 |
| §6 ownership/rename rule | 1 (dl_rename_item), 2 (rename_item) |
| §7 AJAX contract | 2 |
| §8 global history | 5 (publish/history) |
| §8 session undo in-memory | 8 (undo.js), 8/11 (wiring) |
| §9 /design/ router + composition + thumb | 12 |
| §10 file list | matches Tasks 4–13 |
| §11 AI retained | not touched (retained as-is); surfaced in studio is deferred — **noted below** |
| §12 testing/deploy | 3, 6, 8, 11, 12 |

**Gap found & resolved:** Spec §11 says the AI edit feature is "retained and surfaced in the new studio." No task wires the AI button into the studio. **Resolution:** AI is retained server-side (`ajax/design_ai.php` untouched) but surfacing it in the studio is explicitly deferred to a follow-up (not in this plan's scope). Added to "Deferred" below so it's not silently dropped.

**2. Placeholder scan:** No "TBD/TODO/implement later" left. Steps that reference porting existing code name the exact source functions and file (`news_sites_design.js`) rather than restating hundreds of verified lines — this is DRY, not a placeholder. `save_blocks_meta` was dropped in Task 2 Step 3 with a reason (the `_blocks` save reuses `save_draft`).

**3. Type consistency:** `DS.dsGo(screen, params)`, `DS.api`, `DS.REG`, `DS.HtmlEditor.{mount,getHtml,setHtml,onChange,focusElement}`, `DsUndo(getState,setState)->{push,undo,canUndo,clear}` used consistently across Tasks 4–11. PHP helpers (`dl_load_templates`/`dl_load_pages`/`dl_load_blocks_meta`/`dl_create_*`/`dl_rename_item`) defined in Task 1 and called with matching signatures in Task 2. `thumb=1`/`tpl_default=1` preview params consistent between Task 5 (consumer) and Task 12 (producer).

## Deferred (not in this plan)

- Surfacing the existing AI edit feature (`ajax/design_ai.php`) inside the studio (spec §11 retains it server-side only here).
- Deleting `news_sites_design.js`/`.css` from the repo (kept as fallback until the studio is fully verified; remove in a follow-up).
- Mobile-specific editing beyond carrying the device toggle into previews (existing diff model unchanged).
