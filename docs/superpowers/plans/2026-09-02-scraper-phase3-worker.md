# TEN Scraper — Phase 3: Python Ingestion Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A locally-tested Python worker that turns configured feeds/sources into deduplicated `ten_scraper_items`, respecting politeness rules, with a VPN egress abstraction — all pure logic unit-tested offline (mocked HTTP, local fixtures).

**Architecture:** A `tenscraper` package of focused, dependency-injected modules. Network, filesystem, DB, subprocess and clock are all injected so the logic is tested without touching real news sites, MySQL, or VPN tunnels. Real adapters (PyMySQL repo, requests fetcher, subprocess VPN runner) are thin and exercised on the server in Phase 4.

**Tech Stack:** Python 3.14, pytest, feedparser (RSS/Atom), requests (fetch adapter), optional trafilatura (HTML extraction, with a stdlib fallback so tests never require it).

## Global Constraints

- Worker runs server-side against `localhost` MySQL; from the dev machine only unit tests (injected fakes) run — no live DB/network in tests. — spec §3 / build-env decision.
- Politeness three controls (spec §5): honest UA always on; robots.txt respected unless `respect_robots=0` with a logged `robots_override_reason`; per-feed rate limit.
- VPN egress is geo-access, verified before fetch, never per-request rotation (spec §6).
- Facts-only: extraction yields our condensed facts; the fetched body is not persisted by the worker (spec §2/§7).

## File Structure

```
scraper/worker/
  requirements.txt
  pytest.ini
  tenscraper/
    __init__.py
    models.py          # RawItem, ArticleFacts dataclasses
    dedup.py           # normalize_url, url_hash, cluster_key
    politeness.py      # build_user_agent, RobotsPolicy, RateLimiter
    feeds.py           # parse_feed(content) -> [RawItem]
    html_fallback.py   # extract_facts(html,url, extractor?) with stdlib basic extractor
    vpn.py             # VpnProfile, VpnController (injected runner + ip_checker)
    repo.py            # ScraperRepo protocol + InMemoryRepo (real PyMySQL repo stub for Phase 4)
    ingest.py          # run_ingest(pub_section, repo, fetcher, policy...) orchestration
  tests/
    conftest.py
    fixtures/{sample_rss.xml, sample_atom.xml, sample_article.html, sample_robots.txt}
    test_dedup.py test_politeness.py test_feeds.py
    test_html_fallback.py test_vpn.py test_ingest.py
```

## Tasks (each: write test → run red → implement → run green → commit)

1. **models.py** — `RawItem(source_url,title,summary,published_at,source_category_label)`, `ArticleFacts(source_url,title,summary,byline,published_at,key_points)`.
2. **dedup.py** — `normalize_url` (drop fragment, `utm_*`/`fbclid` params, default ports, trailing slash, lowercase host), `url_hash` = sha256 of normalized url, `cluster_key` = sha1 of sorted significant title tokens (lowercase, punctuation/stopwords removed). Tests: same story punctuation/case variants → same cluster_key; different stories → different.
3. **politeness.py** — `build_user_agent(publication, contact_url)`; `RobotsPolicy(respect_robots, override_reason).can_fetch(robots_txt, url, ua)`; `RateLimiter(delay, clock, sleep).wait(domain)` (injected clock/sleep, no real sleeping). Tests: override bypasses disallow; disallow respected; rate limiter sleeps only when interval < delay.
4. **feeds.py** — `parse_feed(content, source_category_label=None)` via feedparser → `[RawItem]` with parsed dates. Tests against `sample_rss.xml` + `sample_atom.xml` fixtures.
5. **html_fallback.py** — `extract_facts(html, url, extractor=None)`; default tries trafilatura, falls back to `basic_extractor` (stdlib `html.parser`: `<title>`, meta description, `<p>` text → key_points). Tests target `basic_extractor` so trafilatura is not required.
6. **vpn.py** — `VpnProfile`; `VpnController(runner, ip_checker)` with `connect(profile)` (builds provider-specific command), `verify_country(expected)`, `disconnect()`. Tests assert command construction per provider and country verification via injected `ip_checker` (no real tunnel).
7. **repo.py + ingest.py** — `ScraperRepo` protocol (`existing_hashes`, `article_scrape_hashes`, `insert_item`), `InMemoryRepo`, and `run_ingest(section_cfg, feeds, repo, fetcher, robots_policy, rate_limiter, ua)` that fetches (injected `fetcher`), parses, dedups (skip hashes already in repo or in articles), assigns cluster_key, inserts new items, returns a run summary. Tests with fakes: dedup skips known hashes; politeness consulted; counts correct.

## Testing approach

All tests run locally: `scraper/worker/venv/Scripts/python.exe -m pytest scraper/worker -q`. No network, DB, or VPN. The real PyMySQL repo, requests fetcher, and subprocess VPN runner are written as thin adapters and verified on the server in Phase 4.
