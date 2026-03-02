"""
SSRF protection: block private/internal URLs and dangerous schemes.
"""
import socket
import ipaddress
from urllib.parse import urlparse


ALLOWED_SCHEMES = ("http", "https")
BLOCKED_HOSTNAMES = frozenset(
    {"localhost", "127.0.0.1", "0.0.0.0", "::1", "metadata.google.internal"}
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
        ip = socket.gethostbyname(hostname)
    except socket.gaierror:
        raise ValueError("Could not resolve hostname")

    try:
        ip_obj = ipaddress.ip_address(ip)
    except ValueError:
        raise ValueError("Invalid IP address")

    if ip_obj.is_private or ip_obj.is_loopback:
        raise ValueError("Private/internal URLs are not allowed")
