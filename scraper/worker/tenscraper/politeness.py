"""Politeness controls: honest identification, robots.txt, rate limiting.

Three independent controls (spec safety posture):
- ``build_user_agent`` — always-on honest identification.
- ``RobotsPolicy`` — respects robots.txt unless explicitly overridden with a
  logged reason (for own/permitted sources only).
- ``RateLimiter`` — per-domain minimum interval; clock/sleep injected for tests.
"""

from __future__ import annotations

import time
from typing import Callable, Dict, Optional
from urllib import robotparser
from urllib.parse import urlsplit


def build_user_agent(publication: str, contact_url: str = "https://theeyenewspapers.com/bot") -> str:
    """An honest User-Agent that names the publication and a contact URL."""
    pub = (publication or "unknown").strip()
    return f"TENNewsBot/1.0 (+{contact_url}; publication={pub})"


class RobotsPolicy:
    """Decide whether a URL may be fetched under robots.txt.

    When ``respect_robots`` is False, ``can_fetch`` always returns True — this
    is the explicit, logged override for own/permitted sources. ``override_reason``
    is carried for audit logging by the caller.
    """

    def __init__(self, respect_robots: bool = True, override_reason: Optional[str] = None):
        self.respect_robots = respect_robots
        self.override_reason = override_reason

    def can_fetch(self, robots_txt: str, url: str, user_agent: str) -> bool:
        if not self.respect_robots:
            return True
        parser = robotparser.RobotFileParser()
        parser.parse((robots_txt or "").splitlines())
        return parser.can_fetch(user_agent, url)


class RateLimiter:
    """Enforce a minimum delay between fetches to the same domain.

    ``clock`` and ``sleep`` are injectable so tests need no real time to pass.
    """

    def __init__(
        self,
        delay_seconds: float,
        clock: Callable[[], float] = time.monotonic,
        sleep: Callable[[float], None] = time.sleep,
    ):
        self.delay_seconds = max(0.0, float(delay_seconds))
        self._clock = clock
        self._sleep = sleep
        self._last_fetch: Dict[str, float] = {}

    @staticmethod
    def _domain(url: str) -> str:
        return (urlsplit(url).hostname or "").lower()

    def wait(self, url: str) -> float:
        """Block until this domain may be fetched again. Returns seconds slept."""
        domain = self._domain(url)
        now = self._clock()
        last = self._last_fetch.get(domain)
        slept = 0.0
        if last is not None and self.delay_seconds > 0:
            elapsed = now - last
            remaining = self.delay_seconds - elapsed
            if remaining > 0:
                self._sleep(remaining)
                slept = remaining
                now = now + remaining
        self._last_fetch[domain] = now
        return slept
