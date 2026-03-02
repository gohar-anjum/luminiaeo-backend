from app.core.config import settings
from app.core.security import validate_public_url
from app.core.http_client import get_http_client


async def fetch_html(url: str) -> str:
    """Fetch HTML from URL using global connection pool."""
    validate_public_url(url)

    headers = {"User-Agent": "Mozilla/5.0"}
    client = await get_http_client()

    response = await client.get(url, headers=headers)

    if "text/html" not in response.headers.get("content-type", ""):
        raise ValueError("URL does not return HTML content")

    if len(response.content) > settings.MAX_CONTENT_SIZE_MB * 1024 * 1024:
        raise ValueError("Content too large")

    return response.text
