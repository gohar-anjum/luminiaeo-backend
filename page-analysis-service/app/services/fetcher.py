import httpx
from app.core.config import settings
from app.core.security import validate_public_url

async def fetch_html(url: str):
    validate_public_url(url)

    headers = {
        "User-Agent": "Mozilla/5.0"
    }

    async with httpx.AsyncClient(timeout=settings.REQUEST_TIMEOUT) as client:
        response = await client.get(url)

    if "text/html" not in response.headers.get("content-type", ""):
        raise ValueError("URL does not return HTML content")

    if len(response.content) > settings.MAX_CONTENT_SIZE_MB * 1024 * 1024:
        raise ValueError("Content too large")

    return response.text
