"""SSRF-aware HTTP fetch helper for downloading untrusted media / documents.

Attachment and document URLs originate from Chatwoot payloads relayed by
Laravel. They are not fully trusted, so every hop is validated before the
request is made:

- scheme must be http/https
- the resolved IP must not be link-local (blocks cloud metadata at
  169.254.169.254 and fe80::/10), multicast, reserved or unspecified

Private and loopback ranges are intentionally allowed: Oryntra reaches MinIO and
Chatwoot over the internal Docker network. Redirects are followed manually so
each hop is re-validated (Chatwoot serves media through redirect endpoints), and
the body is streamed so the size cap aborts the transfer early instead of after
buffering the whole response.

Residual risk: a DNS-rebinding attacker could resolve a host to a safe IP at
validation time and a blocked IP at connect time. Pinning the resolved IP would
close this; it is left as defense-in-depth given these URLs come from Chatwoot.
"""

from __future__ import annotations

import ipaddress
import socket
from urllib.parse import urlparse

import httpx


class UnsafeUrlError(ValueError):
    """Raised when a URL targets a disallowed scheme or network address."""


def _is_blocked_ip(ip: ipaddress.IPv4Address | ipaddress.IPv6Address) -> bool:
    return ip.is_link_local or ip.is_multicast or ip.is_reserved or ip.is_unspecified


def _assert_host_allowed(host: str) -> None:
    if not host:
        raise UnsafeUrlError("missing host")

    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror as exc:
        raise UnsafeUrlError(f"cannot resolve host: {host}") from exc

    for info in infos:
        addr = str(info[4][0]).split("%")[0]
        try:
            ip = ipaddress.ip_address(addr)
        except ValueError:
            continue
        if _is_blocked_ip(ip):
            raise UnsafeUrlError(f"blocked address {ip} for host {host}")


async def safe_get(
    url: str,
    *,
    timeout_seconds: float,
    max_bytes: int,
    max_redirects: int = 5,
) -> bytes:
    """Fetch ``url`` enforcing the SSRF policy and ``max_bytes`` size cap.

    Raises ``UnsafeUrlError`` for blocked targets / oversized bodies and
    ``httpx.HTTPError`` for transport or status failures.
    """
    current = httpx.URL(url)

    async with httpx.AsyncClient(timeout=timeout_seconds, follow_redirects=False) as client:
        for _ in range(max_redirects + 1):
            parsed = urlparse(str(current))
            if parsed.scheme not in ("http", "https"):
                raise UnsafeUrlError(f"blocked scheme: {parsed.scheme or '(none)'}")
            _assert_host_allowed(parsed.hostname or "")

            async with client.stream("GET", current) as response:
                if response.is_redirect:
                    location = response.headers.get("location")
                    if not location:
                        response.raise_for_status()
                        raise UnsafeUrlError("redirect without location")
                    current = response.url.join(location)
                    continue

                response.raise_for_status()

                total = 0
                chunks: list[bytes] = []
                async for chunk in response.aiter_bytes():
                    total += len(chunk)
                    if total > max_bytes:
                        raise UnsafeUrlError("response exceeds maximum size")
                    chunks.append(chunk)
                return b"".join(chunks)

    raise UnsafeUrlError("too many redirects")


__all__ = ["UnsafeUrlError", "safe_get"]
