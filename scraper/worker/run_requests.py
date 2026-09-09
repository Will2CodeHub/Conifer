#!/usr/bin/env python3
"""Cron entrypoint: process pending manual "run now" requests only.

The management UI queues immediate-scrape requests into ten_scraper_run_requests.
run_scheduler.py already drains them on its (e.g. 10-min) tick, but for near-
immediate pickup run THIS lightweight script on a short interval — it does
nothing when the queue is empty:

    * * * * * /home/tenuser/scraper_worker/venv/bin/python run_requests.py >> run_requests.log 2>&1

Same env-var DB credentials as run_ingest.py / run_scheduler.py.
"""

from __future__ import annotations

from datetime import datetime

from tenscraper.adapters import PyMySQLRepo, mysql_connect_from_env
from tenscraper.service import drain_run_requests


def main() -> int:
    ten = mysql_connect_from_env("SCRAPER_DB")
    admin = mysql_connect_from_env("SCRAPER_ADMIN_DB")
    repo = PyMySQLRepo(ten, admin)
    try:
        drained = drain_run_requests(repo)
        if drained["ran"] or drained["errors"]:
            print(f"run-requests {datetime.now():%Y-%m-%d %H:%M}: "
                  f"ran={drained['ran']} errors={drained['errors']}")
        return 0 if not drained["errors"] else 1
    finally:
        ten.close()
        admin.close()


if __name__ == "__main__":
    raise SystemExit(main())
