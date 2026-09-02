"""Ingest one section end to end (load config, optional VPN, fetch, record run).

Shared by run_ingest.py (single section) and run_scheduler.py (all due sections).
Depends on adapter I/O, so it is exercised server-side, not in unit tests.
"""

from __future__ import annotations

from .adapters import RequestsFetcher, country_ip_checker, subprocess_runner
from .html_listing import extract_article_links
from .ingest import run_ingest
from .politeness import build_user_agent
from .vpn import VpnController, VpnError


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
