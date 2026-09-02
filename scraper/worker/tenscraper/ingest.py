"""Ingestion orchestration: feeds -> deduped items.

Pure of I/O specifics — network fetching, robots fetching, DB, clock are all
injected — so the whole flow is unit-tested with fakes.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Callable, List, Optional

from .dedup import cluster_key, url_hash
from .feeds import parse_feed
from .models import RawItem
from .politeness import RateLimiter, RobotsPolicy
from .repo import ScraperRepo

# fetcher(url, user_agent) -> str   (page/feed body)
Fetcher = Callable[[str, str], str]
# robots_fetcher(url, user_agent) -> str   (robots.txt body for that URL's host)
RobotsFetcher = Callable[[str, str], str]
# html_lister(content, base_url, category) -> list[RawItem]   (Phase 3b; optional)
HtmlLister = Callable[[str, str, Optional[str]], List[RawItem]]


@dataclass
class FeedConfig:
    feed_id: int
    feed_url: str
    feed_type: str = "rss"                     # 'rss' | 'html'
    source_category_label: Optional[str] = None
    respect_robots: bool = True
    robots_override_reason: Optional[str] = None
    rate_limit_seconds: Optional[float] = None


@dataclass
class IngestResult:
    items_found: int = 0
    items_new: int = 0
    skipped_dupe: int = 0
    skipped_robots: int = 0
    errors: List[str] = field(default_factory=list)


def run_ingest(
    pub_section_id: int,
    feeds: List[FeedConfig],
    repo: ScraperRepo,
    fetcher: Fetcher,
    user_agent: str,
    robots_fetcher: Optional[RobotsFetcher] = None,
    rate_limiter: Optional[RateLimiter] = None,
    html_lister: Optional[HtmlLister] = None,
    default_rate_seconds: float = 2.0,
) -> IngestResult:
    """Fetch each feed, parse, dedup, and insert new items. Returns a summary."""
    result = IngestResult()

    # Seed the "seen" set with what we already have and what's already published.
    seen = set(repo.existing_item_hashes(pub_section_id))
    seen |= set(repo.article_scrape_hashes())

    for feed in feeds:
        policy = RobotsPolicy(feed.respect_robots, feed.robots_override_reason)

        # robots.txt gate (default on).
        if policy.respect_robots and robots_fetcher is not None:
            try:
                robots_txt = robots_fetcher(feed.feed_url, user_agent)
            except Exception as exc:  # a robots fetch failure should not crash the run
                robots_txt = ""
                result.errors.append(f"robots fetch failed for {feed.feed_url}: {exc}")
            if not policy.can_fetch(robots_txt, feed.feed_url, user_agent):
                result.skipped_robots += 1
                continue

        # Rate limit per domain.
        limiter = rate_limiter
        if limiter is None:
            delay = feed.rate_limit_seconds if feed.rate_limit_seconds is not None else default_rate_seconds
            limiter = RateLimiter(delay)
        limiter.wait(feed.feed_url)

        # Fetch + parse.
        try:
            content = fetcher(feed.feed_url, user_agent)
        except Exception as exc:
            result.errors.append(f"fetch failed for {feed.feed_url}: {exc}")
            continue

        if feed.feed_type == "html":
            if html_lister is None:
                result.errors.append(f"no html_lister for html feed {feed.feed_url}")
                continue
            items = html_lister(content, feed.feed_url, feed.source_category_label)
        else:
            items = parse_feed(content, feed.source_category_label)

        result.items_found += len(items)

        for item in items:
            h = url_hash(item.source_url)
            if h in seen:
                result.skipped_dupe += 1
                continue
            repo.insert_item(pub_section_id, feed.feed_id, item, h, cluster_key(item.title))
            seen.add(h)
            result.items_new += 1

    return result
