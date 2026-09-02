from datetime import datetime

from tenscraper.schedule import is_due


def test_never_run_is_due():
    assert is_due("0 6 * * *", None, datetime(2026, 9, 2, 7, 0)) is True


def test_daily_not_due_if_ran_after_todays_tick():
    # daily 06:00; now 07:00; last run 06:30 today -> already ran since tick
    assert is_due("0 6 * * *", datetime(2026, 9, 2, 6, 30), datetime(2026, 9, 2, 7, 0)) is False


def test_daily_due_if_last_run_before_todays_tick():
    # last run yesterday 06:30; today's 06:00 tick is newer -> due
    assert is_due("0 6 * * *", datetime(2026, 9, 1, 6, 30), datetime(2026, 9, 2, 7, 0)) is True


def test_hourly_due_and_not_due():
    now = datetime(2026, 9, 2, 7, 15)
    assert is_due("0 * * * *", datetime(2026, 9, 2, 6, 5), now) is True   # before 07:00 tick
    assert is_due("0 * * * *", datetime(2026, 9, 2, 7, 5), now) is False  # after 07:00 tick


def test_before_first_tick_of_day_uses_previous_tick():
    # daily 06:00; now 05:00; prev tick is yesterday 06:00; last run yesterday 06:30 -> not due
    assert is_due("0 6 * * *", datetime(2026, 9, 1, 6, 30), datetime(2026, 9, 2, 5, 0)) is False


def test_invalid_cron_is_never_due():
    assert is_due("not a cron", None, datetime(2026, 9, 2, 7, 0)) is False
