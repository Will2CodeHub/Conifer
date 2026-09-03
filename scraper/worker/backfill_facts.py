#!/usr/bin/env python3
"""One-off: backfill the `facts` column for existing scraper items that were
ingested before facts-extraction was wired in (status 'new', empty facts).

Fetches each item's source page and stores condensed facts (same builder the
live ingest now uses). DB creds come from the same env vars as run_ingest.py.

    ./venv/bin/python backfill_facts.py --limit 20 [--section 1]
"""
from __future__ import annotations

import argparse
import sys

from tenscraper.adapters import RequestsFetcher, mysql_connect_from_env
from tenscraper.models import RawItem
from tenscraper.politeness import build_user_agent
from tenscraper.service import _make_facts_builder


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=20)
    ap.add_argument("--section", type=int, default=0)
    args = ap.parse_args()

    ten = mysql_connect_from_env("SCRAPER_DB")
    fetcher = RequestsFetcher()
    ua = build_user_agent("tme")
    builder = _make_facts_builder(fetcher, ua)

    where = "status='new' AND (facts IS NULL OR facts='')"
    params = []
    if args.section:
        where += " AND pub_section_id=%s"
        params.append(args.section)
    params.append(args.limit)

    with ten.cursor() as cur:
        cur.execute(
            f"SELECT id, source_url FROM ten_scraper_items WHERE {where} ORDER BY id DESC LIMIT %s",
            tuple(params),
        )
        rows = cur.fetchall()

    updated, empty, errors = 0, 0, []
    for iid, url in rows:
        try:
            facts = builder(RawItem(source_url=url))
            if not facts:
                empty += 1
            with ten.cursor() as c2:
                c2.execute("UPDATE ten_scraper_items SET facts=%s WHERE id=%s", (facts, iid))
            ten.commit()
            updated += 1
        except Exception as exc:  # noqa: BLE001
            errors.append(f"{iid}:{str(exc)[:100]}")

    ten.close()
    print(f"candidates={len(rows)} updated={updated} empty_extract={empty} errors={errors[:8]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
