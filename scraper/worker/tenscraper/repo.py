"""Repository interface for the worker's DB reads/writes.

The orchestration in ``ingest.py`` depends only on the ``ScraperRepo`` protocol,
so it is unit-tested with ``InMemoryRepo``. The real PyMySQL-backed repository
(server-side, Phase 4) implements the same three methods.
"""

from __future__ import annotations

from typing import Dict, List, Optional, Protocol, Set

from .models import RawItem


class ScraperRepo(Protocol):
    def existing_item_hashes(self, pub_section_id: int) -> Set[str]:
        """Hashes already collated for this section (ten_scraper_items)."""
        ...

    def article_scrape_hashes(self) -> Set[str]:
        """Hashes already promoted to admin_ten.articles.news_scrape_url_hash."""
        ...

    def insert_item(
        self,
        pub_section_id: int,
        feed_id: int,
        item: RawItem,
        source_url_hash: str,
        cluster_id: str,
    ) -> int:
        """Persist a new item; return its id."""
        ...


class InMemoryRepo:
    """Test double implementing ScraperRepo."""

    def __init__(
        self,
        existing_hashes: Optional[Set[str]] = None,
        article_hashes: Optional[Set[str]] = None,
    ):
        self._existing: Set[str] = set(existing_hashes or set())
        self._article_hashes: Set[str] = set(article_hashes or set())
        self.inserted: List[Dict] = []
        self._next_id = 1

    def existing_item_hashes(self, pub_section_id: int) -> Set[str]:
        return set(self._existing)

    def article_scrape_hashes(self) -> Set[str]:
        return set(self._article_hashes)

    def insert_item(
        self,
        pub_section_id: int,
        feed_id: int,
        item: RawItem,
        source_url_hash: str,
        cluster_id: str,
    ) -> int:
        row = {
            "id": self._next_id,
            "pub_section_id": pub_section_id,
            "feed_id": feed_id,
            "source_url": item.source_url,
            "source_url_hash": source_url_hash,
            "title": item.title,
            "summary": item.summary,
            "cluster_id": cluster_id,
            "source_category_label": item.source_category_label,
        }
        self.inserted.append(row)
        self._existing.add(source_url_hash)
        self._next_id += 1
        return row["id"]
