from tenscraper.html_fallback import basic_extractor, extract_facts


def test_basic_extractor_pulls_title_meta_and_paragraphs(read_fixture):
    html = read_fixture("sample_article.html")
    title, summary, key_points = basic_extractor(html, "https://x.example.com/bridge")
    assert title == "Council approves new bridge"
    assert summary == "The council approved funding for a new bridge across the river."
    assert len(key_points) == 2
    assert key_points[0].startswith("The city council voted")


def test_basic_extractor_skips_script_and_style(read_fixture):
    html = read_fixture("sample_article.html")
    _, summary, key_points = basic_extractor(html, "https://x.example.com/bridge")
    joined = summary + " " + " ".join(key_points)
    assert "secret" not in joined
    assert "color:red" not in joined


def test_extract_facts_uses_injected_extractor():
    def fake_extractor(html, url):
        return ("T", "S", ["p1", "p2"])

    facts = extract_facts("<html></html>", "https://x.example.com/a", extractor=fake_extractor)
    assert facts.title == "T"
    assert facts.summary == "S"
    assert facts.key_points == ["p1", "p2"]
    assert facts.source_url == "https://x.example.com/a"
