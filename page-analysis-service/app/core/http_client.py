"""
Global async HTTP client for connection pool reuse.
Avoid creating httpx.AsyncClient per request.
"""
import httpx
from app.core.config import settings

_http_client: httpx.AsyncClient | None = None


async def get_http_client() -> httpx.AsyncClient:
    """Get or create the global async HTTP client."""
    global _http_client
    if _http_client is None:
        _http_client = httpx.AsyncClient(
            timeout=settings.REQUEST_TIMEOUT,
            # Smaller keep-alive pool reduces "server disconnected" from stale pooled sockets.
            limits=httpx.Limits(max_connections=100, max_keepalive_connections=10),
        )
    return _http_client


async def close_http_client() -> None:
    """Close the global HTTP client (call on shutdown)."""
    global _http_client
    if _http_client is not None:
        await _http_client.aclose()
        _http_client = None
