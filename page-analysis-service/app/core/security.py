"""
SSRF protection: block private/internal URLs and dangerous schemes.
"""
import ipaddress
import socket
from urllib.parse import urlparse


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
        raise ValueError("Only HTTP and HTTPS URLs are allowed")

    hostname = parsed.hostname
    if not hostname:
        raise ValueError("Invalid URL: no hostname")

    hostname_lower = hostname.lower()
    if hostname_lower in BLOCKED_HOSTNAMES:
        raise ValueError("Private/internal URLs are not allowed")

    try:
        infos = socket.getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
    except socket.gaierror:
        raise ValueError("Could not resolve hostname") from None

    unique_ips: set[str] = set()
    for _family, _type, _proto, _canon, sockaddr in infos:
        unique_ips.add(sockaddr[0])

    if not unique_ips:
        raise ValueError("Could not resolve hostname")

    for ip_str in unique_ips:
        try:
            ip_obj = ipaddress.ip_address(ip_str)
        except ValueError:
            raise ValueError("Invalid IP address") from None
        if _ip_not_allowed(ip_obj):
            raise ValueError("Private/internal URLs are not allowed")
