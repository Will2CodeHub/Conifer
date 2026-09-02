"""Decide whether a section is due to run, from its cron schedule + last run.

Uses croniter. Times are treated as naive server-local (the scheduler passes
datetime.now() and the DB's last-run timestamp, both server-local).
"""

from __future__ import annotations

from datetime import datetime
from typing import Optional

from croniter import croniter


def is_due(cron_schedule: str, last_run: Optional[datetime], now: datetime) -> bool:
    """True if a scheduled tick has occurred at or before ``now`` that is more
    recent than ``last_run`` (or if the section has never run).

    An invalid cron expression is treated as never due (the caller logs it).
    """
    try:
        itr = croniter(cron_schedule, now)
    except (ValueError, KeyError, TypeError):
        return False
    prev_tick = itr.get_prev(datetime)  # most recent scheduled time <= now
    if last_run is None:
        return True
    return last_run < prev_tick
