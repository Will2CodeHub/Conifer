#!/usr/bin/env python3
"""CLI: ingest one (publication, section) by id.

    python run_ingest.py --pub-section-id 7

DB credentials come from environment variables (exported by the cron wrapper):
    SCRAPER_DB_HOST/USER/PASS/NAME              (TEN_Management)
    SCRAPER_ADMIN_DB_HOST/USER/PASS/NAME        (admin_ten)
"""

from __future__ import annotations

import argparse
import sys

from tenscraper.adapters import PyMySQLRepo, mysql_connect_from_env
from tenscraper.service import ingest_section


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest one publication/section.")
    parser.add_argument("--pub-section-id", type=int, required=True)
    args = parser.parse_args()

    ten = mysql_connect_from_env("SCRAPER_DB")
    admin = mysql_connect_from_env("SCRAPER_ADMIN_DB")
    repo = PyMySQLRepo(ten, admin)
    try:
        result = ingest_section(repo, args.pub_section_id)
        if result is None:
            print(f"Section {args.pub_section_id} not found or inactive.")
            return 0
        print(f"found={result.items_found} new={result.items_new} "
              f"dupe={result.skipped_dupe} robots={result.skipped_robots}")
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    finally:
        ten.close()
        admin.close()


if __name__ == "__main__":
    raise SystemExit(main())
