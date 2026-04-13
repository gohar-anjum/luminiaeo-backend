import httpx

from app.core.config import settings
from app.core.security import validate_public_url
from app.core.http_client import get_http_client

# Many sites block minimal or missing browser headers; some omit or mislabel Content-Type.
HTML_REQUEST_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/122.0.0.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
}


def _media_type(content_type: str | None) -> str:
    if not content_type:
        return ""
    return content_type.split(";")[0].strip().lower()


def _declared_as_html(content_type: str | None) -> bool:
    mt = _media_type(content_type)
    return mt in ("text/html", "application/xhtml+xml")


def _body_looks_like_html(text: str) -> bool:
    if not text:
        return False
    sample = text.lstrip()[:4096].lower()
    if sample.startswith("<!doctype html") or sample.startswith("<html"):
        return True
    return "<html" in sample[:1500]


async def fetch_html(url: str) -> str:
    """Fetch HTML from URL using global connection pool."""
    validate_public_url(url)

    client = await get_http_client()

    try:
        response = await client.get(url, headers=HTML_REQUEST_HEADERS, follow_redirects=True)
    except httpx.TimeoutException:
        raise ValueError("Timed out while fetching URL") from None
    except httpx.RequestError as e:
        raise ValueError(f"Could not fetch URL: {e}") from None

    if response.status_code >= 400:
        raise ValueError(f"URL returned HTTP {response.status_code}")

    if len(response.content) > settings.MAX_CONTENT_SIZE_MB * 1024 * 1024:
        raise ValueError("Content too large")

    text = response.text
    if not _declared_as_html(response.headers.get("content-type")) and not _body_looks_like_html(
        text
    ):
        raise ValueError(
            "URL does not return HTML content (unexpected content-type or body)"
        )

    return text
