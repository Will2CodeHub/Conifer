# TEN Scraper Worker

Python ingestion engine for the TEN Scraper system. Turns configured feeds/
sources into deduplicated `ten_scraper_items`, respecting politeness rules and
an optional geo-appropriate VPN egress.

## Layout

- `tenscraper/` — the package. Pure logic (dedup, politeness, feeds,
  html_fallback, vpn, ingest) is dependency-injected and unit-tested offline.
  `adapters.py` holds the real I/O (requests, subprocess, PyMySQL) and is
  verified server-side.
- `run_ingest.py` — CLI entrypoint: ingest one `(publication, section)`.
- `tests/` — offline test suite (mocked HTTP, local fixtures, in-memory repo).

## Develop / test (dev machine)

```bash
python -m venv venv
venv/Scripts/python.exe -m pip install -r requirements.txt   # Windows
# or: venv/bin/python -m pip install -r requirements.txt      # Linux/server
venv/Scripts/python.exe -m pytest -q
```

All tests run without network, DB, or VPN.

## Run on the server (Phase 4)

Install deps (incl. optional `trafilatura` for better HTML extraction, and
`PyMySQL`). The master PHP scheduler exports DB credentials as env vars, then:

```bash
python run_ingest.py --pub-section-id 7
```

Required environment variables (exported by the cron wrapper so secrets are not
duplicated in Python):

| Variable | Meaning |
|---|---|
| `SCRAPER_DB_HOST/USER/PASS/NAME` | TEN_Management (scraper tables) |
| `SCRAPER_ADMIN_DB_HOST/USER/PASS/NAME` | admin_ten (published articles, for dedup) |

## Safety posture (enforced in code)

- Honest `User-Agent` always sent (`build_user_agent`).
- robots.txt respected unless a feed sets `respect_robots=0` **with** a logged
  `robots_override_reason` (own/permitted sources only).
- Per-domain rate limiting.
- VPN egress is geo-access only; the egress country is verified before fetching
  and the run aborts on mismatch. No per-request IP rotation.
- Only condensed facts are stored; the fetched source body is not persisted.
