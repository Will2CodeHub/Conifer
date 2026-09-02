from tenscraper.html_listing import extract_article_links
from tenscraper.ingest import FeedConfig, run_ingest
from tenscraper.politeness import RateLimiter
from tenscraper.repo import InMemoryRepo

BASE = "https://news.example.com/local"


def test_extract_links_returns_same_host_articles(read_fixture):
    items = extract_article_links(read_fixture("sample_listing.html"), BASE, category="Local")
    urls = [i.source_url for i in items]
    assert urls == [
        "https://news.example.com/news/council-approves-new-bridge-project",
        "https://news.example.com/news/school-budget-vote-passes-narrowly",
    ]
    assert items[0].title == "Council approves new bridge project downtown"
    assert all(i.source_category_label == "Local" for i in items)


def test_extract_links_excludes_external_short_and_mailto(read_fixture):
    items = extract_article_links(read_fixture("sample_listing.html"), BASE)
    joined = " ".join(i.source_url for i in items)
    assert "other-site.example.org" not in joined   # external excluded
    assert "mailto" not in joined                    # mailto excluded
    assert "/news/x" not in joined                   # short title excluded
    assert len(items) == 2                            # duplicate collapsed


def test_ingest_html_feed_uses_lister(read_fixture):
    listing = read_fixture("sample_listing.html")
    repo = InMemoryRepo()
    result = run_ingest(
        pub_section_id=1,
        feeds=[FeedConfig(feed_id=1, feed_url=BASE, feed_type="html")],
        repo=repo,
        fetcher=lambda url, ua: listing,
        user_agent="ua",
        html_lister=extract_article_links,
        rate_limiter=RateLimiter(0.0, sleep=lambda s: None),
    )
    assert result.items_found == 2
    assert result.items_new == 2


def test_ingest_html_feed_without_lister_records_error(read_fixture):
    repo = InMemoryRepo()
    result = run_ingest(
        pub_section_id=1,
        feeds=[FeedConfig(feed_id=1, feed_url=BASE, feed_type="html")],
        repo=repo,
        fetcher=lambda url, ua: "<html></html>",
        user_agent="ua",
        rate_limiter=RateLimiter(0.0, sleep=lambda s: None),
    )
    assert result.items_new == 0
    assert any("no html_lister" in e for e in result.errors)
