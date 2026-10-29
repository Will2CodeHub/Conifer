# Design Studio — Block & Placeholder editor rework

Date: 2026-10-27
Branch: feature/design-system
Status: approved (William: "do and deploy")

## Problem

The Page editor is fine. The **Block editor** is a single toolbar-driven WYSIWYG
(`html_editor.js`) that treats every block — including dynamic data regions
(`section_carousel`, `article_grid`, `front_sections`, `headlines_list`,
`front_feature`, …) — as free-form HTML. It is messy, buggy, and the wrong tool
for structured regions. Specific complaints:

1. Margin/padding is a single value — no per-side (T/R/B/L) control.
2. The insert palette shows *all* placeholders, not just this block's.
3. No way to design a `{{placeholder}}` token according to *what it is*.

## Decisions (from brainstorming)

- **Placeholder = a `{{token}}`.** Selecting a token in the canvas opens controls
  tailored to the token's **kind**.
- **UI archetype = Framer/Figma:** canvas-first, a floating/contextual
  **properties inspector** that swaps its controls by selection, direct
  on-canvas spacing handles, a slim Layers + Insert rail.
- **Evolve, don't rewrite.** Keep the verified engine, the `{{token}}` model, the
  `_blocks` store and the live-preview bridge. No new tables. TPE-only
  (`design_enabled`) until signed off.
- **Storage:** token/element styles continue to be written as **inline styles on
  the element** in the block's HTML template (the existing mechanism). List
  (`{{#each}}`) structural choices — columns/gap/fields/count — are written as
  inline styles + data-attributes on the loop region's container, so the engine
  needs no change (it already renders the stored template verbatim).

## Placeholder taxonomy (7 kinds + container)

A classifier maps every token to a kind; each kind renders only sensible controls.

1. **Heading/title** — font, size, weight, line-height, letter-spacing, colour,
   transform, align, max-lines, link colour.
2. **Body/summary** — font, size, line-height, colour, measure (max-width),
   max-lines, paragraph spacing.
3. **Meta/label** — small-UI type, colour (muted), transform, letter-spacing,
   size, prefix/suffix + separator.
4. **Image** — aspect ratio, object-fit, width/height, radius, border, hover zoom.
5. **Link/button** — mode (text-link | button); link colour, underline, hover;
   button → bg, text colour, per-side padding, radius, border.
6. **List/repeating** (`{{#each}}` region) — layout (grid/row/carousel), columns,
   gap, count, per-field show/hide toggles, image ratio, divider, align.
7. **Icon** — size, colour, spacing.
8. **Container / block** — background, per-side margin & padding, max-width,
   columns, gap, border, align.

Classification lives in `registry.js` (`DS.tokenKind(block,token)`) + a PHP mirror
only if the engine needs it (it does not for v1 — styling is inline).

## Editor layout

```
┌──────────────────────── top bar ───────────────────────┐
│ ◀ Home   Block name    [Desktop|Mobile]  ↶ ↷   Save     │
├────────┬──────────────────────────────┬─────────────────┤
│ Layers │        live canvas           │  Inspector      │
│  tree  │   (iframe, block rendered,   │  (swaps by      │
│ ───────│    tokens as chips, loop     │   selection     │
│ +Insert│    ghosts, selection chrome, │   kind)         │
│ scoped │    on-canvas spacing handles)│                 │
└────────┴──────────────────────────────┴─────────────────┘
```

- Canvas = the existing iframe editing surface (keeps block CSS, tokens, ghosts).
- Selecting an element/token → `renderInspector(el)` classifies + renders the
  typed panel, pre-filled from `cv()` (inline else computed). Edits call
  `styleEl`/`styleElBox` → `changed()` → autosave + preview.
- `</>` raw HTML stays as a secondary escape hatch (toolbar button).
- Insert palette scoped to this block's tokens + shared page tokens.

## Spacing box-model widget

`DS.boxModel(el, prefix)` renders the nested margin→padding widget with four
editable sides (T/R/B/L) + on-canvas drag handles (reuse `mountGridHandles`
pattern). Writes `${prefix}Top/Right/Bottom/Left`.

## Phasing

1. `registry.js`: `DS.tokenKind()` classifier + `DS.KIND_CONTROLS` map.
2. CSS: 3-pane shell, inspector, box-model, selection chrome (`news_sites_design`
   studio CSS is separate; studio uses `design-studio.php` inline + a studio CSS).
3. `html_editor.js`: `mount()` DOM → rail+canvas+inspector; replace
   `buildToolbar`/`syncToolbar` with `renderInspector`; keep core intact.
4. Inspector panels: container + kinds 1–5,7.
5. Kind 6 (list/section-articles) structured controls.
6. Per-side spacing widget + scoped palette.
7. `block_editor.js`: host the new shell; keep save/undo/history/preview.
8. `node --check`; deploy to TPE (tenuser management JS); verify in William's
   logged-in browser (Claude-in-Chrome). Mirror `design_registry.php` if touched.

## Testing / verification

- JS gate: `node --check` every file.
- No local PHP engine — verify endpoints + rendering in William's browser.
- Smoke: open a text block (rich_text) → style a title token; open a dynamic
  block (section_lead / article_grid) → list panel changes columns/fields; margins
  per-side; Save → preview reflects; other pubs untouched (TPE-only flag).

## Risk / rollback

Old `news_sites_design.js/.css` remain as fallback. Changes are additive to the
studio JS; `_blocks` store format unchanged (still inline styles in templates), so
a bad deploy reverts by restoring the previous JS files over FTP.
