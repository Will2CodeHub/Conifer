#!/usr/bin/env python3
"""Cron entrypoint: ingest every section that is due now.

Run from a single cron entry (e.g. every 10 minutes). Reads all active sections,
checks each against its cron_schedule + last successful run, and ingests the due
ones. Same env-var DB credentials as run_ingest.py.
"""

from __future__ import annotations

from datetime import datetime

from tenscraper.adapters import PyMySQLRepo, mysql_connect_from_env
from tenscraper.schedule import is_due
from tenscraper.service import ingest_section


def main() -> int:
    ten = mysql_connect_from_env("SCRAPER_DB")
    admin = mysql_connect_from_env("SCRAPER_ADMIN_DB")
    repo = PyMySQLRepo(ten, admin)
    now = datetime.now()
    ran, errors = [], []
    try:
        for section in repo.list_active_sections():
            last = repo.last_run_time(section["id"])
            if is_due(section["cron_schedule"], last, now):
                try:
                    ingest_section(repo, section["id"])
                    ran.append(section["id"])
                except Exception as exc:  # noqa: BLE001 - keep going for other sections
                    errors.append((section["id"], str(exc)))
        print(f"scheduler {now:%Y-%m-%d %H:%M}: ran={ran} errors={errors}")
        return 0 if not errors else 1
    finally:
        ten.close()
        admin.close()


if __name__ == "__main__":
    raise SystemExit(main())
