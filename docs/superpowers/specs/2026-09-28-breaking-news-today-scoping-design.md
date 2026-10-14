# Breaking-news per-publication toggle + today-scoping fixes

Date: 2026-09-28
Branch: feature/journalist-role

## Problems

1. **Cumulative counts / credit waste.** The Curate pub pill (`new_count`) and the
   "Translating N…" banner (`scraper_untranslated_count`) count *every* `status='new'`
   item ever ingested (TME pill showed 3097; banner showed 573). Worse, the actual
   translate/rank pipeline (`scraper_precompute_section_locked`,
   `scraper_sections_needing_precompute`, `scraper_curate_rank`) also operates on all
   `new` items regardless of date, so "Run now" pays to translate hundreds of stale
   items the Curate screen never shows (it only ever displays `CURDATE()`).
2. **TME breaking news dead for ~a week.** The breaking auto-publisher pulls candidates
   from `status='new'` ordered by `published_at DESC` with no date scope, so it churns
   old backlog instead of today's news and publishes nothing current.
3. **No per-publication control.** Which publications run a breaking desk is hardcoded in
   two places: `$BREAKING_PUBS` in the cron, and `in_array(BASE_SITE_ABBREVIATION, ...)`
   in the shared front-page template.

## Decisions (confirmed with owner)

- "Today" = calendar `CURDATE()`, matching what the Curate screen already displays.
- The breaking toggle controls **both** auto-publishing and front-page display.
- Seed: breaking ON for `tme` (Munich) and `tpe` (Paris); OFF for all others (incl. `tbere` Berlin).

## Changes

### A. Scope to today (management: `scraper/lib/scraper_review.php`)
Add `AND DATE(fetched_at)=CURDATE()` to the `status='new'` filters in:
`scraper_curate_publications`, `scraper_untranslated_count`,
`scraper_precompute_section_locked` (translate batch + remaining gate),
`scraper_sections_needing_precompute` (both EXISTS branches), `scraper_curate_rank`.
Old backlog is left in place but ignored (ignoring it is free).

### B. Per-publication parameter: `breaking_news_enabled`
- DB: `ALTER TABLE publications ADD COLUMN breaking_news_enabled TINYINT(1) NOT NULL DEFAULT 0`
  (admin_ten). One-time migration script `run_breaking_news_migration.php` seeds tme+tpe=1,
  then is deleted.
- `scraper/lib/news_sites_db.php`: include flag in list/get/save; add
  `ns_breaking_enabled_publications()` for the cron.
- `module-news.php` + `js/news_sites.js`: "Publication settings" block in the edit modal
  with a Breaking-news-desk toggle, plus a card badge. Built as an extensible settings
  block for future parameters.
- `ajax/news_sites.php`: pass the flag through save (admin-only, already gated).

### C. Wire cron + front page to the flag; fix candidate scoping
- `scraper/cron/breaking_publish.php`: replace hardcoded `$BREAKING_PUBS` with
  `ns_breaking_enabled_publications()`.
- `scraper/lib/scraper_breaking.php`: candidate query scoped to `DATE(fetched_at)=CURDATE()`.
- `generate_index_page.php` (shared site template): replace the hardcoded `in_array`
  with a live lookup of `publications.breaking_news_enabled` for `BASE_SITE_ABBREVIATION`
  (fail-closed on error).

## Verification (no local DB / no PHP test harness)
- Run migration (admin), confirm flags.
- Hit the breaking cron URL; read its JSON: tme+tpe publish up to 2/day or show clear
  skip reasons; others skipped.
- Curate: pills show today's counts; "Run now" translates only today's items.
- Front pages: tme/tpe show the breaking section; others don't.
