"""Ingest one section end to end (load config, optional VPN, fetch, record run).

Shared by run_ingest.py (single section) and run_scheduler.py (all due sections).
Depends on adapter I/O, so it is exercised server-side, not in unit tests.
"""

from __future__ import annotations

from .adapters import RequestsFetcher, country_ip_checker, subprocess_runner
from .html_fallback import extract_facts
from .html_listing import extract_article_links
from .ingest import run_ingest
from .politeness import RateLimiter, build_user_agent
from .vpn import VpnController, VpnError


def _make_facts_builder(fetcher, user_agent, rate_seconds: float = 1.5, max_chars: int = 5000):
    """Return a facts_builder(item) that fetches the article page and returns a
    condensed facts blob (summary + key paragraphs) for the AI writer. Politeness:
    a small per-domain delay between article-page fetches."""
    limiter = RateLimiter(rate_seconds)

    def _build(item) -> str:
        limiter.wait(item.source_url)
        html = fetcher.fetch(item.source_url, user_agent)
        facts = extract_facts(html, item.source_url)
        parts = []
        if facts.summary:
            parts.append(facts.summary)
        parts.extend(facts.key_points)
        blob = "\n\n".join(p for p in parts if p).strip()
        return blob[:max_chars]

    return _build


def ingest_section(repo, pub_section_id: int):
    """Run ingestion for one section id. Returns the IngestResult, or None if the
    section is missing/inactive. Records a row in ten_scraper_runs either way."""
    section = repo.load_section(pub_section_id)
    if not section or not section.get("is_active"):
        return None

    feeds = repo.load_feeds(pub_section_id)
    user_agent = build_user_agent(section["publication_key"])
    run_id = repo.start_run(pub_section_id)

    vpn = None
    try:
        if section.get("vpn_profile_id"):
            profile = repo.load_vpn_profile(section["vpn_profile_id"])
            if profile:
                vpn = VpnController(subprocess_runner, country_ip_checker)
                vpn.connect(profile)
                if profile.country and not vpn.verify_country():
                    raise VpnError(
                        f"Egress country mismatch (wanted {profile.country}); aborting fetch."
                    )

        fetcher = RequestsFetcher()
        # NOTE: facts are NOT extracted here — fetching every article page at
        # ingest is far too slow across many sections/feeds. Facts are pulled at
        # PROMOTE time instead, only for the items actually being published.
        result = run_ingest(
            pub_section_id=pub_section_id,
            feeds=feeds,
            repo=repo,
            fetcher=fetcher.fetch,
            user_agent=user_agent,
            robots_fetcher=fetcher.fetch_robots,
            html_lister=extract_article_links,
        )
        log = (
            f"found={result.items_found} new={result.items_new} "
            f"dupe={result.skipped_dupe} robots={result.skipped_robots} errors={result.errors}"
        )
        repo.finish_run(run_id, result.items_found, result.items_new, "ok", log)
        return result

    except Exception as exc:  # record failure then re-raise for the caller to log
        import traceback
        repo.finish_run(run_id, 0, 0, "error", f"ERROR: {exc}\n{traceback.format_exc()}")
        raise
    finally:
        if vpn is not None:
            try:
                vpn.disconnect()
            except Exception:
                pass
