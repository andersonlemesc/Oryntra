import socket

import pytest

from oryntra_agent.agent import net
from oryntra_agent.agent.net import UnsafeUrlError, safe_get


def _patch_resolve(monkeypatch: pytest.MonkeyPatch, ip: str = "93.184.216.34") -> None:
    def fake_getaddrinfo(host: str, *args: object, **kwargs: object) -> list:
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 0))]

    monkeypatch.setattr(net.socket, "getaddrinfo", fake_getaddrinfo)


async def test_blocks_link_local_metadata_address() -> None:
    with pytest.raises(UnsafeUrlError):
        await safe_get("http://169.254.169.254/latest/meta-data", timeout_seconds=5, max_bytes=1000)


async def test_blocks_non_http_scheme() -> None:
    with pytest.raises(UnsafeUrlError):
        await safe_get("file:///etc/passwd", timeout_seconds=5, max_bytes=1000)


async def test_downloads_from_allowed_host(httpx_mock, monkeypatch: pytest.MonkeyPatch) -> None:
    _patch_resolve(monkeypatch)
    httpx_mock.add_response(url="http://example.com/file", content=b"hello")

    data = await safe_get("http://example.com/file", timeout_seconds=5, max_bytes=1000)

    assert data == b"hello"


async def test_enforces_size_cap(httpx_mock, monkeypatch: pytest.MonkeyPatch) -> None:
    _patch_resolve(monkeypatch)
    httpx_mock.add_response(url="http://example.com/big", content=b"x" * 100)

    with pytest.raises(UnsafeUrlError):
        await safe_get("http://example.com/big", timeout_seconds=5, max_bytes=10)


async def test_follows_and_revalidates_redirect(
    httpx_mock, monkeypatch: pytest.MonkeyPatch
) -> None:
    _patch_resolve(monkeypatch)
    httpx_mock.add_response(
        url="http://example.com/a",
        status_code=302,
        headers={"location": "http://example.com/b"},
    )
    httpx_mock.add_response(url="http://example.com/b", content=b"final")

    data = await safe_get("http://example.com/a", timeout_seconds=5, max_bytes=1000)

    assert data == b"final"


async def test_blocked_redirect_target_is_rejected(
    httpx_mock, monkeypatch: pytest.MonkeyPatch
) -> None:
    # First hop resolves to a safe public IP; the redirect points at the
    # cloud metadata address, which must be rejected on re-validation.
    def fake_getaddrinfo(host: str, *args: object, **kwargs: object) -> list:
        ip = "93.184.216.34" if host == "example.com" else host
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 0))]

    monkeypatch.setattr(net.socket, "getaddrinfo", fake_getaddrinfo)
    httpx_mock.add_response(
        url="http://example.com/a",
        status_code=302,
        headers={"location": "http://169.254.169.254/latest/meta-data"},
    )

    with pytest.raises(UnsafeUrlError):
        await safe_get("http://example.com/a", timeout_seconds=5, max_bytes=1000)
