"""Real-world I/O adapters implementing the injected interfaces.

These touch the network, subprocess, and MySQL, so they are NOT unit-tested on
the dev machine — they are verified server-side in Phase 4. The tested logic
lives in the other modules; these are deliberately thin.
"""

from __future__ import annotations

import subprocess
from typing import List, Optional, Set
from urllib.parse import urlsplit, urlunsplit

import requests

from .ingest import FeedConfig
from .models import RawItem
from .vpn import VpnProfile


# ---------------------------------------------------------------------------
# Network
# ---------------------------------------------------------------------------

class RequestsFetcher:
    """HTTP GET with an honest UA and a timeout."""

    def __init__(self, timeout: float = 20.0):
        self.timeout = timeout

    def fetch(self, url: str, user_agent: str) -> str:
        resp = requests.get(url, headers={"User-Agent": user_agent}, timeout=self.timeout)
        resp.raise_for_status()
        return resp.text

    def fetch_robots(self, url: str, user_agent: str) -> str:
        parts = urlsplit(url)
        robots_url = urlunsplit((parts.scheme, parts.netloc, "/robots.txt", "", ""))
        try:
            resp = requests.get(robots_url, headers={"User-Agent": user_agent}, timeout=self.timeout)
            if resp.status_code == 200:
                return resp.text
        except requests.RequestException:
            pass
        return ""  # no robots.txt reachable -> treat as unrestricted


def country_ip_checker(timeout: float = 10.0) -> str:
    """Return the ISO country code of the current egress IP (for VPN verify)."""
    resp = requests.get("https://ipapi.co/country/", timeout=timeout)
    resp.raise_for_status()
    return resp.text.strip()


def subprocess_runner(command: List[str]) -> int:
    """Execute a VPN command; return its exit code."""
    completed = subprocess.run(command, capture_output=True, text=True)
    return completed.returncode


# ---------------------------------------------------------------------------
# Database (PyMySQL)
# ---------------------------------------------------------------------------

def mysql_connect_from_env(prefix: str):
    """Open a PyMySQL connection from <PREFIX>_HOST/USER/PASS/NAME env vars."""
    import os
    import pymysql

    return pymysql.connect(
        host=os.environ[f"{prefix}_HOST"],
        user=os.environ[f"{prefix}_USER"],
        password=os.environ[f"{prefix}_PASS"],
        database=os.environ[f"{prefix}_NAME"],
        charset="utf8mb4",
        autocommit=False,
    )


class PyMySQLRepo:
    """ScraperRepo + config loader backed by MySQL (server-side).

    Two logical databases: ``ten`` (TEN_Management, the scraper tables) and
    ``admin`` (admin_ten, the published articles). Both are on localhost.
    """

    def __init__(self, ten_conn, admin_conn):
        self._ten = ten_conn
        self._admin = admin_conn

    # --- scheduler support ------------------------------------------------

    def list_active_sections(self) -> List[dict]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT id, cron_schedule FROM ten_scraper_pub_sections WHERE is_active = 1"
            )
            return [{"id": r[0], "cron_schedule": r[1]} for r in cur.fetchall()]

    def last_run_time(self, pub_section_id: int):
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT MAX(started) FROM ten_scraper_runs "
                "WHERE pub_section_id = %s AND status = 'ok'",
                (pub_section_id,),
            )
            row = cur.fetchone()
        return row[0] if row else None

    # --- manual "run now" request queue -----------------------------------

    def ensure_run_requests_table(self) -> None:
        with self._ten.cursor() as cur:
            cur.execute(
                "CREATE TABLE IF NOT EXISTS ten_scraper_run_requests ("
                " id INT AUTO_INCREMENT PRIMARY KEY,"
                " pub_section_id INT NOT NULL,"
                " requested_by INT NULL,"
                " requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                " status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',"
                " started_at DATETIME NULL,"
                " finished_at DATETIME NULL,"
                " result VARCHAR(255) NULL,"
                " error VARCHAR(500) NULL,"
                " KEY idx_status (status),"
                " KEY idx_section (pub_section_id)"
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            )
        self._ten.commit()

    def pending_run_request_ids(self) -> List[int]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT id FROM ten_scraper_run_requests WHERE status='pending' ORDER BY id"
            )
            return [r[0] for r in cur.fetchall()]

    def claim_run_request(self, request_id: int):
        """Atomically flip one pending request to 'running'. Returns its
        pub_section_id, or None if another worker already claimed it."""
        with self._ten.cursor() as cur:
            cur.execute(
                "UPDATE ten_scraper_run_requests SET status='running', started_at=NOW() "
                "WHERE id=%s AND status='pending'",
                (request_id,),
            )
            if cur.rowcount == 0:
                self._ten.commit()
                return None
            cur.execute(
                "SELECT pub_section_id FROM ten_scraper_run_requests WHERE id=%s",
                (request_id,),
            )
            row = cur.fetchone()
        self._ten.commit()
        return row[0] if row else None

    def finish_run_request(self, request_id: int, status: str,
                           result: str = None, error: str = None) -> None:
        with self._ten.cursor() as cur:
            cur.execute(
                "UPDATE ten_scraper_run_requests SET status=%s, result=%s, error=%s, "
                "finished_at=NOW() WHERE id=%s",
                (status, result, error, request_id),
            )
        self._ten.commit()

    # --- config loading ---------------------------------------------------

    def load_section(self, pub_section_id: int) -> Optional[dict]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT id, project_id, publication_key, ten_section, daily_count, "
                "vpn_profile_id, journalist_id, auto_publish, is_active "
                "FROM ten_scraper_pub_sections WHERE id=%s",
                (pub_section_id,),
            )
            row = cur.fetchone()
        if not row:
            return None
        cols = ["id", "project_id", "publication_key", "ten_section", "daily_count",
                "vpn_profile_id", "journalist_id", "auto_publish", "is_active"]
        return dict(zip(cols, row))

    def load_feeds(self, pub_section_id: int) -> List[FeedConfig]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT f.id, f.feed_url, f.feed_type, f.source_category_label, "
                "f.respect_robots, f.robots_override_reason, f.rate_limit_seconds, f.max_items "
                "FROM ten_scraper_feeds f "
                "JOIN ten_scraper_sources s ON s.id = f.source_id "
                "WHERE s.pub_section_id=%s AND f.is_active=1 AND s.is_active=1",
                (pub_section_id,),
            )
            rows = cur.fetchall()
        feeds: List[FeedConfig] = []
        for r in rows:
            feeds.append(FeedConfig(
                feed_id=r[0],
                feed_url=r[1],
                feed_type=r[2] or "rss",
                source_category_label=r[3],
                respect_robots=bool(r[4]),
                robots_override_reason=r[5],
                rate_limit_seconds=r[6],
                max_items=r[7],
            ))
        return feeds

    def load_vpn_profile(self, vpn_profile_id: int) -> Optional[VpnProfile]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT provider, name, country, config_ref FROM ten_scraper_vpn_profiles "
                "WHERE id=%s AND is_active=1",
                (vpn_profile_id,),
            )
            row = cur.fetchone()
        if not row:
            return None
        return VpnProfile(provider=row[0], name=row[1], country=row[2] or "", config_ref=row[3] or "")

    # --- run logging ------------------------------------------------------

    def start_run(self, pub_section_id: int) -> int:
        with self._ten.cursor() as cur:
            cur.execute(
                "INSERT INTO ten_scraper_runs (pub_section_id, status) VALUES (%s, 'running')",
                (pub_section_id,),
            )
            run_id = cur.lastrowid
        self._ten.commit()
        return run_id

    def finish_run(self, run_id: int, items_found: int, items_new: int, status: str, log: str) -> None:
        with self._ten.cursor() as cur:
            cur.execute(
                "UPDATE ten_scraper_runs SET finished=NOW(), items_found=%s, items_new=%s, "
                "status=%s, log=%s WHERE id=%s",
                (items_found, items_new, status, log, run_id),
            )
        self._ten.commit()

    # --- ScraperRepo protocol --------------------------------------------

    def existing_item_hashes(self, pub_section_id: int) -> Set[str]:
        with self._ten.cursor() as cur:
            cur.execute(
                "SELECT source_url_hash FROM ten_scraper_items WHERE pub_section_id=%s",
                (pub_section_id,),
            )
            return {r[0] for r in cur.fetchall()}

    def article_scrape_hashes(self) -> Set[str]:
        with self._admin.cursor() as cur:
            cur.execute(
                "SELECT news_scrape_url_hash FROM articles "
                "WHERE news_scrape_url_hash IS NOT NULL AND news_scrape_url_hash <> ''"
            )
            return {r[0] for r in cur.fetchall()}

    def insert_item(self, pub_section_id, feed_id, item: RawItem, source_url_hash, cluster_id, facts: str = "") -> int:
        published = item.published_at.strftime("%Y-%m-%d %H:%M:%S") if item.published_at else None
        with self._ten.cursor() as cur:
            # INSERT IGNORE: a duplicate (pub_section_id, source_url_hash) — the
            # same story arriving via two feeds in one section — is skipped rather
            # than raising and aborting the whole section's ingest.
            cur.execute(
                "INSERT IGNORE INTO ten_scraper_items "
                "(feed_id, pub_section_id, source_url, source_url_hash, title, summary, facts, "
                " published_at, cluster_id, status) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'new')",
                (feed_id, pub_section_id, item.source_url, source_url_hash,
                 item.title, item.summary, facts, published, cluster_id),
            )
            item_id = cur.lastrowid
        self._ten.commit()
        return item_id
