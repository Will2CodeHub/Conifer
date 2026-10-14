# Design Editor — Unified "select → inspect" UX Implementation Plan

> **For agentic workers:** this plan is executed inline in this session. Steps use checkbox (`- [ ]`) syntax. There is NO unit-test harness for this editor; each task's verification is an in-browser check against the live TPE preview (`thepariseye.com/design/?preview=1`) after deploy, plus reading the posted-message payloads via the browser console.

**Goal:** Replace the confusing two-mode design editor (Page mode + a hidden "Max preview → Blocks template" mode) with one model: click any element in the live preview, a right-hand inspector shows **Content / Link / Style** for just that element and applies live; the main left rail only adds/reorders/removes blocks.

**Architecture:** Keep the proven internals (postMessage preview bridge, `settings.styles[selector]` scoped-CSS system, token/template save path). (1) Remove the `pvMax`→`enterBlockMode` mode-switch; make the maximise button a pure "Fullscreen" view toggle; demote the raw-HTML template editor to a clearly-labelled Advanced escape hatch. (2) Add a new `settings.links[selector] = href` override applied server-side at render (mirroring how `settings.styles` becomes scoped CSS) so a per-element link persists to the crawlable live site without JS. (3) Add a **Link** section to the element inspector reusing the existing link-target list. (4) Preview bridge reports the selected element's nearest anchor + current href and applies a live `href`. (5) CSS polish.

**Tech Stack:** Vanilla JS (`management/js/news_sites_design.js`), PHP design engine (`design/lib/registry.php`, `design/blocks/*`), preview bridge (`design/assets/design.js`), CSS (`management/css/news_sites_design.css`). Deploy: WinSCP saved sessions — `/design/*` → `tpeuser`, `management/*` → `tenuser`.

## Global Constraints
- Links must persist to the **live, non-preview** site as real `href` attributes (news site — crawlable, must work with JS off). No client-side-only link application on the live site.
- Do not break the existing `settings.styles` / `custom_css` / `custom_html` / token-template systems; the raw-HTML template editor stays as an advanced option.
- Dynamic content (article title/summary from DB) stays read-only in Content; still styleable/linkable/arrangeable.
- Selector overrides are scoped per block instance (`.dl-iN`), same as styles.
- Verify every task in-browser on TPE; deploy `/design/*` via `tpeuser`, `management/*` via `tenuser`, no `/ini=nul`.

---

### Task 1: Remove the mode-switch; make maximise a pure Fullscreen toggle
**Files:** Modify `management/js/news_sites_design.js` (`togglePvMax` ~254-261, build button ~141, click handler ~209-217, `renderBlockSettings` advanced button ~1261-1266), `management/css/news_sites_design.css` (`.dl-pvmax`/`.dl-max`).
- [ ] Relabel button `#dlPvMax` to "⛶ Fullscreen" / "⛶ Exit fullscreen"; remove the "click any part to edit its HTML" toast.
- [ ] In the preview click handler, delete the `if (st.pvMax && d.blockType ...) enterBlockMode(...)` branch so a click ALWAYS just selects (never jumps into template mode).
- [ ] Keep `enterBlockMode` reachable ONLY via the demoted "⟨⟩ Advanced: edit raw HTML & links" button. Reword it "⟨⟩ Advanced: edit block template (developers)".
- [ ] Fullscreen CSS: `.dl-pvmax` widens the preview but the right inspector stays visible (so selecting still works in fullscreen). Left block-rail may hide in fullscreen.
- [ ] **Verify:** deploy JS; open editor on TPE; Fullscreen no longer changes what a click does; clicking a block/element selects it and the inspector stays.

### Task 2: Server-side per-element link override at render
**Files:** Modify `design/lib/registry.php` (`dl_render_blocks`, add `dl_apply_link_overrides`).
- [ ] After `$inner` is built and before custom_html append, if `settings.links` is a non-empty map, run `$inner = dl_apply_link_overrides($inner, $settings['links'])`.
- [ ] `dl_apply_link_overrides(string $html, array $links): string` — for each `selector => href`: resolve simple selectors (`.class`, `tag`, `tag.class`) to the matching `<a>`(s) OR the nearest ancestor/descendant `<a>`, and set/replace their `href`. Use `DOMDocument` with a UTF-8 guard (`mb_convert_encoding` + `LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD`), XPath for class/tag match; on any parse failure return `$html` unchanged (fail-soft). Escape/validate href (allow `/…`, `https?://…`, `mailto:`, and `{{token}}` passthrough is NOT needed here — tokens already resolved).
- [ ] **Verify:** temporarily hand-add `"links":{".dl-view-all":"/design/about"}` to a TPE block's settings via the editor draft (or a one-off), deploy registry.php, confirm the view-all link's href changed on the live preview and with JS disabled (view-source).

### Task 3: Preview bridge reports anchor + applies live href
**Files:** Modify `design/assets/design.js` (`postSelect` ~55-65, message handler ~121-150).
- [ ] In `postSelect`, also compute the nearest anchor for the clicked element: `var a = elm.closest('a') || elm.querySelector('a');` and include `anchorHref: a ? a.getAttribute('href') : null` and `anchorSelector: a ? elemSelector(a, blk) : null` in the posted message.
- [ ] Add a `live_href` message handler: `{action:'live_href', selector, href}` → set `href` on matching `blk.querySelectorAll(selector)` anchors live (so the inspector shows the change immediately before the debounced reload).
- [ ] **Verify:** deploy design.js; in the editor, click a view-all link; in the preview iframe console confirm the posted message now carries `anchorHref`/`anchorSelector`.

### Task 4: Link section in the element inspector
**Files:** Modify `management/js/news_sites_design.js` — message handler (store `st.selAnchorHref`, `st.selAnchorSel` ~205-216); `renderBlockSettings` (~1300) add a Link section; reuse `wys.sections`/`FIXED_PAGES`/`BLOCK_HREFS` and `loadSections()`.
- [ ] On select message, store `st.selAnchorHref` and `st.selAnchorSel`.
- [ ] In `renderBlockSettings`, when `st.selAnchorSel` (element is/contains a link): render a "Link" sub-section with: a destination `<select>` grouped Fixed pages / Sections (from `wys.sections`) / Dynamic fields (`BLOCK_HREFS[block.type]` + generic) / "Custom URL…", plus a text input for custom, and an "Open in new tab" note is out-of-scope (skip). Current value preselected from `st.selAnchorHref`.
- [ ] On change: `block.settings.links = block.settings.links || {}; block.settings.links[st.selAnchorSel] = href;` then `pv({action:'live_href', blockIdx:st.sel, selector:st.selAnchorSel, href:href})` and `applyChangeDebounced()`.
- [ ] Ensure `loadSections()` is called on open (it is, in blocks path) so `wys.sections` is populated in page mode too — add a call in `open()`.
- [ ] **Verify:** on TPE, select a view-all link, set it to a section, see it change live; reload the editor and the setting persisted; view live page (JS off) shows the new href.

### Task 5: UI polish (cleaner, less "ugly")
**Files:** Modify `management/css/news_sites_design.css`; minor markup in `management/module-news.php` if needed.
- [ ] Clean inspector: clear section headers (Content / Link / Style), consistent spacing, lighter borders, obvious "selected element" outline in the preview (the bridge already adds `.dl-selected`; style it as a 2px accent outline).
- [ ] Left rail: clearer block rows, visible drag handle, hover affordances for ↑↓⚙✕.
- [ ] Remove now-dead "Max preview" styling; ensure layout holds at the modal's min width.
- [ ] **Verify:** visual check on TPE; selected-element outline visible; panels readable.

### Task 6: Deploy + full verification
- [ ] Deploy `/design/` changed files (`lib/registry.php`, `assets/design.js`, any blocks) → `tpeuser:/public_html/design/`.
- [ ] Deploy `management/` changed files (`js/news_sites_design.js`, `css/news_sites_design.css`, `module-news.php`) → `tenuser:/public_html/management/`.
- [ ] **Verify end-to-end on TPE:** one mode only; click element → Content/Link/Style inspector; set a link live + persisted + crawlable; blocks drag/reorder/add/remove; no console errors; existing style/text editing still works.

## Self-Review notes
- Spec coverage: unified select→inspect (T3/T4), links for selected element (T2/T3/T4), main-pane block add/reorder/remove (already exists; T5 polish), kill two-system confusion (T1), nicer UI (T5), dynamic read-only (T4 — Content stays read-only for non-editKey elements; Link/Style still offered). ✔
- Risk: Task 2 DOMDocument override is the highest-risk; fail-soft returns original HTML so a bad selector can never blank a block. Links that are already token/settings-driven in templates keep working unchanged.
