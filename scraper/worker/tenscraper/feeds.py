"""Parse RSS/Atom feed content into RawItem objects using feedparser."""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import List, Optional

import feedparser

from .models import RawItem

_TAG_RE = re.compile(r"<[^>]+>")
_WS_RE = re.compile(r"\s+")


def _clean_text(value: str) -> str:
    """Strip HTML tags and collapse whitespace from a feed summary."""
    if not value:
        return ""
    text = _TAG_RE.sub(" ", value)
    return _WS_RE.sub(" ", text).strip()


def _entry_date(entry) -> Optional[datetime]:
    for attr in ("published_parsed", "updated_parsed"):
        parsed = entry.get(attr)
        if parsed:
            # feedparser returns a time.struct_time in UTC.
            return datetime(*parsed[:6], tzinfo=timezone.utc)
    return None


def parse_feed(content: str, source_category_label: Optional[str] = None) -> List[RawItem]:
    """Parse feed ``content`` (RSS or Atom) into a list of RawItem.

    Entries without a link are skipped (nothing to fetch or dedup on).
    """
    parsed = feedparser.parse(content or "")
    items: List[RawItem] = []
    for entry in parsed.entries:
        link = (entry.get("link") or "").strip()
        if not link:
            continue
        items.append(
            RawItem(
                source_url=link,
                title=(entry.get("title") or "").strip(),
                summary=_clean_text(entry.get("summary") or ""),
                published_at=_entry_date(entry),
                source_category_label=source_category_label,
            )
        )
    return items
