"""Extract condensed facts from an article HTML page (feedless sources).

Uses trafilatura when available for high-quality extraction; otherwise falls
back to a dependency-free stdlib extractor. Tests target the stdlib extractor,
so trafilatura is never required to run them.

Only condensed facts are returned — the full source body is not persisted
(spec safety posture).
"""

from __future__ import annotations

import re
from html.parser import HTMLParser
from typing import Callable, List, Optional, Tuple

from .models import ArticleFacts

_WS_RE = re.compile(r"\s+")

# An extractor takes (html, url) and returns (title, summary, key_points).
Extractor = Callable[[str, str], Tuple[str, str, List[str]]]


class _BasicHTMLExtractor(HTMLParser):
    """Pull <title>, meta description, and paragraph text from HTML."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title_parts: List[str] = []
        self.meta_description: str = ""
        self._paragraphs: List[str] = []
        self._buf: List[str] = []
        self._in_title = False
        self._capture_depth = 0  # inside <p>
        self._skip_depth = 0     # inside <script>/<style>

    def handle_starttag(self, tag, attrs):
        if tag == "title":
            self._in_title = True
        elif tag in ("script", "style"):
            self._skip_depth += 1
        elif tag == "p":
            self._capture_depth += 1
            self._buf = []
        elif tag == "meta":
            a = dict(attrs)
            name = (a.get("name") or a.get("property") or "").lower()
            if name in ("description", "og:description") and a.get("content"):
                if not self.meta_description:
                    self.meta_description = a["content"].strip()

    def handle_endtag(self, tag):
        if tag == "title":
            self._in_title = False
        elif tag in ("script", "style"):
            self._skip_depth = max(0, self._skip_depth - 1)
        elif tag == "p" and self._capture_depth > 0:
            self._capture_depth -= 1
            text = _WS_RE.sub(" ", "".join(self._buf)).strip()
            if text:
                self._paragraphs.append(text)
            self._buf = []

    def handle_data(self, data):
        if self._skip_depth:
            return
        if self._in_title:
            self.title_parts.append(data)
        elif self._capture_depth:
            self._buf.append(data)

    @property
    def title(self) -> str:
        return _WS_RE.sub(" ", "".join(self.title_parts)).strip()

    @property
    def paragraphs(self) -> List[str]:
        return self._paragraphs


def basic_extractor(html: str, url: str) -> Tuple[str, str, List[str]]:
    """Dependency-free extraction: title, summary, first few paragraphs."""
    parser = _BasicHTMLExtractor()
    parser.feed(html or "")
    paragraphs = parser.paragraphs
    summary = parser.meta_description or (paragraphs[0] if paragraphs else "")
    key_points = paragraphs[:5]
    return parser.title, summary, key_points


def _trafilatura_extractor(html: str, url: str) -> Tuple[str, str, List[str]]:  # pragma: no cover - server only
    import trafilatura  # imported lazily; optional dependency

    extracted = trafilatura.extract(html, url=url, include_comments=False) or ""
    paragraphs = [p.strip() for p in extracted.split("\n") if p.strip()]
    title, summary_fallback, _ = basic_extractor(html, url)
    summary = paragraphs[0] if paragraphs else summary_fallback
    return title, summary, paragraphs[:5]


def _default_extractor() -> Extractor:
    try:
        import trafilatura  # noqa: F401
        return _trafilatura_extractor
    except Exception:
        return basic_extractor


def extract_facts(html: str, url: str, extractor: Optional[Extractor] = None) -> ArticleFacts:
    """Return ArticleFacts extracted from ``html``.

    ``extractor`` is injectable for testing; when omitted, trafilatura is used
    if importable, else the stdlib ``basic_extractor``.
    """
    chosen = extractor or _default_extractor()
    title, summary, key_points = chosen(html, url)
    return ArticleFacts(
        source_url=url,
        title=title,
        summary=summary,
        key_points=list(key_points),
    )
