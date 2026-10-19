# TEN Design Studio — rework

**Date:** 2026-10-19
**Branch:** `feature/design-system`
**Status:** Design approved; spec for review before planning.
**Supersedes the editor UX of:** `2026-09-29-per-publication-design-system-design.md`, `2026-10-13-visual-editor-figma-defaults-ai-prompt-design.md` (the `/design/` render engine and the publish/version model are kept; only the management-side editor is reworked).

## 1. Problem

The current per-publication design editor is a single large modal in TEN News Sites
(`module-news.php` + `js/news_sites_design.js`, ~1,925 lines). It mixes page layout,
block content editing, token templates, per-block modal and AI editing into one cramped
overlay. William finds it hard to use. We are reworking the **management-side editor** into
a clear, full-page **Design Studio** with distinct Page and Block editing surfaces, template
inheritance, custom pages, a full HTML editor, and per-session undo.

The `/design/` renderer, the `admin_ten` design tables, and the publish/version/rollback
model are retained. This is a UX and editor-architecture rework plus three new capabilities
(templates, custom static pages, global named blocks), not a rewrite of the render engine.

## 2. Scope decisions (approved 2026-10-19)

- **New pages = static content pages:** a new page has its own slug, its own editable
  content, a `/design/` route, and is based on a template.
- **Templates = multiple named templates:** several templates may exist; each page is based
  on exactly one; the template owns page-level style (margins/padding/max-width/background)
  and the **locked header and footer** blocks.
- **HTML editor = extend the in-house editor** (no third-party page builder; GrapesJS was
  rejected previously).
- **Rollout = replace the modal immediately:** the Design button opens the new studio; the
  old modal is retired. Still gated by `publications.design_enabled` (TPE only for now).

## 3. Architecture

### 3.1 Entry point
- New standalone page **`design-studio.php?pub=<acronym>`** in the management app.
  Admin + `design_enabled` gated, same checks as `ajax/design_layout.php`.
- The **Design** button in TEN News Sites links to it with `target="_blank"` (new tab,
  fullscreen). `openDesignModal` and the `#dlDesignRoot` modal are removed from
  `module-news.php` and `js/news_sites_design.js`.

### 3.2 Three screens, one tab (SPA swap)
A single-page app inside `design-studio.php`. Screen changes swap the view without a full
reload and push real browser history (`history.pushState`) so the browser **Back** works and
is mirrored by an in-app Back button.

1. **Design Home** — the two-column hub; owns global Publish / History / Rollback.
2. **Page Editor** — add/remove/move blocks on one page or template.
3. **Block Editor** — edit one block's content/style via the full HTML editor.

### 3.3 Server seam (unchanged pattern)
All reads/writes go through `ajax/design_layout.php` → `lib/design_layouts_db.php` →
`admin_ten` (mysqli). The `/design/` engine renders previews (`?preview=1`) and the live site
from the same tables. New AJAX actions are added (§7); no new DB tables (§6).

## 4. Screens

### 4.1 Design Home (two columns)
Top bar: publication name, **Publish**, **History** (opens the versions/rollback panel),
and a device toggle (Desktop/Mobile) that applies to the thumbnails and carries into the
editors.

- **Left column — "Pages":**
  - First card: **Page templates** → opens a template picker / the Page Editor on a template.
  - One card per page (built-in page types + any custom pages): a scaled rendered thumbnail
    (a small `/design/?preview=1&page=<slug>` iframe) and the page name
    (e.g. "Sections page", "Article page").
  - Trailing cards: **+ New page**, **+ New template**.
  - Card action: **Edit** → Page Editor for that page/template.
- **Right column — "Blocks":**
  - One card per block (built-in + global named blocks): thumbnail + name
    (e.g. "Top Menu", "Footer", "Front feature").
  - Trailing card: **+ New block**.
  - Card action: **Edit** → Block Editor for that block.
- **"No default-page card":** the hub lists real pages/blocks directly; there is no extra
  generic landing card. The generic template is reached through the first Pages card.

### 4.2 Page Editor
Opened for a page or a template. Purpose is **structure only**.
- Layout: left **Blocks rail** + large **live preview** (iframe on `/design/?preview=1`).
- Allowed actions: **Add** (from rail), **Remove**, **Move** (drag up/down). **No per-block
  content editing here.**
- **Header and footer are locked**: shown in the preview but cannot be moved, removed, or
  opened for block editing on this screen. (They belong to the template.)
- Every change reflects **immediately** in the preview (live postMessage apply where
  possible, else re-render). Save is autosaved to the page draft.
- **Preview ↗**: opens the page in a new tab (`/design/?preview=1&page=<slug>&device=<d>`).
- **Back** → Home. **Undo** → session undo (§8).
- **New page** flow: name + slug + choose template → creates a `<slug>` draft seeded from
  the template, adds it to the `_pages` registry, routed by `/design/`.
- **Template editing** is the same editor on a template: here header/footer **are** editable
  (add/remove/move still only), plus page-style controls (margins/padding/max-width/bg).

### 4.3 Block Editor
Opened for one block. Purpose is **content + style of that block**.
- Top: the block rendered in the **main pane** (live, real data).
- Below, **full page width: the HTML editor** (§5).
- **Click an element** in the main pane → it highlights and the HTML editor focuses that
  element's markup.
- Every HTML/style change reflects **immediately** in the main pane.
- **Preview ↗**: opens a block preview in a new tab
  (`/design/?preview=1&block=<type>`).
- **Back** → Home. **Undo** → session undo (§8).
- **New block** flow: name it → stored as a global reusable block (`_blocks` registry),
  available to all pages, editable here.

## 5. The HTML editor (extended in-house)

A full-width editor beneath the block in the Block Editor. Built on the existing
contenteditable-iframe editor (`#dlWysFrame` + toolbar), extended to be feature-complete.

### 5.1 Toolbar
- Paragraph / H1–H4 block format.
- Bold, italic, underline, strikethrough.
- Bulleted and numbered lists.
- Text alignment (left/center/right/justify).
- **Font family dropdown** (the site's configured fonts + a standard set).
- **Font size dropdown.**
- **Text colour** and **background/highlight colour** pickers.
- Link / unlink (destination picker: fixed pages, live sections, dynamic field hrefs,
  custom URL, open-in-new-tab — reuse `link_targets`).
- **Dynamic placeholder dropdown** — insert a placeholder at the cursor. Lists all content
  types: date, journalist/author name, position/role, section, article title, article
  content/body, standfirst, publication name, etc. (sourced from the block's available
  fields plus a global set).
- Clear formatting.
- **`</>` source view** — edit raw HTML directly; round-trips with the visual view.

### 5.2 Columns
Within the editor: **add a column**, **drag to change column widths**, remove a column.
Implemented as a CSS grid/flex wrapper with width handles; widths stored on the block.

### 5.3 Placeholder protection rule
Placeholder (`{{…}}`) content is **protected**:
- Its **text/content cannot be edited or deleted as text** — rendered as a protected,
  non-content-editable token that round-trips verbatim on save (existing mechanism).
- Its **styling can** be changed — colour, size, font, weight, alignment apply by wrapping
  or styling around the token, never altering the token text.
- The source view and toolbar enforce this (a toolbar style action on a selection containing
  a token styles it; a text edit of the token is rejected/ignored).

## 6. Data model (no new tables)

Reuse the `page_type`-rows-in-`ten_design_layouts` pattern; publish snapshots **all**
`page_type` rows into `ten_design_versions`, so new meta rows are versioned automatically.

- **Built-in pages:** unchanged (`page_type` = `front|section|article|author|impressum|…`).
- **Custom pages:** `page_type = <slug>` holds the page's block layout. A new meta row
  **`_pages`** holds the registry:
  ```json
  { "pages": [ { "slug": "guides", "title": "Guides", "template": "tpl_default", "order": 1 } ] }
  ```
- **Templates:** a new meta row **`_templates`**:
  ```json
  { "templates": [
      { "id": "tpl_default", "name": "Default page", "created_by": null,
        "header": { /* block */ }, "footer": { /* block */ },
        "page_style": { "container_max": "...", "margin": "...", "padding": "...", "background": "..." } }
  ] }
  ```
  A page's effective layout = template.header + page blocks + template.footer; page-level
  style comes from the template. `tpl_default` is the built-in "mother" template (not
  deletable; renameable only if user-created).
- **Global/named blocks:** the existing **`_blocks`** store, extended per block with
  `name` and `created_by` so Home can label them and the rename rule can be enforced.
- **Ownership / rename rule:** `created_by` (and built-in flag) determines whether the
  current user may rename a block/template/page. Built-ins are not renameable.

## 7. AJAX contract (`ajax/design_layout.php`)

Existing actions kept: `load`, `save_draft`, `publish`, `list_versions`, `link_targets`,
`rollback`. New actions (admin + `design_enabled` gated):

- `list_pages` — returns built-in page types + `_pages` registry (slug, title, template,
  order) for the Home left column.
- `list_blocks` — returns built-in block registry + `_blocks` named blocks (type, name,
  created_by) for the Home right column.
- `list_templates` — returns `_templates` entries.
- `save_pages` / `save_templates` / `save_blocks_meta` — upsert the respective meta rows
  (reuse `dl_save_draft` with the meta `page_type`).
- `create_page` — `{slug,title,template}`: validate unique slug, seed `<slug>` draft from
  the template, add to `_pages`.
- `create_template` — `{name}`: add a `_templates` entry seeded from `tpl_default`.
- `create_block` — `{name}`: add a named block to `_blocks`.
- `rename_*` — enforce `created_by`/built-in rule server-side.

All new writes `logActivity(...)` as the existing ones do.

## 8. Session undo vs global history

- **Global History** (Design Home): existing `publish` / `list_versions` / `rollback` —
  durable published snapshots. Unchanged.
- **Session Undo** (Page Editor and Block Editor): a **client-side, in-memory** stack of
  this session's changes (layout mutations in the Page Editor; HTML/style mutations in the
  Block Editor). **Undo** pops the stack and reverts the change **immediately** in the
  preview/main pane. It is **not persisted** and is cleared when the studio tab is closed.
  Independent per editor.

## 9. `/design/` renderer changes (template folder, not in git)

- **Router** (`design/router.php`): resolve a custom `<slug>` to its page draft/snapshot;
  fall back to 404 for unknown slugs.
- **Composition**: for every page, wrap the page's blocks with the referenced template's
  header + footer and apply the template's `page_style`. Built-in pages that omit a template
  reference use `tpl_default`.
- These files are deployed to each site docroot by William (tenuser FTP is chrooted and
  cannot reach other pubs); TPE first via tpeuser.

## 10. Files

**Management repo (in git):**
- `design-studio.php` — new standalone page (shell + mounts).
- `js/design-studio/` (or a single `js/design_studio.js`) — home, page editor, block
  editor, shared HTML-editor module, undo stack. Reorganised from `news_sites_design.js`.
- `css/design_studio.css` — studio styles.
- `ajax/design_layout.php` — new actions (§7).
- `lib/design_layouts_db.php` — helpers for `_pages` / `_templates` / named blocks + create
  helpers.
- `lib/design_registry.php` — built-in page/block registry (kept in sync with
  `design/lib/design_registry.php`).
- `module-news.php` — Design button → `design-studio.php` (new tab); remove modal markup.

**Site template (`design/`, not in git), deployed by William:**
- `design/router.php`, `design/lib/registry.php` / `config.php` — custom slugs + template
  composition.

## 11. Out of scope / retained

- The AI edit feature (`ajax/design_ai.php`) is retained as-is and surfaced in the new
  studio; no changes to its behaviour in this spec.
- Mobile remains a diff over desktop (existing model).
- No change to the publish/version storage format beyond the new meta `page_type` rows.

## 12. Testing & deploy

- No local PHP: verify by deploying and viewing in the built-in browser / William's Brave
  (login persists there; the built-in pane may bounce to login.php).
- Deploy via WinSCP CLI **through PowerShell** (saved sessions `tenuser@…/public_html/management`,
  `tpeuser@…/public_html`). `/design/` files are not in git.
- TPE-only during the build (`design_enabled`).
- Verify, per screen: Home thumbnails render; Page Editor add/remove/move reflects live and
  autosaves; header/footer locked; Block Editor element-click → HTML editor → live main-pane
  update; placeholder tokens non-editable as text but styleable; columns add/resize; font/
  size/colour/link/placeholder toolbar all work; Preview ↗ opens a correct new tab; session
  Undo reverts immediately; create page/template/block; rename rule enforced; Publish →
  live site reflects after reseed/publish.

## 13. Open questions resolved

- "No default-page card" (§4.1): hub lists real pages; generic template via first card.
- Session undo (§8): in-memory only, not persisted.
