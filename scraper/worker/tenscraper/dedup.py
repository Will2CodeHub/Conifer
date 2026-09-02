"""URL normalisation, hashing, and story clustering keys.

- ``url_hash`` gives the dedup key stored as ``ten_scraper_items.source_url_hash``
  and compared against ``admin_ten.articles.news_scrape_url_hash``.
- ``cluster_key`` gives a best-effort "same story" grouping key so the same
  event reported by several outlets can be shown once in review.
"""

from __future__ import annotations

import hashlib
import re
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

# Query params that never identify the article itself — drop them before hashing.
_TRACKING_PARAMS = {
    "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
    "fbclid", "gclid", "mc_cid", "mc_eid", "ref", "ref_src", "cmpid",
    "icid", "igshid", "spm",
}

_DEFAULT_PORTS = {"http": "80", "https": "443"}

# Short, common words ignored when building a clustering signature.
# Kept intentionally small and multilingual (en + de) for TEN's use.
_STOPWORDS = {
    # English
    "the", "a", "an", "and", "or", "of", "to", "in", "on", "for", "with",
    "at", "by", "from", "as", "is", "are", "was", "were", "be", "has", "have",
    "after", "over", "new", "says", "say", "amid",
    # German
    "der", "die", "das", "und", "oder", "von", "zu", "im", "in", "fuer", "für",
    "mit", "am", "auf", "den", "dem", "ein", "eine", "ist", "sind", "war",
    "nach", "ueber", "über", "neue", "neu",
}

_WORD_RE = re.compile(r"[^\w]+", re.UNICODE)


def normalize_url(url: str) -> str:
    """Return a canonical form of ``url`` for stable hashing.

    Lowercases scheme/host, drops the fragment, strips tracking params and
    default ports, and removes a trailing slash from the path.
    """
    url = (url or "").strip()
    if not url:
        return ""
    parts = urlsplit(url)

    scheme = parts.scheme.lower()
    host = parts.hostname or ""
    host = host.lower()

    netloc = host
    if parts.port is not None and _DEFAULT_PORTS.get(scheme) != str(parts.port):
        netloc = f"{host}:{parts.port}"

    path = parts.path
    if len(path) > 1 and path.endswith("/"):
        path = path.rstrip("/")

    kept = [(k, v) for k, v in parse_qsl(parts.query, keep_blank_values=True)
            if k.lower() not in _TRACKING_PARAMS]
    kept.sort()
    query = urlencode(kept)

    return urlunsplit((scheme, netloc, path, query, ""))


def url_hash(url: str) -> str:
    """SHA-256 of the normalised URL (hex)."""
    return hashlib.sha256(normalize_url(url).encode("utf-8")).hexdigest()


def _significant_tokens(title: str) -> list[str]:
    lowered = (title or "").lower()
    tokens = [t for t in _WORD_RE.split(lowered) if t]
    return [t for t in tokens if t not in _STOPWORDS and len(t) > 2]


def cluster_key(title: str) -> str:
    """Best-effort signature grouping headlines about the same story.

    Case-, punctuation-, and stopword-insensitive; token order does not matter.
    Returns a SHA-1 hex of the sorted unique significant tokens, or "" when the
    title has no significant tokens.
    """
    tokens = sorted(set(_significant_tokens(title)))
    if not tokens:
        return ""
    return hashlib.sha1(" ".join(tokens).encode("utf-8")).hexdigest()
