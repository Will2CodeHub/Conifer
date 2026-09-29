# Per-Publication Design System — Design Spec

**Date:** 2026-09-29
**Status:** Draft for review
**Author:** William + Claude
**Feature branch (management):** to be created off `master`

---

## 1. Purpose

Introduce a new, config-driven templating system for the TEN newspaper network so that:

1. Every site runs **one identical set of front-end page files** ("universal code"). A code change is made once and pushed to every site (`scp`/`rsync`/FTP) — **no per-site page files ever again**.
2. **All per-publication difference** — which blocks appear on which page, in what order, with which fonts/colours, carousels, adverts, etc. — is **stored in the database** and edited entirely from the **TEN News Sites** management module via a new **Design** button/modal.
3. A publication's editors can lay out the **front page, section pages, article pages, impressum and contact page**, set a **theme** (fonts/colours **and spacing/margins**) globally and per-block, and tune a **mobile** variant that inherits the desktop layout but can be made lighter.
4. Editing is **safe**: changes are a **draft** previewed in a **test folder**; the editor **publishes to live** only when happy, and can view **older versions** and **roll back** instantly if a published design causes a problem.

The first target is a new visual design for **The Paris Eye**, built and previewed at **`thepariseye.com/design/`**, using the **real database**. The feature is **flagged off for every publication except The Paris Eye** during development. Once approved, the same engine rolls out to all 11 news sites.

### Non-goals (this phase)
- Rewriting the existing live site pages (`generate_index_page.php`, `section.php`, `full_article.php`, …). They remain untouched; `/design/` is parallel and isolated.
- Migrating other publications onto the engine (that is the post-approval rollout).
- A visual drag-on-canvas WYSIWYG. The editor is a structured block-list + settings panel with a preview, not a free-form canvas.

---

## 2. Core principle — universal code, three per-site edges

The whole design hinges on separating what is **identical everywhere** from what is **per-site**.

**Universal (one copy, byte-identical on every site, deploy once → all sites):**
- The entire `/design/` renderer: `/design/index.php`, `/design/router.php`, `/design/lib/*`, `/design/blocks/*`, `/design/assets/*.css`, `/design/assets/*.js`.
- One host-agnostic `/design/.htaccess` (uses `%{HTTP_HOST}`, never a literal domain).
- The Management Tool's Design editor (lives in the management app, shared by all pubs).

**Per-site — only three touch points, each an existing convention:**
1. **Private folder** (outside docroot, e.g. `/home/<siteuser>/private/global_paths_secure.php`). Already sets `TEN_BASE_SITE_ABBREVIATION` + DB credentials via `putenv`/`getenv`. The renderer reads the site's identity from here and needs nothing else to know which publication it is. **No change to how this works** — the engine simply consumes `BASE_SITE_ABBREVIATION`.
2. **`.htaccess`** — set once per site. The engine's `/design/.htaccess` is written to be identical across sites (host-agnostic), so in practice this is copy-once.
3. **Standardised branding assets** — a fixed-name web folder each site populates once:
   ```
   /design/site/logo.png          (primary logo / eye badge)
   /design/site/logo-mobile.png   (optional lighter mark for small screens)
   /design/site/favicon.ico
   /design/site/og-default.jpg     (default social share image)
   ```
   Universal code always references `site/logo.png` etc. — it never has to know which paper it is. Each site drops its own files in.

**Everything else that differs between papers lives in the DB** (`ten_design_layouts`) and is edited from the Management Tool. Nothing per-site is hand-edited in code.

**Resulting deploy story:** edit design in the Management Tool (DB — instant, affects the chosen pub only) **+** push the universal `/design/` folder once whenever engine *code* changes.

---

## 3. Architecture — three parts joined only by the database

| Part | Lives in | Repo | Job |
|---|---|---|---|
| **Layout config** (`ten_design_layouts` table) | shared DB | — | Single source of truth: per publication × page × device → ordered blocks + settings + theme. |
| **`/design/` renderer** | parent site template docroot → deployed to each site | *not* the management git repo | Reads the config for the current pub+page, renders the ordered blocks, injects the theme as CSS variables. |
| **Design editor** | management app (`module-news.php` + new AJAX + JS + CSS) | this git repo | The Design button beside Feed/Edits. Pick a page, order/toggle blocks, set fonts/colours, switch Desktop/Mobile, Save → writes config JSON. |

**The database is the only integration seam.** The editor writes config; the renderer reads it. There is **no PHP include coupling** between the two, which keeps the two codebases (management repo vs. non-versioned site template) independent. Both already connect to the same DB via the `Core` singleton / `global_include.php`.

---

## 4. Data model

One new table. Layout is stored as **JSON per (publication, page_type, device)** — flexible enough for the widely varying layouts, and trivial for the editor to read/write whole.

```sql
CREATE TABLE ten_design_layouts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  publication   VARCHAR(16)  NOT NULL,        -- e.g. 'TPE' (BASE_SITE_ABBREVIATION)
  page_type     VARCHAR(32)  NOT NULL,        -- 'front' | 'section' | 'article' | 'impressum' | 'contact'
  device        ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
  layout_json   MEDIUMTEXT   NOT NULL,        -- { theme:{...}, blocks:[ {type, settings:{...}} ] }
  enabled       TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    VARCHAR(64)  NULL,
  UNIQUE KEY uniq_pub_page_device (publication, page_type, device)
);
```

### `layout_json` shape

```jsonc
{
  "theme": {
    "font_display": "\"Playfair Display\", Georgia, serif",
    "font_body":    "\"Lora\", Georgia, serif",
    "font_ui":      "\"Inter\", system-ui, sans-serif",
    "color_ink":    "#14142b",   // headlines / wordmark (near-black navy)
    "color_body":   "#333333",
    "color_muted":  "#6b6b6b",   // standfirst / meta
    "color_accent": "#1a2b4a",   // section rules ("LATEST HEADLINES" underline)
    "color_bg":     "#ffffff",
    "color_button": "#14142b",
    "container_max": "1200px",   // page content max width
    "gutter":        "24px",     // left/right page gutter (side margin)
    "section_gap":   "48px",     // vertical space between blocks (rhythm)
    "block_gap":     "24px"      // internal gap within a block (grid/column gap)
  },
  "blocks": [
    { "type": "site_header",    "settings": { "show_search": true, "show_subscribe": true } },
    { "type": "masthead",       "settings": { "tagline": "Independent journalism for the international community in France" } },
    { "type": "front_feature",  "settings": { "hero_source": "latest", "lead_source": "editor_pick", "sidebar_count": 5,
                                              "spacing": { "margin_top": "0", "margin_bottom": "48px", "padding": "0 24px" } } },
    { "type": "section_carousel","settings": { "section": "culture", "count": 10 } },
    { "type": "advert",         "settings": { "slot": "frontpage_mid" } },
    { "type": "footer",         "settings": {} }
  ]
}
```

**Spacing & margins** are first-class:
- **Page-level tokens** (`container_max`, `gutter`, `section_gap`, `block_gap`) emit as CSS variables (`--container-max`, `--gutter`, `--section-gap`, `--block-gap`) and drive the whole page's rhythm. Editable in the theme panel.
- **Per-block spacing** — any block may set `settings.spacing` (`margin_top`, `margin_bottom`, `padding`) which the renderer applies as a scoped wrapper style, so a single block's spacing can be tuned without touching the page defaults.
- Desktop and mobile each carry their own spacing values (the mobile diff can tighten gutters/gaps for phones), satisfying "set spacing and margins for desktop and mobile."

### Mobile = a diff of desktop
The `device='mobile'` row does **not** store a full independent layout. It stores only **overrides**:
- `hidden`: block types/ids to omit on mobile,
- `order`: an optional reordered list,
- per-block `settings` overrides (e.g. `count: 3` instead of `10`),
- optional `theme` overrides (e.g. smaller display size).

At render time the renderer loads the desktop layout, then applies the mobile diff when the request is mobile (or always emits both and lets CSS switch — see §5.4). Editing the Mobile tab writes only the diff. This satisfies "mobile follows desktop but can be made lighter."

### Draft → Publish → Live, plus version history & rollback

Editing must be **safe**: work on a draft, preview it in a test folder, publish to live only when happy, and roll back instantly if a published design causes a problem.

**Two states, one history table.**
- `ten_design_layouts` (above) is the **working draft** — what the modal edits and the **test folder** renders. Editing never touches what the public sees.
- A publish **snapshots the whole publication's design** (every page × device) into an immutable, numbered version:

```sql
CREATE TABLE ten_design_versions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  publication   VARCHAR(16)  NOT NULL,        -- e.g. 'TPE'
  version_no    INT          NOT NULL,        -- 1,2,3… per publication
  label         VARCHAR(120) NULL,            -- optional note, e.g. "New Paris serif look"
  snapshot_json MEDIUMTEXT   NOT NULL,        -- full bundle: all page_types × devices at publish time
  is_live       TINYINT(1)   NOT NULL DEFAULT 0,  -- exactly one live per publication
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    VARCHAR(64)  NULL,
  UNIQUE KEY uniq_pub_version (publication, version_no),
  KEY idx_pub_live (publication, is_live)
);
```

**Lifecycle**
1. **Edit** → writes the draft (`ten_design_layouts`). Test folder shows it immediately.
2. **Publish** → bundles the pub's draft pages into a new `ten_design_versions` row (`version_no = max+1`), sets `is_live = 1` and clears the previous live row. The **live site** renders the `is_live` snapshot.
3. **Version history** → the modal lists versions newest-first (`v3 · 29 Sep 14:32 · "New Paris serif look" · [Live]`), each with **Preview** and **Roll back**.
4. **Roll back** → sets a chosen older version `is_live = 1` (instantly changing what the live site serves) **and** copies its snapshot back into the draft, so further editing continues from the restored version. No data is destroyed — every published version is retained.

**What "live" means by phase:** in **Phase 1** the engine serves `thepariseye.com/design/`, so "live" = the published view at that subfolder (test folder shows the draft via `?preview=1`). In **Phase 2**, when the engine serves the real site root, publishing is exactly what promotes a design to the public site — same mechanism, no new concepts. This is the agreed "test on a test folder, then publish to the live site" flow.

---

## 5. The `/design/` renderer

### 5.1 Folder layout (universal)
```
/design/
  .htaccess                 # host-agnostic routing
  index.php                 # front controller — bootstraps, routes, renders
  router.php                # maps URL → (page_type, params)
  lib/
    bootstrap.php           # require global_include.php; resolve pub; guard feature flag
    config.php              # load + parse layout_json for (pub, page_type, device); apply mobile diff
    theme.php               # emit :root{ --font-*: ...; --color-*: ... } from theme tokens
    registry.php            # map block "type" -> renderer file; render loop
    data.php                # shared data helpers (reuse existing queries)
  blocks/
    site_header.php
    masthead.php
    front_feature.php
    headlines_list.php
    section_title.php
    section_lead.php
    article_grid.php
    article_header.php
    article_hero.php
    article_body.php
    article_byline.php
    article_comments.php
    section_carousel.php
    advert.php
    ticker.php
    breaking_news.php
    footer.php
    rich_text.php           # impressum/contact static content
  assets/
    design.css              # base + block CSS, all colours/fonts via CSS vars
    design.js               # carousel, mobile nav, search toggle
  site/                     # PER-SITE branding (see §2) — the ONLY non-universal files here
    logo.png  logo-mobile.png  favicon.ico  og-default.jpg
```

### 5.2 Request flow
1. `.htaccess` routes any `/design/...` request to `index.php`.
2. `bootstrap.php` requires `global_include.php` (gives `Core` DB handle + `BASE_SITE_ABBREVIATION`).
3. **Feature-flag guard:** if the pub is not design-enabled, respond 404/redirect (see §7). Only TPE passes during development.
4. `router.php` resolves the URL to a `page_type` and params:
   - `/design/` → `front`
   - `/design/<section>` → `section` (section = slug)
   - `/design/<slug>-<id>` → `article` (id captured like the live rules)
   - `/design/impressum`, `/design/contact` → those page types
5. `config.php` selects the **source** by mode: `?preview=1` (or the test folder default) reads the **draft** (`ten_design_layouts`); otherwise it reads the **live snapshot** (`ten_design_versions` where `is_live=1`). It then loads `(page_type, 'desktop')` and, if the request is mobile, applies the mobile diff.
6. `theme.php` prints the `:root` CSS-variable block from the theme tokens.
7. `registry.php` loops the `blocks` array; for each, includes `blocks/<type>.php` passing its `settings`; each block returns clean, token-styled HTML.
8. `index.php` assembles `<head>` (title/meta/structured data helpers reused from `backend/`) + the block HTML + `design.css`/`design.js`.

### 5.3 Block contract — reuse the data, replace the markup
Each block is a small renderer that **reuses the existing site's data logic** (the same SQL the live `backend/*` files run, filtered by `BASE_SITE_ABBREVIATION`) but outputs **fresh, token-based markup** whose every colour/font comes from CSS variables. This is the agreed approach (reuse queries, themeable markup) — it avoids dragging in the monolithic hardcoded styles while not re-inventing the data layer.

A block file receives `$settings` (array) and returns a string. Data helpers in `lib/data.php` wrap the reusable queries (latest headlines, section articles, most-read, a single article, breaking news, ticker) so blocks stay thin.

### 5.4 Theming
- All block CSS references variables: `color: var(--color-ink); font-family: var(--font-display);` etc.
- `theme.php` emits the values from `layout_json.theme` into `:root`.
- **Per-block overrides:** a block whose `settings.theme` is set gets a scoped wrapper (`<div class="blk" style="--color-ink:…">`) so a single section can restyle without affecting the page.
- Fonts load from **Google Fonts** (serif display + serif body + a UI sans), chosen to match the Figma (Playfair Display / Lora / Inter as starting points — final choice confirmed against Figma during build).

### 5.5 Responsive / mobile
- Base CSS is mobile-first; desktop layout via `@media (min-width: 768px)`.
- The mobile **diff** from the DB lets editors drop heavy blocks (e.g. a carousel) or reduce counts for phones, beyond what CSS alone does.

---

## 6. Block registry (seed set from the Figma designs)

Derived from the five Figma frames + the existing `backend/` component library. Each maps to reusable existing logic.

| Block `type` | Purpose | Reuses (existing logic) | Key settings |
|---|---|---|---|
| `site_header` | Logo badge + serif wordmark + nav + search + Subscribe | `backend/main_menu.php` (nav from `main_menu`) | `show_search`, `show_subscribe` |
| `masthead` | Centered big serif site name + italic tagline + rule | `BASE_SITE_NAME` | `tagline` |
| `front_feature` | 3-col: hero image · lead story · Latest Headlines | `top_headline`, `latest_headlines` queries | `hero_source`, `lead_source`, `sidebar_count` |
| `headlines_list` | "LATEST HEADLINES" sidebar w/ navy rule + read-time | `latest_headlines.php` | `count`, `show_readtime` |
| `section_title` | Big serif uppercase section name | route param | `uppercase` |
| `section_lead` | Section hero image + headline + summary | `section_articles.php` | `section` |
| `article_grid` | Grid/list of section articles below the lead | `section_articles.php` | `section`, `columns`, `per_page` |
| `article_header` | Section pill · centered headline · standfirst · divider · date·read | `insert_full_article.php` | — |
| `article_hero` | Full-width hero image (omitted in no-image variant) | article row `image_url` | `ratio` |
| `article_body` | Justified serif body | `full_article` content | — |
| `article_byline` | Author byline block | `ten_users`/byline resolution | — |
| `article_comments` | Comments box | existing comments | `enabled` |
| `section_carousel` | Horizontal card carousel of a section | `insert_section_articles_carousel.php` | `section`, `count` |
| `advert` | Ad slot | `frontpage_main_article_advert*.php` / `global_advert_insertion.php` | `slot` |
| `ticker` | News ticker strip | `get_ticker_data.php` | `source` |
| `breaking_news` | Breaking-news block (respects `breaking_news_enabled`) | `insert_breaking_news_frontpage.php` | — |
| `footer` | Footer with columns | `footer_widget.php` | — |
| `rich_text` | Static content (impressum/contact) | `impressum.php`/`contact.php` content | `content_key` |

New block *types* the Figma needs beyond the existing library: essentially the **no-image article** handling (a variant of `article_hero`/`article_header`, not a new query) and the **centered article header with pill + standfirst** styling — both markup/CSS work, not new data.

---

## 7. Feature flag & isolation

- Add a flag column: `publications.design_enabled TINYINT(1) NOT NULL DEFAULT 0`. Set **only TPE = 1** during development.
- The `/design/` renderer's bootstrap **refuses** any pub whose `design_enabled != 1` (404 or redirect to the live homepage). So even if the folder is deployed everywhere, only TPE serves it.
- In the Management Tool, the **Design** button shows for every pub row but is **disabled/greyed** (with a tooltip) unless that pub is design-enabled — so the team can see it's coming without being able to edit non-enabled sites yet.
- Zero impact on the live site: everything is under `/design/` and new DB rows/columns.

---

## 8. The Design editor (management module)

### 8.1 Entry point
In `module-news.php`, add a **Design** button in each publication row's action group, beside **Feed** and **Edits**. Clicking opens a large modal (`#ns-design-modal`).

### 8.2 Modal layout
- **Top bar:** publication name · **Page selector** (Front / Section / Article / Impressum / Contact) · **Desktop | Mobile** tabs · **Save draft** · **Publish** · **History**.
- **Left column — Block list:** the ordered blocks for the current page. Each row: drag handle (reorder), name, visibility toggle, settings (gear), remove. An **"+ Add block"** button opens the **block palette** (the registry list, filtered to those valid for the page type).
- **Centre — Preview:** an `<iframe>` pointing at `/design/<page>?preview=1&pub=TPE` (draft mode) that refreshes on change, so editors see the real result. (MVP: refresh-on-save; live refresh is a nice-to-have.)
- **Right — Settings panel:** context-sensitive.
  - With a block selected → that block's settings **+ its spacing** (`margin_top`, `margin_bottom`, `padding`).
  - With nothing selected → **page theme**: font pickers (display/body/ui), colour pickers (ink/body/muted/accent/bg/button), and **spacing controls** (`container_max`, `gutter`, `section_gap`, `block_gap`) — all per the active Desktop/Mobile tab.

### 8.3 Publish & history UI
- **Save draft** persists the working draft only (nothing public changes).
- **Publish** snapshots the whole publication's design to a new version, sets it live, and (Phase 1) makes it the design served at the live `/design/` view. A confirm dialog offers an optional label.
- **History** opens a panel listing versions newest-first with timestamp, author, label, and a **[Live]** badge; each row has **Preview** (opens that version in the test folder) and **Roll back** (confirm → makes it live and restores it into the draft).

### 8.4 Persistence
- AJAX endpoint `ajax/design_layout.php` with actions: `load` (pub, page, device, mode=draft|live), `save_draft` (pub, page, device, layout_json), `publish` (pub, label), `list_versions` (pub), `rollback` (pub, version_no). Reuses the app's auth/session and role checks.
- `save_draft` validates block types against the registry (server-side allow-list) and writes `ten_design_layouts`.
- Mobile tab `save_draft` writes only the diff for `device='mobile'`.
- `publish`/`rollback` write/point `ten_design_versions` (§4) transactionally.

### 8.5 Files (management repo)
- `module-news.php` — add button + modal markup.
- `ajax/design_layout.php` — load/save-draft/publish/list-versions/rollback.
- `js/news_sites_design.js` — modal logic, drag-order, palette, settings, theme + spacing pickers, publish/history/rollback, preview refresh.
- `css/news_sites_design.css` — modal styling.
- `lib/design_layouts_db.php` — DB read/write for drafts + versions, snapshot/restore helpers, default-seed helper.

---

## 9. Design language (from the Paris Eye Figma)

The seed TPE theme + layouts reproduce the Figma:

- **Typography:** serif display (Playfair Display-like) for masthead/section-titles/headlines; serif body (Lora/PT Serif-like); sans (Inter-like) for nav, `LATEST HEADLINES` labels, meta.
- **Palette:** ink `#14142b`, body `#333`, muted `#6b6b6b`, accent navy `#1a2b4a`, bg `#fff`, dark Subscribe button.
- **Header:** circular "The Eye Newspapers" ring badge + serif wordmark left; nav centre; search + Subscribe right.
- **Front page:** centered masthead + italic tagline + hairline rule; then 3-col (hero image · lead story image+headline+summary · Latest Headlines list).
- **Section page:** large serif uppercase title; lead hero + headline; Latest Headlines sidebar with "X min read" and navy top rule; article grid below.
- **Article page:** centered — grey section pill, big serif headline, grey serif standfirst, short centered divider, `date · N min read`, full-width hero image, justified serif body, byline, comments.
- **No-image article:** identical header block, hero omitted, flows straight into body.

Seed `layout_json` for all five TPE pages (desktop + a light mobile diff) is produced during implementation and inserted via a seed script.

---

## 10. Per-site setup checklist (for rollout, documented now)

For each new site brought onto the engine:
1. Ensure the private folder sets `TEN_BASE_SITE_ABBREVIATION` (already present).
2. Deploy the universal `/design/` folder (identical bytes).
3. Drop the site's branding files into `/design/site/` (standard names).
4. Ensure `/design/.htaccess` is present (identical host-agnostic file).
5. Set `publications.design_enabled = 1` for that pub.
6. Create/seed its `ten_design_layouts` rows (or clone TPE's as a starting point) and adjust in the Design modal.

---

## 11. Deployment

- **Management app** (editor): committed to this git repo; deployed as the management app normally is.
- **`/design/` engine**: lives in the parent site-template docroot (not this git repo). Deployed to `thepariseye.com/design/` by William (per deploy notes, Paris site code is pushed by William, not the theeyenewspapers.com FTP user). Rollout later copies the same folder to every site.
- **DB migrations**: `ten_design_layouts` + `ten_design_versions` tables + `publications.design_enabled` column, run once against the shared DB via a migration script in the repo.

---

## 12. Open questions / to confirm during build
1. Final font choices vs. the Figma (Playfair/Lora/Inter are the working assumption).
2. Exact `/design/` article URL pattern (mirror live `-<id>` scheme vs. a `?id=` for the test phase).
3. Whether the preview iframe renders via the live `/design/` (needs deploy) or a management-side mirror during development — likely the deployed `/design/?preview=1` since we deploy to the live subfolder anyway.
4. Roles/permissions: which management roles may open/save Design (reuse existing News Sites permissions).

---

## 13. Phasing

- **Phase 1 (this spec):** `ten_design_layouts` + `ten_design_versions` + `design_enabled`; the `/design/` engine with the block registry above; theme + spacing controls; draft→publish→live with version history & rollback; seed TPE layouts from Figma; the Design modal in TEN News Sites; flagged to TPE only; deploy to `thepariseye.com/design/`.
- **Phase 2 (post-approval):** roll the engine out to all 11 sites; per-site branding + enable flags; optional live-refresh preview; migrate/retire the old per-site page files.
