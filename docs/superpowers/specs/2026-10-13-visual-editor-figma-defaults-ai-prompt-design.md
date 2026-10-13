# Design — Visual editor + Figma default templates + AI edit prompt

**Date:** 2026-10-13
**Branch:** `feature/design-system`
**Status:** Approved (brainstorm) — building in stages, deploying to the TPE test phase as each stage lands.

Builds on the existing per-publication Design System (`2026-09-29-per-publication-design-system-design.md`). This
spec covers three interlocking additions to the TEN News Sites **Design** feature, delivered as one combined effort.

---

## 1. Problem

The Blocks editor is an in-house WYSIWYG over a contenteditable iframe. It exposes the template machinery —
`{{field}}` chips, `↻ nav` (`{{#each}}`), `? show_search` (`{{#if}}`) — which is meaningless to a non-technical
editor. William's complaint: *"this is a complete mess for a non-technical person to edit."* Three things are needed:

1. A genuine **visual editor** where the editor sees a real-looking page and edits it directly; no template syntax.
2. The two **Figma page designs** seeded as the defaults for the relevant page types (an enriched article page, and a
   brand-new author/journalist profile page).
3. An **AI edit prompt** (via the Anthropic API) that restyles the whole design, or edits a selected element, from a
   plain-English instruction prepended to a fixed design-system brief.

## 2. Decisions (from brainstorm)

- **Editor model:** live-preview visual editor (Approach A — *annotated live render + structured edits*). The preview
  renders with real/sample data; edits flow through invisible anchors, never by re-parsing HTML. Template stays the
  source of truth; raw `{{…}}`/`↻`/`?` are never shown.
- **Figma defaults:** apply **Design A** (article page) and **Design B** (new author profile page type). Author page is
  a real new page type backed by existing author records.
- **AI scope:** **both** modes behind a toggle — "Restyle the whole design" (theme tokens) and "Edit this element"
  (anchored block edit). Everything lands in the draft; preview; undo; existing publish/history/rollback.
- **Rollout:** TPE-only during the test phase (existing `publications.design_enabled` gate), then per-pub.

## 3. The two Figma designs (captured 2026-10-13)

**Design A — Article page** (`node 508-1735`), top→bottom:
- Site header: serif wordmark ("The Paris Eye"), nav (NEWS BUSINESS FASHION POLITICS HEALTH EXPAT LIFE), black
  **Subscribe** button (sharp corners).
- Article hero block: **centered** category tag (small, uppercase, Inter, grey box), large **Lora** headline (centered),
  thin short horizontal rule, **Inter** byline ("September 22 2026 · 4 min read", centered, grey).
- Article body: **Merriweather**, left-aligned, ~720px measure, generous paragraph spacing.
- Author bio block: round avatar, name (Inter bold), role (grey), "Read all articles by X →", LinkedIn + email icons;
  thin top rule.
- "Related" — 3-up grid: image, category tag, serif title, date.
- "Quick Reads News" — 3-up text-only list: category tag, serif title, "X min read".
- Comments block: "Join the conversation…" textarea, "Posting as Guest", black **Post Comment** button.
- Footer: dark (near-black), 4 columns (brand blurb, SECTIONS, CONNECT, SERVICES), copyright + social icons.

**Design B — Author / journalist profile page** (`node 509-1166`):
- Same header + footer.
- Breadcrumb: "The Paris Eye / Our journalists / Hannah Mones" (Inter, grey).
- Profile: round avatar (left) + **Lora** name (right), Inter role subtitle (uppercase, letter-spaced, grey),
  **Merriweather** bio paragraphs.
- Social row: X ↗, LinkedIn; outlined **Contact Author** button (sharp corners, mail icon).
- "Articles by X" list: each row = category tag, **Lora** title, Inter standfirst, "date · X min read", right-aligned
  thumbnail; thin rule between rows.

**Shared design language (the AI default prompt, encoded):** 3-font system — **Lora** (headlines/titles),
**Merriweather** (article body only), **Inter** (UI/meta/tags/captions); **zero border-radius** everywhere; generous
whitespace; editorial authority.

## 4. Architecture — where each piece lives

Two code homes, joined only by the `admin_ten` DB (unchanged seam from the base system):

- **Site template `theeyenewspapers_website_template/design/`** (NOT git-tracked; deployed to `thepariseye.com` via
  `tpeuser`): the `/design/` renderer. Changes here: fonts, theme tokens, block templates/CSS, the new author page type
  + route + data helpers.
- **Management repo `management/`** (git-tracked `feature/design-system`; deployed to `theeyenewspapers.com/management`
  via `tenuser`): the editor UI + AJAX + the AI endpoint. Uses mysqli `getDBConnection_TENAdmin()`.
- **`design/lib/design_registry.php`** is the MASTER copy and is duplicated VERBATIM into the site template; both the
  renderer and the editor read it. Any change to page types / theme defaults / block registry must be copied to both.

## 5. Component designs

### 5.1 Theme tokens — a real typographic scale (foundation)

Extend `dl_default_theme()` (and the `theme.php` CSS-var map) from the current flat token set to a full editorial scale,
so (a) the Figma look is reproducible from tokens and (b) the AI "restyle" mode has structured outputs to write.

New/changed tokens (all emitted as CSS variables, consumed by block CSS):

| Token | Var | Default (Figma) |
|---|---|---|
| `font_display` | `--font-display` | `"Lora", Georgia, serif` |
| `font_body` | `--font-body` | `"Merriweather", Georgia, serif` |
| `font_ui` | `--font-ui` | `"Inter", system-ui, sans-serif` |
| `h1_size` `h1_weight` `h1_line` `h1_spacing` | `--h1-size` … | `clamp(2.4rem,4vw,3.4rem)` / 700 / 1.12 / -0.01em |
| `h2_size` … / `h3_size` … | `--h2-*` `--h3-*` | editorial scale |
| `body_size` `body_line` | `--body-size` `--body-line` | `1.15rem` / `1.8` |
| `body_measure` | `--body-measure` | `44rem` (~720px) |
| `ui_size` `ui_spacing` | `--ui-size` `--ui-spacing` | `0.8rem` / `0.08em` |
| `caption_size` | `--caption-size` | `0.85rem` |
| `radius` | `--radius` | `0` (zero-border-radius rule) |
| colours + `container_max` `gutter` `section_gap` `block_gap` | (as today) | navy-ink monochrome |

`dl_theme_css()` gains the new keys (same safe-value stripping). Backward compatible: missing keys fall back to defaults
via `array_merge(dl_default_theme(), …)` in `dl_validate_layout`/`dl_resolve`. Fonts: `index.php` loads
Lora + Merriweather + Inter from Google Fonts (replace the Playfair link); keep weights needed (Lora 400/600/700,
Merriweather 400/700, Inter 400/500/600/700).

### 5.2 Figma default templates (Design A — article)

Update the built-in block default templates (`block_templates.php`) + block CSS (`design/assets/design.css`) to match
Design A. Blocks touched: `site_header` (Subscribe sharp button), `article_header` (centered category pill + Lora title
+ short rule + Inter meta — merge `article_hero` position), `article_body` (Merriweather, measured), a new/!reworked
**`article_byline`** rendered as the **author bio card** (avatar, name, role, "Read all articles →", social icons),
`article_grid` reused as the "Related" 3-up, `headlines_list` reused as "Quick Reads News", `article_comments`
(guest form + Post Comment), `footer` (dark 4-column). Defaults are seeded into `ten_design_layouts` for TPE and
published into `ten_design_versions` by the seed.

### 5.3 New author page type (Design B)

- **Registry:** add `'author'` to `dl_page_types()` (NOT a static page — it takes params), a label "Author page", and
  new author-only blocks to `dl_registry()`: `author_profile` (breadcrumb + avatar + name + role + bio + socials +
  Contact Author) and `author_articles` (the "Articles by X" list). Copy the registry to both homes.
- **Route:** `router.php` gains `/design/author/<id>` (and `/design/author/<id>-<slug>`), returning
  `['page'=>'author','params'=>['id'=>…]]`. `index.php` preview `?page=author` supplies a representative author id.
- **Data:** new helpers in `data.php`: `dl_author(int $id): ?array` (reads `TEN_Management.ten_users` by `id` **or**
  `public_id` — cross-schema read already used by the live bio page), returning name/pen_name/position/bio/photo +
  socials; `dl_author_articles(int $id, int $limit)` (published articles where `journalist_id = id`, window-filtered,
  reusing the existing patterns). Image/byline helpers reused.
- **Blocks:** `design/blocks/author_profile.php`, `design/blocks/author_articles.php`, each building a `$ctx` and
  rendering via `dl_render_block_template()` with defaults in `block_templates.php`.

### 5.4 Author socials — schema add

`ten_users` has no social/contact-link fields. Add three nullable columns: `x_url VARCHAR(255)`,
`linkedin_url VARCHAR(255)`, `contact_email VARCHAR(255)` (contact email falls back to `email` when blank).
Additive, reversible migration script run in-browser (admin), consistent with prior `run_*_migration.php` scripts.
Edited later from User Management (out of scope for this round beyond adding the columns; author page reads them,
renders the link only when present).

### 5.5 Live-preview visual editor (Approach A)

The core change in `management/`. "Blocks" mode becomes the default visual surface; the current source editor is kept
behind an **Advanced ⟨⟩** toggle for power users.

**Anchors (the seam).** Templates carry invisible data-attributes that tie rendered DOM back to template positions.
Extend the existing `data-dl-edit="key"` convention with:
- `data-ed-static="key"` — editable static label (already `data-dl-edit`; keep that name).
- `data-ed-field="path"` — a `{{field}}`-bound value: show live sample, **text locked**, style/move/delete allowed.
- `data-ed-loop="name"` on a `{{#each}}` wrapper's rendered container, `data-ed-item` on the repeated item.
- `data-ed-if="name"` on a `{{#if}}` region.
The renderer emits these in **preview mode only** (`$ctx['live']` false) so live pages stay clean. Implemented by a
thin pass in the block render / template layer keyed off preview mode.

**Editor UI** (`js/news_sites_design.js`, `css/news_sites_design.css`, `module-news.php`):
- Preview iframe (existing `?preview=1&page=&device=`) becomes interactive: hovering shows the three affordances
  (editable = blue outline + pencil; auto-filled = grey outline + lock chip "… filled automatically"; repeating =
  dotted outline + "Repeats for each …" ribbon).
- Top toolbar: **Insert ▾** (Heading, Paragraph, Image, Button, Link, Divider, Quote, List, Columns 2/3) inserts clean
  theme-styled HTML at the click point; **Text style** (shown on text selection: bold/italic/heading/align/list/link,
  reusing today's Range-based `wrapInline`/`formatBlockSel`/`listSel`/`alignSel`/link dialog — NOT `execCommand`);
  **Undo/Redo**; **AI ▾** (§5.6); **Advanced ⟨⟩** (source view).
- Selecting an element shows move-up / move-down / duplicate / delete + the existing per-element style panel (font,
  size, weight, colour, spacing, align, border/radius) writing to `settings.styles[selector]`.
- Edits mutate the block template (static text, inserted elements, structure) or `settings.styles`, autosave (700ms) to
  the `_blocks` draft, and refresh the preview — the existing save path. `{{field}}` text and loop/if structure are
  never editable as text, so round-tripping stays safe.

**Cross-origin note.** The preview iframe is `thepariseye.com` (different origin from the mgmt app); direct DOM reach is
blocked. Interaction is driven via `postMessage`: a small controller script served by `/design/` (preview mode only)
reports hovers/clicks/anchors to the editor and applies highlight/insert previews; the editor sends edit intents back.
The authoritative mutation is still the template JSON saved through `ajax/design_layout.php`; the iframe is the surface.

### 5.6 AI edit prompt (Anthropic API)

New management endpoint `ajax/design_ai.php` (git-tracked), POST, auth-gated like the other design AJAX. Follows the
existing Claude call pattern (`ajax/ajax_cv_parser.php`): `ANTHROPIC_API_KEY`, `https://api.anthropic.com/v1/messages`,
`anthropic-version: 2023-06-01`, a current Claude model, `max_tokens`, JSON body, cURL.

**Fixed default prompt** (prepended to the user's instruction) — the Lead UI/UX Designer / typography brief William
supplied (Lora headlines, Merriweather body, Inter UI; strict scale, weights, line-height, letter-spacing, vertical
rhythm; zero-border-radius). Stored server-side as a constant so it can't be tampered with client-side.

Two modes (UI toggle in **AI ▾**):
- **Restyle whole design.** Input: current theme tokens + the user instruction. Output contract: a strict JSON object of
  **theme tokens only** (the §5.1 keys) + optional `custom_css`. Server validates keys against the known token set,
  sanitises values (same stripping as `dl_theme_css`), merges into the draft layout's `theme`, autosaves, previews.
  Cannot touch HTML or `{{fields}}` — safe by construction.
- **Edit this element.** Input: the selected element's anchor + the block's current template + the user instruction.
  Output contract: the **rewritten block template** (or just the targeted fragment). Server validates it still
  round-trips (all original `{{…}}`/`{{#each}}`/`{{#if}}` tokens preserved — reject if any are dropped/added), then
  saves to the `_blocks` draft, previews.

Everything is a draft edit: preview first, **Undo**, and the existing publish/history/rollback. No live change until
Publish. The API key never reaches the client; the endpoint returns only validated token/template results.

## 6. Data flow (unchanged seam)

Editor (mysqli, `admin_ten`) writes draft `ten_design_layouts` → `/design/?preview=1` (PDO Core) reads the draft and
renders with anchors → editor iframe surfaces it → edits saved back → **Publish** snapshots into `ten_design_versions`
(`is_live=1`) → live `/design/` reads the snapshot. AI writes the same draft rows. Author page adds read-only
`ten_users`/`articles` queries. The only schema change is the three `ten_users` social columns (§5.4).

## 7. Error handling & safety

- Renderer stays fail-soft (every data helper try/catch → empty). New author helpers follow suit (missing author → 404
  via router/`dl_boot` pattern or an empty state).
- AI endpoint: on invalid/malformed model output, or a token-preservation failure (edit mode), return an error and make
  **no** draft change; the editor shows a toast and leaves the draft untouched.
- Anchors are additive data-attributes, emitted in preview only; they cannot change live rendering.
- Feature stays gated to design-enabled pubs (TPE) for the test phase.

## 8. Testing / verification

No local PHP. Verify by: run migration + seed in-browser (admin, logged in); deploy `/design/` to `thepariseye.com`
(`tpeuser`) and `management/` to `theeyenewspapers.com` (`tenuser`); view in the browser pane. Per piece:
- Theme/fonts/Design A: `/design/read/<id>/<slug>` renders the Figma article page; 0 leftover `{{placeholders}}`;
  Lora/Merriweather/Inter load; zero radius.
- Author page: `/design/author/<id>` renders Design B with a real author + their articles.
- Editor: hover affordances correct; Insert adds elements; text edits + style edits autosave and round-trip; source
  view clean; 0 console errors.
- AI: restyle returns valid tokens and re-themes the preview; edit-element rewrites a block and preserves all tokens;
  bad output makes no change.
- Round-trip integrity test for the template token-preservation check (a small PHP assertion run via the CLI pattern
  used before, or a headless same-origin iframe assertion).

## 9. Build stages (each deployed to TPE + verified before the next)

1. **Foundation — theme scale + fonts + Design A article CSS/templates.** `design_registry.php` (both copies),
   `theme.php`, `index.php` fonts, `block_templates.php`, `design/assets/design.css`; seed; deploy tpeuser; verify
   article page.
2. **Author page type.** registry (both), `router.php`, `index.php` preview, `data.php` helpers, two new blocks +
   templates + CSS; `ten_users` social migration; seed author layout; deploy; verify `/design/author/<id>`.
3. **Visual editor (Approach A).** Anchor emission in preview; `/design/` postMessage controller; editor UI rebuild in
   `js/news_sites_design.js` + CSS + `module-news.php` + `ajax/design_layout.php`; deploy tenuser (+ `/design/`
   controller to tpeuser); verify.
4. **AI prompt.** `ajax/design_ai.php` + the two modes + UI in the editor; deploy tenuser; verify both modes + safety.

Management changes are committed to `feature/design-system`. `/design/` changes are not in git (template root is not a
repo); they are deployed by WinSCP and noted for William where another pub needs them.

## 10. Out of scope (this round)

User-Management UI to edit the new author social fields (columns added now; editing later); rolling the feature beyond
TPE; migrating remaining blocks already covered by the base system; GPHI stats and the section/submenu editor (tracked
separately).
