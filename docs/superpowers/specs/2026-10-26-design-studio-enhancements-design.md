# Design Studio enhancements — design spec

**Date:** 2026-10-26
**Status:** Approved design, pending implementation plan
**Area:** TEN Design Studio (`management/js/design_studio/*`, `management/ajax/design_layout.php`) and the universal `/design/` engine (`design/lib/*`, `design/blocks/*`).

## Background

The Design Studio lets each publication build its pages from blocks, with a live
preview served by the universal `/design/` engine. Blocks are HTML templates
containing `{{placeholder}}` tokens (see `design/lib/template.php`) that each
block's PHP file fills from a context it builds itself. There is currently **no
central list of placeholders** — the tokens are implicit in each block's code.

This spec covers seven user-requested improvements, grouped into three pieces,
all resting on one new foundation: a **placeholder registry**.

## Goals

1. Custom blocks you create can actually be placed on pages. *(bug #1)*
2. Rename and delete custom blocks/pages/templates; built-ins protected. *(#2)*
3. A save guard when leaving an editor. *(#3)*
4. A placeholder reference ("manual") in the tool. *(#4)*
5. Every page-level placeholder usable in any block on that page, and each
   block's placeholders listed in its editor. *(#5)*
6. `date` and `read time` as separate placeholders. *(#6)*
7. New placeholders: author byline, author bio, author role, last-updated date. *(#7)*

## Non-goals

- No change to publishing/versioning (`ten_design_versions`) or the draft store
  (`ten_design_layouts`).
- No redesign of the editors' layout or the undo system.
- Built-in blocks remain code-backed PHP renderers; the registry only *documents*
  them, it does not replace them.
- No new per-element visual-editor features.

## Foundation — placeholder registry

**New file: `design/lib/placeholders.php`.**

A single PHP source of truth describing the placeholders available in the system.
Two parts:

### 1. Shared, page-level groups

Keyed by page area, each a list of `{ token, label, example }`:

- `article` — available on the **article** page: `title`, `standfirst`,
  `section`, `section_href`, `date` (alias of published), `date_published`,
  `date_updated`, `read_time`, `image`, `url`, `byline`, `author_name`,
  `author_role`, `author_bio`, `author_photo`, `author_href`.
- `author` — author page: `name`, `role`, `bio`, `photo`, `email`, `x_url`,
  `linkedin_url`.
- `site` — everywhere: `site_name`, `logo`, `search_icon`, `subscribe_href`,
  `contact_href`.

### 2. Per-block placeholders

Keyed by block type → list of `{ token, label, example }`, derived from each
block's default template + context (e.g. `headlines_list` → `headlines_label`,
and a repeating `items` group with `title`, `href`, `section`, `meta`).

### Accessors

- `dl_placeholder_registry(): array` — returns `{ shared: {...}, blocks: {...} }`.
- `dl_page_shared_context(string $page, array $params): array` — builds the
  actual values for a page's shared group (used by the engine, below). For
  `article` it loads the article once (`dl_article`), its byline
  (`dl_article_byline`) and author card (`dl_author_card`), and the dates
  (`created_at` → published, `modified_date` → updated), each formatted or `''`.

## Piece A — Blocks you build actually work (#1, #2)

### #1 — Custom blocks in the page palette (bug fix)

**Root cause:** `renderPalette()` in `page_editor.js` iterates only `DS.REG`
(built-ins). Custom blocks (stored in the `_blocks` store under `names`) are
never offered, so they cannot be placed on any page.

**Fix:**
- The page editor loads the custom-block list (`DS.api("list_blocks")`) on entry
  and keeps `{ type → name }` in `st.customBlocks`.
- `renderPalette()` appends a palette item per custom block (allowed on **all**
  pages and templates; they are generic).
- `addBlock(type)` handles a custom type: no `DS.REG` entry, so it inserts
  `{ type, settings: {} }`.
- `blockLabel(type)` falls back to the custom name (then the type).
- Custom blocks are **not** frame/locked and are freely movable/removable.

### #2 — Rename & delete

**Home tiles** (`home.js`) gain a `⋯` affordance per tile:

- **Custom** block / page / template → **Rename** and **Delete** (confirm first).
- **Built-in block** → **Rename** only (changes its display label; never
  deletable).
- **Built-in page type** → locked (neither); no menu.

**Rename storage:**
- Custom block name: the `names` map in the `_blocks` store (already exists).
- Built-in block display label: a new `labels` map in the `_blocks` store,
  overriding `DS.REG[type].label` in the studio UI (engine output unaffected).
- Custom page title: the page meta `title` (via `list_pages`/page meta).
- Template name: the template meta `name`.

**Delete:**
- Custom block: remove from `names`/`templates`/`settings` in `_blocks`, **and**
  strip any `{type}` instance from every page draft + template so nothing renders
  a dangling block. (The engine already skips a custom block with no template,
  but we remove the instances too, to keep layouts clean.)
- Custom page: delete its page meta + its layout rows.
- Template: existing `delete_template` (pages fall back to no template).

**New AJAX actions** in `ajax/design_layout.php`: `rename_block`,
`delete_block`, `rename_page`, `delete_page`, `rename_template`. (`create_*`,
`delete_template`, `assign_template` already exist and are the pattern to
follow.) Each is permission-checked exactly as the existing design actions are.

## Piece B — Save guard (#3)

The editors already autosave every change to the draft and flush on "Design
home", so this is a **confirmation guard**, not a new save/discard model
(autosave stays).

- Each editor tracks a session `dirty` flag, set on the first change after
  entry/after a manual Save, cleared by Save.
- Clicking **Design home** while `dirty` → confirm dialog:
  **"Save your changes before leaving?"** → *Save & leave* (flush + record
  history, then go home) / *Leave* (go home; the last autosaved draft remains —
  no silent data loss) / *Cancel*.
- `beforeunload` warns if `dirty` when closing/reloading the tab.

Applies to the block editor and the page/template editor.

## Piece C — Placeholders (#4, #5, #6, #7)

### #5 — Shared context usable in any block (engine)

- `index.php` builds `$GLOBALS['dl_shared_ctx'] = dl_page_shared_context($route['page'], $route['params'])` after routing, in both live and preview paths.
- `dl_render_block_template()` (`design/lib/template.php`) merges the shared
  context **beneath** the block's own `$ctx` (block keys win) before rendering.
  This makes `{{byline}}`, `{{author_bio}}`, `{{date_updated}}`, etc. resolve in
  any article-page block without editing each block file. Additive: existing
  templates are unaffected because their own keys still take precedence and
  unknown tokens already render empty.

### #6 — Separate date & read time

- `article_header.php` adds `date` and `read_time` to its context (keeping the
  combined `meta` for existing templates). The default `article_header` template
  is updated to use `{{date}}` and `{{read_time}}` in place of `{{meta}}` so new
  installs show them separately; existing saved templates keep working.
- These also live in the shared `article` context, so they are usable in every
  article-page block.

### #7 — New placeholders

Added to the shared `article` context (and therefore the registry):
`byline` (`dl_article_byline`), `author_bio`, `author_role`, `author_name`,
`author_photo`, `author_href` (from `dl_author_card`), `date_updated`
(`modified_date`), `date_published` (`created_at`). Each falls back to `''`.

### #4 — Placeholder manual (studio UI)

- **New file: `management/js/design_studio/placeholders.js`** — a reference panel
  (modal/overlay) listing every placeholder grouped by shared area and by block,
  each row showing *friendly label → `{{token}}`* with a **Copy** button.
- Opened from a **"Placeholders"** button on Design Home and inside each editor.
- **New AJAX action `placeholders`** returns `dl_placeholder_registry()` as JSON;
  the panel renders from it (no duplicated list in JS).

### #5 (UI half) — per-block listing

- In the **block editor**, show that block's placeholders (shared + block-
  specific) inline near the editor, from the same registry endpoint, each
  copyable. The page editor's block list links to the manual.

## Data flow

```
Studio (JS)
  home.js / *_editor.js / placeholders.js
     │  DS.api(...)  ──────────────▶ ajax/design_layout.php
     │                                  (load/save_draft/list_*/create_*/
     │                                   rename_*/delete_*/placeholders)
     │                                      │ reads/writes
     │                                      ▼
     │                               ten_design_layouts (drafts, _blocks store)
     │                               ten_design_versions (published)
     │
     ▼ iframe preview (?preview=1)
  /design/index.php
     routes → dl_page_shared_context() → $GLOBALS['dl_shared_ctx']
     dl_render_blocks() → each block builds $ctx
        → dl_render_block_template() merges shared ctx beneath $ctx
        → dl_render_template() fills {{tokens}}
```

## Error handling

- Registry endpoint failure: the manual panel shows a friendly error; editors
  still function.
- Deleting a custom block in use: instances stripped from drafts/templates in the
  same action; if the strip fails, the delete is rolled back and an error is
  returned (no half-deleted state).
- Shared-context build failure (e.g. article not found): returns `[]`; blocks
  behave exactly as today.
- All new AJAX actions validate the publication + permissions identically to the
  existing design actions and reject otherwise.

## Testing

Local PHP is **not** available and the engine runs on the server, so automated
PHP tests cannot run in this workspace. Strategy:

- **Pure-function checks** where feasible: `dl_placeholder_registry()` shape and
  the shared-context merge precedence are written so they *could* be unit-tested
  under PHP (and will be if a PHP runtime is added); logic verified by reasoning
  and by the link-rewrite-style simulation used earlier.
- **Manual verification on TPE** against a checklist:
  1. Create a custom block, add elements → it appears in every page's palette and
     can be placed, moved, removed.
  2. Rename and delete custom block/page/template; confirm built-in blocks rename
     but cannot be deleted and built-in pages are locked; a deleted custom block
     leaves no broken instance.
  3. Edit a page, then click Design home → save guard appears; Save & leave,
     Leave, and Cancel each behave correctly; tab-close warns.
  4. Placeholders manual lists every token with working Copy; block editor shows
     the block's placeholders.
  5. Put `{{byline}}`, `{{author_bio}}`, `{{author_role}}`, `{{date_updated}}`,
     `{{date}}`, `{{read_time}}` into the Article header → all render correctly on
     a real article preview.
  6. Existing templates/pages render unchanged (regression).

## Deployment note

The `/design/` engine files (`design/lib/*`, `design/blocks/*`, `design/index.php`)
are **not** in the `management` git repo; they deploy separately to the site's
`/design/` folder (per project deploy notes). The studio files
(`management/js/design_studio/*`, `management/ajax/design_layout.php`) are in the
repo. Both sides must be deployed together for a release.

## Open items (resolved defaults, flagged for review)

- `date_published` is sourced from `articles.created_at`; if a dedicated
  publish-date column is preferred, swap the source in `dl_page_shared_context`.
- Custom blocks are allowed on **all** pages; if some should be page-scoped later,
  the registry can carry a `pages` list like `DS.REG`.
