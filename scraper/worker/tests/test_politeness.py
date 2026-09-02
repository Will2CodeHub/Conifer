from tenscraper.politeness import RateLimiter, RobotsPolicy, build_user_agent


def test_user_agent_names_publication_and_contact():
    ua = build_user_agent("The Munich Eye")
    assert "The Munich Eye" in ua
    assert "theeyenewspapers.com" in ua


def test_robots_respected_by_default(read_fixture):
    robots = read_fixture("sample_robots.txt")
    policy = RobotsPolicy(respect_robots=True)
    ua = build_user_agent("tme")
    assert policy.can_fetch(robots, "https://site.example.com/private/x", ua) is False
    assert policy.can_fetch(robots, "https://site.example.com/public/y", ua) is True


def test_robots_override_bypasses(read_fixture):
    robots = read_fixture("sample_robots.txt")
    policy = RobotsPolicy(respect_robots=False, override_reason="own site")
    ua = build_user_agent("tme")
    assert policy.can_fetch(robots, "https://site.example.com/private/x", ua) is True


class _Clock:
    def __init__(self):
        self.t = 0.0

    def __call__(self):
        return self.t


def test_rate_limiter_sleeps_only_when_interval_too_short():
    clock = _Clock()
    slept = []

    def fake_sleep(seconds):
        slept.append(seconds)
        clock.t += seconds

    rl = RateLimiter(5.0, clock=clock, sleep=fake_sleep)

    assert rl.wait("https://a.example.com/1") == 0.0   # first call, no wait
    assert rl.wait("https://a.example.com/2") == 5.0   # immediate second call waits full delay
    assert slept == [5.0]

    clock.t += 10.0                                    # plenty of time passes
    assert rl.wait("https://a.example.com/3") == 0.0   # no wait needed
    assert slept == [5.0]


def test_rate_limiter_tracks_domains_independently():
    clock = _Clock()
    slept = []
    rl = RateLimiter(5.0, clock=clock, sleep=lambda s: slept.append(s))
    rl.wait("https://a.example.com/1")
    assert rl.wait("https://b.example.com/1") == 0.0   # different domain, no wait
    assert slept == []
