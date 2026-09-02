from tenscraper.dedup import cluster_key, normalize_url, url_hash


def test_normalize_url_drops_fragment_and_tracking_and_trailing_slash():
    assert normalize_url("https://example.com/News/Story?utm_source=x&id=5#top") == \
        "https://example.com/News/Story?id=5"
    assert normalize_url("https://EXAMPLE.com/News/Story/?id=5") == \
        "https://example.com/News/Story?id=5"


def test_normalize_url_default_port_dropped_custom_kept():
    assert normalize_url("https://example.com:443/x") == "https://example.com/x"
    assert normalize_url("http://example.com:8080/x") == "http://example.com:8080/x"


def test_url_hash_equal_for_equivalent_urls():
    a = "https://example.com/News/Story?utm_source=x&id=5#top"
    b = "https://EXAMPLE.com/News/Story/?id=5"
    assert url_hash(a) == url_hash(b)
    assert len(url_hash(a)) == 64  # sha-256 hex


def test_url_hash_differs_for_different_articles():
    assert url_hash("https://example.com/a") != url_hash("https://example.com/b")


def test_cluster_key_same_for_punctuation_and_stopword_variants():
    t1 = "Bayern Munich beat Dortmund in thriller"
    t2 = "Bayern Munich beat Dortmund, in a thriller!"
    assert cluster_key(t1) == cluster_key(t2)


def test_cluster_key_differs_for_different_stories():
    assert cluster_key("Bayern Munich beat Dortmund in thriller") != \
        cluster_key("Berlin floods force hundreds to evacuate")


def test_cluster_key_empty_when_no_significant_tokens():
    assert cluster_key("the a an of to") == ""
    assert cluster_key("") == ""
