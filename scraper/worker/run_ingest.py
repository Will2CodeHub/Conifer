#!/usr/bin/env python3
"""CLI entrypoint: ingest one (publication, section).

Invoked by the master PHP scheduler (Phase 4) once per due section:

    python run_ingest.py --pub-section-id 7

DB credentials come from environment variables (exported by the cron wrapper so
secrets are not duplicated in Python):
    SCRAPER_DB_HOST SCRAPER_DB_USER SCRAPER_DB_PASS SCRAPER_DB_NAME        (TEN_Management)
    SCRAPER_ADMIN_DB_HOST SCRAPER_ADMIN_DB_USER SCRAPER_ADMIN_DB_PASS SCRAPER_ADMIN_DB_NAME  (admin_ten)

Server-verified in Phase 4 — not runnable on the dev machine (no MySQL/network).
"""

from __future__ import annotations

import argparse
import os
import sys
import traceback

from tenscraper.adapters import (
    PyMySQLRepo,
    RequestsFetcher,
    country_ip_checker,
    subprocess_runner,
)
from tenscraper.html_listing import extract_article_links
from tenscraper.ingest import run_ingest
from tenscraper.politeness import build_user_agent
from tenscraper.vpn import VpnController, VpnError


def _connect(prefix: str):
    import pymysql

    return pymysql.connect(
        host=os.environ[f"{prefix}_HOST"],
        user=os.environ[f"{prefix}_USER"],
        password=os.environ[f"{prefix}_PASS"],
        database=os.environ[f"{prefix}_NAME"],
        charset="utf8mb4",
        autocommit=False,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest one publication/section.")
    parser.add_argument("--pub-section-id", type=int, required=True)
    args = parser.parse_args()

    ten = _connect("SCRAPER_DB")
    admin = _connect("SCRAPER_ADMIN_DB")
    repo = PyMySQLRepo(ten, admin)

    section = repo.load_section(args.pub_section_id)
    if not section or not section["is_active"]:
        print(f"Section {args.pub_section_id} not found or inactive; nothing to do.")
        return 0

    feeds = repo.load_feeds(args.pub_section_id)
    user_agent = build_user_agent(section["publication_key"])
    run_id = repo.start_run(args.pub_section_id)

    vpn = None
    try:
        # Optional geo-appropriate egress.
        if section["vpn_profile_id"]:
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
            pub_section_id=args.pub_section_id,
            feeds=feeds,
            repo=repo,
            fetcher=fetcher.fetch,
            user_agent=user_agent,
            robots_fetcher=fetcher.fetch_robots,
            html_lister=extract_article_links,
        )
        log = (
            f"found={result.items_found} new={result.items_new} "
            f"dupe={result.skipped_dupe} robots={result.skipped_robots} "
            f"errors={result.errors}"
        )
        repo.finish_run(run_id, result.items_found, result.items_new, "ok", log)
        print(log)
        return 0

    except Exception as exc:  # noqa: BLE001 - top-level guard: record and exit non-zero
        log = f"ERROR: {exc}\n{traceback.format_exc()}"
        repo.finish_run(run_id, 0, 0, "error", log)
        print(log, file=sys.stderr)
        return 1

    finally:
        if vpn is not None:
            try:
                vpn.disconnect()
            except Exception:  # never let teardown mask the real result
                pass
        ten.close()
        admin.close()


if __name__ == "__main__":
    raise SystemExit(main())
