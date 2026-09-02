from tenscraper.dedup import url_hash
from tenscraper.ingest import FeedConfig, run_ingest
from tenscraper.politeness import RateLimiter
from tenscraper.repo import InMemoryRepo

FEED_URL = "https://news.example.com/feed"
MUNICH = "https://news.example.com/munich-fares"
MUSEUM = "https://news.example.com/museum"


def _no_wait_limiter():
    return RateLimiter(0.0, sleep=lambda s: None)


def _make_feed(**kw):
    return FeedConfig(feed_id=1, feed_url=FEED_URL, **kw)


def test_ingest_inserts_new_items(read_fixture):
    rss = read_fixture("sample_rss.xml")
    seen_ua = {}

    def fetcher(url, ua):
        seen_ua["ua"] = ua
        return rss

    repo = InMemoryRepo()
    result = run_ingest(
        pub_section_id=7,
        feeds=[_make_feed(source_category_label="Local")],
        repo=repo,
        fetcher=fetcher,
        user_agent="TENNewsBot/1.0 (+https://theeyenewspapers.com/bot; publication=tme)",
        rate_limiter=_no_wait_limiter(),
    )
    assert result.items_found == 2
    assert result.items_new == 2
    assert len(repo.inserted) == 2
    assert repo.inserted[0]["pub_section_id"] == 7
    assert repo.inserted[0]["source_category_label"] == "Local"
    assert "publication=tme" in seen_ua["ua"]


def test_ingest_skips_items_already_collated(read_fixture):
    rss = read_fixture("sample_rss.xml")
    repo = InMemoryRepo(existing_hashes={url_hash(MUNICH)})
    result = run_ingest(
        pub_section_id=7,
        feeds=[_make_feed()],
        repo=repo,
        fetcher=lambda url, ua: rss,
        user_agent="ua",
        rate_limiter=_no_wait_limiter(),
    )
    assert result.items_found == 2
    assert result.items_new == 1
    assert result.skipped_dupe == 1
    assert repo.inserted[0]["source_url"].endswith("/museum")


def test_ingest_skips_items_already_published(read_fixture):
    rss = read_fixture("sample_rss.xml")
    repo = InMemoryRepo(article_hashes={url_hash(MUSEUM)})
    result = run_ingest(
        pub_section_id=7,
        feeds=[_make_feed()],
        repo=repo,
        fetcher=lambda url, ua: rss,
        user_agent="ua",
        rate_limiter=_no_wait_limiter(),
    )
    assert result.items_new == 1
    assert result.skipped_dupe == 1
    assert all("museum" not in row["source_url"] for row in repo.inserted)


def test_ingest_respects_robots_disallow(read_fixture):
    rss = read_fixture("sample_rss.xml")
    repo = InMemoryRepo()

    def robots_fetcher(url, ua):
        return "User-agent: *\nDisallow: /"

    result = run_ingest(
        pub_section_id=7,
        feeds=[_make_feed(respect_robots=True)],
        repo=repo,
        fetcher=lambda url, ua: rss,
        user_agent="ua",
        robots_fetcher=robots_fetcher,
        rate_limiter=_no_wait_limiter(),
    )
    assert result.skipped_robots == 1
    assert result.items_new == 0
    assert repo.inserted == []


def test_ingest_robots_override_allows_fetch(read_fixture):
    rss = read_fixture("sample_rss.xml")
    repo = InMemoryRepo()
    result = run_ingest(
        pub_section_id=7,
        feeds=[_make_feed(respect_robots=False, robots_override_reason="own site")],
        repo=repo,
        fetcher=lambda url, ua: rss,
        user_agent="ua",
        robots_fetcher=lambda url, ua: "User-agent: *\nDisallow: /",
        rate_limiter=_no_wait_limiter(),
    )
    assert result.skipped_robots == 0
    assert result.items_new == 2
