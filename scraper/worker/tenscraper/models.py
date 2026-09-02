"""Data structures passed between worker stages."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
from typing import List, Optional


@dataclass
class RawItem:
    """One entry harvested from a feed or listing, before fact extraction."""

    source_url: str
    title: str = ""
    summary: str = ""
    published_at: Optional[datetime] = None
    source_category_label: Optional[str] = None


@dataclass
class ArticleFacts:
    """Our condensed, storable facts about a source article.

    Deliberately does NOT retain the full source body (spec safety posture).
    """

    source_url: str
    title: str = ""
    summary: str = ""
    byline: Optional[str] = None
    published_at: Optional[datetime] = None
    key_points: List[str] = field(default_factory=list)
