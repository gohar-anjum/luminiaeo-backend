"""
SSRF protection: block private/internal URLs and dangerous schemes.
"""
import ipaddress
import socket
from urllib.parse import urlparse

from app.core.pipeline_log import log_step


def _safe_url(url: str) -> str:
    return url[:120] + ("…" if len(url) > 120 else "")


ALLOWED_SCHEMES = ("http", "https")
BLOCKED_HOSTNAMES = frozenset(
    {
        "localhost",
        "127.0.0.1",
        "0.0.0.0",
        "::1",
        "metadata.google.internal",
    }
)


def _ip_not_allowed(ip_obj: ipaddress.IPv4Address | ipaddress.IPv6Address) -> bool:
    return bool(
        ip_obj.is_private
        or ip_obj.is_loopback
        or ip_obj.is_link_local
        or ip_obj.is_multicast
        or ip_obj.is_reserved
    )


def validate_public_url(url: str) -> None:
    """
    Validate URL is safe for outbound fetch (SSRF protection).
    Raises ValueError if URL is private, internal, or uses blocked scheme.
    """
    parsed = urlparse(url)

    if parsed.scheme.lower() not in ALLOWED_SCHEMES:
        log_step("ssrf_fail", reason="bad_scheme", scheme=parsed.scheme)
        raise ValueError("Only HTTP and HTTPS URLs are allowed")

    hostname = parsed.hostname
    if not hostname:
        log_step("ssrf_fail", reason="no_hostname", url=_safe_url(url))
        raise ValueError("Invalid URL: no hostname")

    hostname_lower = hostname.lower()
    if hostname_lower in BLOCKED_HOSTNAMES:
        log_step("ssrf_fail", reason="blocked_hostname", host=hostname_lower)
        raise ValueError("Private/internal URLs are not allowed")

    try:
        infos = socket.getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
    except socket.gaierror:
        log_step("ssrf_fail", reason="dns_resolution", host=hostname_lower)
        raise ValueError("Could not resolve hostname") from None

    unique_ips: set[str] = set()
    for _family, _type, _proto, _canon, sockaddr in infos:
        unique_ips.add(sockaddr[0])

    if not unique_ips:
        log_step("ssrf_fail", reason="no_addresses", host=hostname_lower)
        raise ValueError("Could not resolve hostname")

    for ip_str in unique_ips:
        try:
            ip_obj = ipaddress.ip_address(ip_str)
        except ValueError:
            log_step("ssrf_fail", reason="invalid_ip", ip=ip_str)
            raise ValueError("Invalid IP address") from None
        if _ip_not_allowed(ip_obj):
            log_step("ssrf_fail", reason="disallowed_ip", ip=ip_str)
            raise ValueError("Private/internal URLs are not allowed")

    log_step(
        "ssrf_ok",
        host=hostname_lower,
        resolved_ip_count=len(unique_ips),
    )
