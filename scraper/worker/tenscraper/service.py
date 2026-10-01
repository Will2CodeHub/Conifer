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


def drain_run_requests(repo) -> dict:
    """Process any pending manual "run now" requests (from the management UI),
    scraping each requested section immediately regardless of its schedule.
    Safe to call from both run_requests.py (frequent) and run_scheduler.py."""
    repo.ensure_run_requests_table()
    ran, errors = [], []
    for req_id in repo.pending_run_request_ids():
        pub_section_id = repo.claim_run_request(req_id)
        if pub_section_id is None:
            continue  # already claimed by another worker
        try:
            # A manual "run now" request is an explicit override — scrape even a
            # disabled section (force=True); the scheduler's auto-sweep still skips
            # disabled ones.
            result = ingest_section(repo, pub_section_id, force=True)
            if result is None:
                repo.finish_run_request(req_id, "done", "section not found")
            else:
                repo.finish_run_request(
                    req_id, "done",
                    f"found={result.items_found} new={result.items_new} "
                    f"dupe={result.skipped_dupe} robots={result.skipped_robots}",
                )
            ran.append(req_id)
        except Exception as exc:  # noqa: BLE001 - keep going for other requests
            repo.finish_run_request(req_id, "error", None, str(exc)[:480])
            errors.append((req_id, str(exc)))
    return {"ran": ran, "errors": errors}


def ingest_section(repo, pub_section_id: int, force: bool = False):
    """Run ingestion for one section id. Returns the IngestResult, or None if the
    section is missing (or inactive and not forced). Records a row in
    ten_scraper_runs when it runs.

    force=True is used for MANUAL runs (the "Run now" button / run_ingest CLI):
    a disabled section is only skipped by the scheduler's automatic sweep, but an
    explicit manual request must scrape it anyway. Disabling a section therefore
    stops scheduled scraping without blocking an on-demand run."""
    section = repo.load_section(pub_section_id)
    if not section:
        return None
    if not force and not section.get("is_active"):
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
        # Daily per-section cap: never collect more than daily_count (N) items in a day.
        daily_cap = int(section.get("daily_count") or 0)
        already_today = 0
        if daily_cap:
            try:
                already_today = repo.count_items_today(pub_section_id)
            except Exception:
                already_today = 0
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
            daily_cap=daily_cap,
            already_today=already_today,
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
