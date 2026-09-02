# TEN Scraper System — Design Spec

**Date:** 2026-09-02
**Author:** William Smyth (with Claude)
**Status:** Approved design → ready for implementation planning
**Location:** `management/scraper/` + `management/module-scraper.php`

---

## 1. Purpose

Give TEN Management a **scraping + AI-assisted original-writing system** so each publication (The Munich Eye, etc.) can:

1. Collate newsworthy leads from external sources (RSS/Atom feeds first, HTML fallback where no feed exists), per publication and per section.
2. Let an editor review the collated pool daily and **select up to N items** per (publication, section).
3. On **promote**, have Claude or ChatGPT write an **original, SEO-optimised** article *from the facts* (not a reworded copy), assign a journalist byline, and insert it into the existing TEN Article Tool (`admin_ten.articles`) as a draft — or, if the section's auto-publish switch is on, as published.
4. Suggest royalty-free images for the article from multiple free providers, with license/attribution captured.

The framework hosts multiple **project types**. **News Collation** is the first, fully specified here. A second project, **Cafe/Restaurant email collection**, is scaffolded as a stub only (internals designed later).

---

## 2. Legal & safety posture (non-negotiable, baked into the design)

This system is deliberately the **defensible** version of the request. The following are structural, not optional:

- **Original composition, not rewording.** The AI writes original coverage from extracted *facts* + a source link. Facts are not copyrightable; a reworded copy of a specific article is a derivative work and is specifically exposed under **German/EU press-publishers' rights** (*Leistungsschutzrecht* / EU Copyright Directive Art. 15) and database rights. The pipeline never produces a paraphrase-and-republish.
- **Facts-only storage.** We store our own condensed facts + the source URL. The fetched source body is used **transiently** for fact extraction and then discarded — never warehoused or republished.
- **Attribution.** `news_scrape_url` is retained on every promoted article; the prompt instructs source citation.
- **No source images.** Source outlets' photos are never reused. Images come from royalty-free providers with license/attribution stored.
- **VPN egress is for geo-access, not evasion.** Country-appropriate egress so an in-country publication reads in-country sources. No automated per-request IP rotation.
- **Honest identification always on.** The fetcher's User-Agent always names the publication + a contact URL. This is what distinguishes legitimate access from evasion and is never switched off.
- **robots.txt respected by default.** Overriding robots.txt is an explicit, per-source, logged-with-reason action intended only for your own sites or sources you have permission to access. It is not a casual global toggle.
- **Human in the loop.** No fully unattended publishing. Auto-publish (per section) still requires a human to *select* each story; it only changes the promoted state from `draft` to `published`.

---

## 3. Architecture & folder layout

```
management/scraper/
  schema.sql                 # all new ten_scraper_* tables
  worker/                    # Python ingestion engine (choice A.2)
    run_ingest.py            # entrypoint: ingest one (publication, section)
    feeds.py                 # RSS/Atom via feedparser
    html_fallback.py         # trafilatura extraction for feedless sources
    vpn.py                   # egress provider abstraction (ProtonVPN + generic WireGuard/OpenVPN)
    politeness.py            # robots.txt + rate limiting + honest UA
    dedup.py                 # source_url_hash + cross-check vs admin_ten.articles
    requirements.txt
  lib/
    scraper_db.php           # DB helpers (TEN_Management + admin_ten)
    ScraperAI.php            # Claude + OpenAI provider abstraction
    ImageSearch.php          # Pixabay/Openverse/Wikimedia/Pexels/Unsplash search
    promote.php              # select+promote -> AI write -> admin_ten.articles
  ajax/                      # UI endpoints (list items, select, promote, save config, image search)
  cron_scraper.php           # master scheduler (PHP), invokes worker for due sections
management/module-scraper.php  # TEN Management page (matches module-*.php pattern)
```

- **Engine:** Python (feedparser + trafilatura). Everything the browser touches is **PHP**, consistent with existing TEN Management.
- **Databases:** New `ten_scraper_*` tables live in `TEN_Management`. Promote writes into `admin_ten.articles`. Publications read from `admin_ten.publications` (`SELECT publication, url FROM publications WHERE pub_live='1'`). Journalists read from `ten_users` (`journalist_id` = `ten_users.id`).

---

## 4. Data model (`ten_scraper_*` in `TEN_Management`)

Exact column types finalised during implementation; shape below.

- **`ten_scraper_projects`** — `id`, `name`, `type` (`news_collation` | `email_collection`), `default_ai_provider`, `default_ai_model`, `default_prompt` (TEXT), `is_active`, timestamps.
- **`ten_scraper_pub_sections`** — the core config node.
  `id`, `project_id`, `publication_key`, `ten_section`,
  `daily_count` (N), `cron_schedule`,
  `vpn_profile_id` (nullable), `journalist_id`,
  `ai_provider`, `ai_model`, `prompt` (TEXT, copied from project then editable),
  `auto_publish` (bool, default 0),
  `is_active`, timestamps.
- **`ten_scraper_sources`** — `id`, `pub_section_id`, `name`, `homepage_url`, `is_active`.
- **`ten_scraper_feeds`** — `id`, `source_id`, `feed_url`, `feed_type` (`rss` | `html`), `source_category_label`, `html_selectors` (JSON, nullable), `respect_robots` (bool default 1), `robots_override_reason` (nullable), `rate_limit_seconds` (nullable → default), `is_active`.
- **`ten_scraper_items`** — collated results.
  `id`, `feed_id`, `pub_section_id`, `source_url`, `source_url_hash` (dedup),
  `title`, `summary`, `facts` (our condensed notes), `published_at`,
  `cluster_id` (same-story grouping), `image_suggestions` (JSON, nullable),
  `status` (`new` | `selected` | `promoting` | `promoted` | `discarded`), `fetched_at`.
- **`ten_scraper_drafts`** — generated article provenance.
  `id`, `item_id`, `generated_title`, `generated_body`, `meta_title`, `meta_description`, `meta_keywords`,
  `ai_provider`, `ai_model`, `prompt_used`, `journalist_id`,
  `article_id` (nullable, set after insert into admin_ten.articles), `status`, `created_at`.
- **`ten_scraper_vpn_profiles`** — `id`, `provider` (`protonvpn` | `wireguard` | `openvpn`), `name`, `country`, `config_ref` (path/credential reference, server-side), `is_active`.
- **`ten_scraper_runs`** — cron run log: `id`, `pub_section_id`, `started`, `finished`, `items_found`, `items_new`, `status`, `log`.

**Dedup:** `source_url_hash` unique per project, **cross-checked against `admin_ten.articles.news_scrape_url_hash`** so an already-imported story never returns. Same story across sources grouped by `cluster_id` (title similarity) and shown once in review.

---

## 5. Ingestion engine (Python worker)

Per due (publication, section):

1. For each feed: **RSS** → `feedparser`; **HTML** → fetch listing page, extract article links, fetch each *new* article, run `trafilatura` to extract facts (title, date, byline, key points).
2. Build `facts` = our own condensed notes + source URL. Discard fetched body.
3. Dedup on `source_url_hash` (+ cross-check admin articles). Group clusters.
4. Insert `ten_scraper_items` with `status='new'`.

**Politeness** (`politeness.py`), three independent controls:
- **Honest User-Agent** — always on; names publication + contact URL.
- **Rate limiting** — per-feed configurable (`rate_limit_seconds`), default polite; may be lowered/disabled for legitimate cases (own sites, agreements).
- **robots.txt** — respected by default (`respect_robots=1`); override is per-feed, requires `robots_override_reason`, and is logged in `ten_scraper_runs`.
- If a source actively blocks (403/429), the worker backs off and flags the source for the operator to disable — never evades.

---

## 6. VPN egress subsystem

`vpn.py` brings up a chosen egress **before** fetching a section's sources and tears it down after.

- Provider-switchable + configured from the scraper page via `ten_scraper_vpn_profiles` (ProtonVPN + generic WireGuard/OpenVPN backends).
- Each (publication, section) may point at a VPN profile → in-country egress for in-country sources.
- Worker **verifies egress country/IP** before fetching; aborts if it can't confirm.
- IP change is a manual/occasional operator action (UI button) for geo-access and operational recovery — **not** automated per-request rotation.

**Implementation caution (flagged for the plan):** the tunnel must run in an **isolated network namespace** so only scraper traffic uses the VPN and the server's own site traffic is never disrupted. This is the highest-care part of the build; needs root/systemd setup and careful testing.

---

## 7. Scheduling

A single master cron `cron_scraper.php` (added to the system crontab **once**) runs every few minutes, finds (publication, section) rows **due** per their editable `cron_schedule`, and invokes the Python worker for each. Schedules are edited in the UI — you never touch the system crontab to add or change a source.

---

## 8. Review, selection & promote UI (`module-scraper.php`)

- Project tabs. For **News Collation**: choose Publication → Section → see collated `new` items (newest first, deduped/clustered) with title, source, summary, source link, time.
- **Select up to N** (counter "12 / 20", N = section `daily_count`).
- **Promote** is the action that triggers the AI write:
  1. For each selected item, `ScraperAI` writes an original SEO-optimised article using the section's prompt + model.
  2. Insert into `admin_ten.articles` (see §10), state = `draft`, or `published` if the section's `auto_publish` is on.
  3. Record `ten_scraper_drafts` provenance + `article_id`; set item `status='promoted'`.
- Final editing happens in the existing Article Tool (`module-articles.php`).
- Config sub-pages (gated by `scraper_admin`): manage projects, publications/sections, sources/feeds, prompts, models, journalists, VPN profiles, schedules, N, auto-publish, politeness overrides.

---

## 9. AI writing step

`ScraperAI.php` abstracts **Claude** (existing `ANTHROPIC_API_KEY`) + **OpenAI** (new `OPENAI_API_KEY`).

- Model + prompt come from the section, falling back to the project default.
- Prompt is an editable template with variables: `{source_title}`, `{source_summary}`, `{source_facts}`, `{source_url}`, `{section}`, `{publication}`, `{target_language}`.
- Returns structured output: `title`, `body` (HTML), `meta_title`, `meta_description`, `meta_keywords`.
- **SEO lives in the editable prompt text** (target keyword, meta title/description, sensible headings) — you control it.
- Project has a default prompt; each section starts as a **copy** of the project prompt and is then independently editable.

---

## 10. Promote → `admin_ten.articles` mapping

| articles column | source |
|---|---|
| `title` | generated title |
| `article_text` | generated body (HTML) |
| `meta_title` / `meta_description` / `meta_keywords` | generated meta |
| `section` | section key |
| `publications` / `canonical` | publication key |
| `journalist_id` | section journalist |
| `state` | `draft`, or `published` if section `auto_publish=1` |
| `news_scrape_url` | source URL |
| `news_scrape_url_hash` | source URL hash |
| `imageless` / `image_url` | left to image flow (§11) |

Article then appears in `module-articles.php` for final edit/publish.

---

## 11. Royalty-free image suggestions

Extends the **existing** Pixabay panel in the article editor (search form, results grid, attribution field, `download_pixabay_image.php`, `article_image_attribution` table).

- **Providers (v1):** Pixabay (existing) + **Openverse** + **Wikimedia Commons** (no keys) + **Pexels** + **Unsplash** (free keys in `config.php`; provider appears once its key is present).
- Generalise the Pixabay UI into one **free-image search** with a source selector; unify results (url, thumb, attribution, license).
- Generalise the download endpoint from Pixabay-only to a **per-provider host allowlist**, still saving into `article_images/` and **recording license + attribution** in `article_image_attribution`.
- **Scraper suggestions:** when a draft is generated, derive image keywords from topic/`meta_keywords`, pre-search the free providers, and store candidates in `ten_scraper_items.image_suggestions`. In the Article Tool they appear as **"Suggested for this article"** — one click to insert, attribution carried over.
- **Licensing honesty:** "royalty-free" ≠ "no credit." System captures + stores the correct license/attribution per image by default and never strips it.

---

## 12. Cafe/Restaurant email collection (stub only)

Created now as a project of `type='email_collection'` with a placeholder tab ("configuration coming soon") and a `ten_scraper_projects` row. No functional internals until designed together later.

---

## 13. Roles / permissions / sidebar

- New `ten_modules` entry + `includes/sidebar.php` link.
- Two permissions: **`scraper_use`** (daily selection + promote) and **`scraper_admin`** (edit sources, prompts, models, VPN, schedules, N, auto-publish, politeness overrides).
- `module-scraper.php` gated via `hasPermission()`, consistent with the roles/permissions architecture.

---

## 14. Config & keys

- Reuse `ANTHROPIC_API_KEY` (already in `config.php`).
- Add `OPENAI_API_KEY`, `PEXELS_API_KEY`, `UNSPLASH_API_KEY` to `config.php` (same pattern).
- **Security note:** the OpenAI key was shared in chat during design and should be **rotated** before going live; VPN credentials stored server-side only, referenced by `config_ref`. (Existing pattern keeps secrets in `config.php`; a future improvement is moving all secrets to environment variables / a non-web-readable file.)

---

## 15. Non-goals (v1)

- No reword-and-republish.
- No fully unattended publishing (human selects every story).
- No automated per-request IP rotation.
- No Cafe/Restaurant internals.
- No warehousing of source article bodies.

---

## 16. Key risks / watch-items

1. **VPN network namespace** (§6) — highest-care implementation item; must not disturb the server's own traffic.
2. **robots.txt overrides** (§5) — legitimate for own/permitted sources only; logged with reason.
3. **Auto-publish sections** (§8) — even with human selection, published AI-from-facts articles should be spot-checked; consider a review period per new section.
4. **Image licensing** (§11) — attribution must never be stripped; verify each provider's terms.
5. **Environment:** the `management/` folder is not currently a git repo — version control this work before/at build time (see §17).

---

## 17. Suggested build phases (detail in the implementation plan)

1. Schema + config keys + module/sidebar/permissions scaffolding.
2. Config UI: projects → publications/sections → sources/feeds → prompts/models/journalists/N/schedule/auto-publish.
3. Python worker: feeds → items, dedup, politeness (Phase 3a), HTML fallback (3b), VPN egress (3c, network namespace).
4. Master cron scheduler.
5. Review + select + promote (AI write on promote) → articles insert.
6. Image search generalisation + scraper suggestions.
7. Cafe/Restaurant stub.
8. Hardening: logging, error handling, key rotation, robots-override audit trail.
