"""Extract candidate article links from a feedless source's listing/index page.

Used for `feed_type='html'` sources: fetch the listing HTML, pull same-site
article links (absolute URLs + anchor text as provisional title). The article
page itself is then fetched and passed through ``html_fallback.extract_facts``.

Heuristics are deliberately conservative and can be tightened per-source later
via the feed's ``html_selectors`` config.
"""

from __future__ import annotations

import re
from html.parser import HTMLParser
from typing import List, Optional
from urllib.parse import urljoin, urlsplit

from .dedup import normalize_url
from .models import RawItem

_WS_RE = re.compile(r"\s+")
_SKIP_SCHEMES = ("mailto:", "javascript:", "tel:")


class _AnchorCollector(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.anchors: List[tuple[str, str]] = []  # (href, text)
        self._href: Optional[str] = None
        self._text: List[str] = []

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            href = dict(attrs).get("href")
            if href:
                self._href = href
                self._text = []

    def handle_data(self, data):
        if self._href is not None:
            self._text.append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._href is not None:
            text = _WS_RE.sub(" ", "".join(self._text)).strip()
            self.anchors.append((self._href, text))
            self._href = None
            self._text = []


def extract_article_links(
    html: str,
    base_url: str,
    category: Optional[str] = None,
    min_title_len: int = 15,
) -> List[RawItem]:
    """Return same-host article candidates as RawItem(url, title).

    Filters out external links, fragments, mailto/js, the base path itself, and
    anchors whose visible text is too short to be a headline. Deduplicates by
    normalised URL, preserving first-seen order.
    """
    parser = _AnchorCollector()
    parser.feed(html or "")

    base_host = (urlsplit(base_url).hostname or "").lower()
    seen: set[str] = set()
    items: List[RawItem] = []

    for href, text in parser.anchors:
        href = href.strip()
        if not href or href.startswith("#"):
            continue
        if any(href.lower().startswith(s) for s in _SKIP_SCHEMES):
            continue

        absolute = urljoin(base_url, href)
        parts = urlsplit(absolute)
        if parts.scheme not in ("http", "https"):
            continue
        if (parts.hostname or "").lower() != base_host:
            continue
        if parts.path in ("", "/"):
            continue
        if len(text) < min_title_len:
            continue

        key = normalize_url(absolute)
        if key in seen:
            continue
        seen.add(key)
        items.append(RawItem(source_url=absolute, title=text, source_category_label=category))

    return items
