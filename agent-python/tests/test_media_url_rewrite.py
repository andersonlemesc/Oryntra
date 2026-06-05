from oryntra_agent.agent.media import rewrite_localhost_url


def test_rewrites_with_port() -> None:
    assert (
        rewrite_localhost_url(
            "http://localhost:3000/foo/bar",
            "http://host.docker.internal:3000",
        )
        == "http://host.docker.internal:3000/foo/bar"
    )


def test_preserves_query() -> None:
    assert rewrite_localhost_url(
        "http://localhost:3000/foo?a=1&b=2",
        "http://internal:3000",
    ).endswith("/foo?a=1&b=2")


def test_no_rewrite_for_real_host() -> None:
    url = "https://chatwoot.example.com/x"
    assert rewrite_localhost_url(url, "http://internal:3000") == url


def test_no_rewrite_when_base_missing() -> None:
    url = "http://localhost:3000/x"
    assert rewrite_localhost_url(url, None) == url
    assert rewrite_localhost_url(url, "") == url


def test_case_insensitive_localhost() -> None:
    assert (
        rewrite_localhost_url("http://LocalHost:3000/foo", "http://internal:3000")
        == "http://internal:3000/foo"
    )


def test_strips_trailing_slash_from_base() -> None:
    assert (
        rewrite_localhost_url("http://localhost:3000/foo", "http://internal:3000/")
        == "http://internal:3000/foo"
    )
