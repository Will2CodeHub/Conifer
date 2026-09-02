# TEN Scraper — Phase 2: News Collation Config UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
>
> **Prerequisite:** Phase 1 verified on staging (module loads, tables exist). This phase builds the CRUD that fills those tables. **Depends on Phase 1's staging deploy being confirmed first** — do not start blind.

**Goal:** Give `scraper.manage` users a UI inside `module-scraper.php` to configure the News Collation project: per-publication sections (with daily count N, cron schedule, journalist, AI model/prompt, auto-publish, VPN profile), their sources, and each source's feeds — plus manage VPN profiles and edit the project/section prompts.

**Architecture:** Follows the existing module pattern exactly — a management view rendered inside `module-scraper.php`, driven by AJAX endpoints under `ajax/scraper_*.php` that mirror `ajax/roles.php` (JSON in/out, `requireLogin()` + permission guard, `getDBConnection()`, prepared statements). Data access lives in `scraper/lib/scraper_db.php` (extended from Phase 1). Client JS in `js/scraper_config.js`.

**Tech Stack:** PHP (mysqli, prepared statements), vanilla JS + fetch, existing backend CSS + SweetAlert (`Swal`) already used across modules.

## Global Constraints

- All write endpoints guard with `hasPermission('scraper.manage') || isAdmin()`; read endpoints allow `scraper.use` too. — spec §13.
- Publications come from `admin_ten.publications` (`getDBConnection_TENAdmin()`, `SELECT publication, url FROM publications WHERE pub_live='1'`). Journalists from `ten_users` (`id`, `full_name`, `username`). — spec §3.
- Section prompt starts as a copy of the project's `default_prompt`, then edited independently. — spec §9.
- `cron_schedule` stored as a standard 5-field cron string; UI offers presets (hourly / every 6h / daily HH:MM) plus a raw field. — spec §7.
- Sources/feeds map into a TEN section by their placement under that section (no separate mapping table). — spec §4.

## File Structure

- `scraper/lib/scraper_db.php` (extend) — config read/write helpers (below).
- `scraper/lib/scraper_refs.php` (create) — `scraper_get_publications()`, `scraper_get_journalists()`, `scraper_get_ten_sections()` (reference lists for dropdowns).
- `module-scraper.php` (extend) — add a "Manage" view for `news_collation` when `$canManage`, with three nested levels: Sections → Sources → Feeds, plus VPN Profiles and Prompt editors.
- `ajax/scraper_sections.php`, `ajax/scraper_sources.php`, `ajax/scraper_feeds.php`, `ajax/scraper_vpn.php`, `ajax/scraper_prompt.php`, `ajax/scraper_refs.php` — CRUD + reference endpoints.
- `js/scraper_config.js` — view logic (list/create/edit/delete via fetch).

## Data-access helpers to add to `scraper/lib/scraper_db.php`

Concrete signatures (implement with prepared statements, mirroring existing code):

```php
// Sections (per publication+section config nodes) for a project.
function scraper_list_sections(int $projectId): array;          // rows incl. publication_key, ten_section, daily_count, cron_schedule, journalist_id, ai_provider, ai_model, auto_publish, vpn_profile_id, is_active
function scraper_get_section(int $id): ?array;
function scraper_create_section(array $data): int;              // requires project_id, publication_key, ten_section; copies project default_prompt into prompt
function scraper_update_section(int $id, array $data): bool;    // daily_count, cron_schedule, journalist_id, ai_provider, ai_model, auto_publish, vpn_profile_id, is_active, prompt
function scraper_delete_section(int $id): bool;                 // cascades sources+feeds (delete children first)

// Sources under a section.
function scraper_list_sources(int $pubSectionId): array;
function scraper_create_source(array $data): int;              // pub_section_id, name, homepage_url
function scraper_update_source(int $id, array $data): bool;
function scraper_delete_source(int $id): bool;                 // deletes its feeds first

// Feeds under a source.
function scraper_list_feeds(int $sourceId): array;
function scraper_create_feed(array $data): int;               // source_id, feed_url, feed_type, source_category_label, respect_robots, robots_override_reason, rate_limit_seconds
function scraper_update_feed(int $id, array $data): bool;
function scraper_delete_feed(int $id): bool;

// VPN profiles.
function scraper_list_vpn_profiles(): array;
function scraper_save_vpn_profile(array $data): int;           // upsert by id
function scraper_delete_vpn_profile(int $id): bool;

// Prompts.
function scraper_get_project(int $id): ?array;
function scraper_update_project_prompt(int $id, string $prompt, string $provider, string $model): bool;
```

## Endpoint contract (all POST JSON, mirror ajax/roles.php)

Each endpoint: `require config.php` + `scraper/lib/scraper_db.php`; `requireLogin()`; guard; read `json_decode(file_get_contents('php://input'))`; switch on `action` (`list|get|create|update|delete`); return `{status:'success'|'error', ...}`. `scraper_refs.php` returns publications, journalists, sections, models for dropdowns and requires only `scraper.use`.

## Tasks

1. **Reference helpers** — `scraper/lib/scraper_refs.php` + `ajax/scraper_refs.php`. Verify on staging: endpoint returns publications from admin DB + journalists from `ten_users` + a static AI model list (`claude-sonnet-5`, `claude-opus-5`, `claude-haiku-4-5-20251001`, `gpt-...`). *(Model list is editable text in one place.)*
2. **Sections CRUD** — helpers + `ajax/scraper_sections.php`. Create copies project `default_prompt` into the section. Verify: create a TME/News section with N=20, daily cron; it appears; edit N; delete.
3. **Sources CRUD** — helpers + `ajax/scraper_sources.php`. Verify under a section.
4. **Feeds CRUD** — helpers + `ajax/scraper_feeds.php`, incl. robots override reason field shown only when respect_robots unchecked. Verify add RSS feed with category label.
5. **VPN profiles CRUD** — helpers + `ajax/scraper_vpn.php`. Verify create a `protonvpn` DE profile; it appears in the section VPN dropdown.
6. **Prompt editors** — `ajax/scraper_prompt.php`: edit project default prompt; edit a section prompt (pre-filled from project). Verify save + reload.
7. **UI wiring** — extend `module-scraper.php` manage view + `js/scraper_config.js`: nested Sections → Sources → Feeds accordion, section edit modal (N, schedule preset, journalist dropdown, model dropdown, auto-publish toggle, VPN dropdown, prompt textarea), VPN profiles panel. Verify full click-through on staging.

## Testing approach

No local PHP — each task is verified by WinSCP-deploying the changed files to `/management-staging/` and exercising the UI against the live DB, then confirming rows via phpMyAdmin. Keep each task's deploy small so a failure is easy to localise.

## Out of scope (later phases)

Fetching/generation (Phase 3 worker already built), the review/select/promote screen and AI-on-promote (Phase 5), image suggestions (Phase 6), the master cron (Phase 4). This phase only *configures*.
