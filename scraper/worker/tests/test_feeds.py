from tenscraper.feeds import parse_feed


def test_parse_rss_returns_items(read_fixture):
    items = parse_feed(read_fixture("sample_rss.xml"), source_category_label="Local")
    assert len(items) == 2

    first = items[0]
    assert first.title == "Munich transit fares to rise in 2026"
    assert "munich-fares" in first.source_url
    assert first.summary == "The city announced higher fares."   # tags stripped
    assert first.source_category_label == "Local"
    assert first.published_at is not None
    assert (first.published_at.year, first.published_at.month, first.published_at.day) == (2025, 9, 1)
    assert first.published_at.hour == 8


def test_parse_atom_skips_entries_without_link(read_fixture):
    items = parse_feed(read_fixture("sample_atom.xml"))
    assert len(items) == 1
    assert items[0].title == "Atom entry one"
    assert items[0].source_url == "https://atom.example.com/one"


def test_parse_empty_content_returns_empty():
    assert parse_feed("") == []
