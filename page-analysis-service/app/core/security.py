import socket
import ipaddress
from urllib.parse import urlparse

def validate_public_url(url: str):
    parsed = urlparse(url)
    hostname = parsed.hostname

    ip = socket.gethostbyname(hostname)
    ip_obj = ipaddress.ip_address(ip)

    if ip_obj.is_private or ip_obj.is_loopback:
        raise ValueError("Private/internal URLs are not allowed")
