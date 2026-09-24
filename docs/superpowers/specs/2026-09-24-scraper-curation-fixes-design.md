# Scraper: curation robustness + editable curation prompt

**Date:** 2026-09-24
**Author:** William Smyth (with Claude)

## Background

Reported symptoms: (1) no curated articles for some publications (TME, Berlin);
(2) disabling then re-enabling a publication "fails to get new articles for days".

### Investigation findings (live system, 2026-09-24)

- The ingest scheduler cron and precompute cron are **both alive** (TME News fetched
  126 items at exactly 12:00, its cron time; all were translated).
- 10 of 12 publications are intentionally "scraping off"; only TPE and TBERE are on.
  TME showing no curated articles was simply because it was switched off.
- Enabling TME News + a precompute pass produced 27–29 curated stories **instantly**,
  so re-enabling recovers once precompute runs. The felt "fails for days" is that
  enabling only flips the switch and then waits for the daily ingest cron + 15-min
  precompute cron; the "Curated pick" filters on `curated_at = today`, so it reads
  empty until a re-rank happens.
- **Latent wedge risk (the real bug to fix):** ranking is gated on translating 100%
  of an unbounded `'new'` pool (TME News had a 2,927-item backlog). A single
  untranslatable item — or a 20-item translation chunk that fails JSON decode, which
  threw and discarded the *whole* pass — would wedge a section permanently: it would
  never reach `remain == 0`, so it would never rank, and the Curated pick stays empty.

## Fix #2 — harden translate-before-rank (bugfix)

- `scraper_ai_translate` (ScraperAI.php): isolate each 20-item chunk in try/catch. A
  chunk that errors or returns unparseable JSON is skipped, not thrown — earlier
  chunks' translations are kept. Failed chunks simply omit their ids.
- `scraper_precompute_section_locked` (scraper_review.php): when a pass produced at
  least one real translation, any item the translator omitted gets a **fallback** —
  its original title/summary stored as the "translation" (cleaned) so it stops
  blocking. A total failure (empty result — API down) is left untouched to retry.
  Net effect: `remain` strictly decreases whenever the API is up, so a section can no
  longer wedge forever on one bad item.

## Fix #3 — editable curation prompt (feature)

Mirror the existing article-writing-prompt pattern (`scraper_effective_ai`).

- **Data:** `ten_scraper_pub_sections.curate_prompt` (TEXT NULL) + section override;
  `ten_scraper_projects.default_curate_prompt` (TEXT NULL) project default. Add-only
  migration; NULL falls back so behaviour is unchanged until edited.
- **Resolver:** `scraper_effective_curate_prompt($section)` = section → project →
  built-in `SCRAPER_DEFAULT_CURATE_BRIEF` constant.
- **Assembly:** in `scraper_curate_rank`, the editable **brief** (with `{region}`
  substituted) becomes the editorial content of the system prompt. The JSON output
  contract, the translate-to-English instruction and the strict headline-formatting
  rules stay **fixed in code** so an edited brief cannot break parsing. The old
  hard-coded `ten`=world branch is folded into `{region}` (which already resolves to
  "the world…" for `ten`).
- **UI (Configure):** section editor gets a "Curation prompt (optional)" textarea
  (blank = inherit); Project settings gets a "Default curation prompt" textarea.
  Wired through `section.create/update` and `prompt.get/update`.

## Deploy

Migration script `scraper/migrate_curate_prompt.php` (token-guarded, idempotent) run
over HTTPS, then deleted. Code deployed via WinSCP plain-FTP to live `/management/`:
`scraper/lib/ScraperAI.php`, `scraper/lib/scraper_review.php`,
`scraper/lib/scraper_crud.php`, `ajax/scraper_config.php`, `js/scraper_config.js`.

## Out of scope (deferred)

- "Instant enable" (kick ingest + precompute on enable) — considered, not selected.
- Bounding/pruning the `'new'` backlog so ranking doesn't translate thousands of old
  items just to rank the newest 80.
