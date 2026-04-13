import httpx

from app.core.config import settings
from app.core.http_client import get_http_client
from app.core.pipeline_log import log_step
from app.core.security import validate_public_url

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
    if "<html" in sample:
        return True
    return any(tag in sample for tag in ("<head", "<body", "<article", "<main"))


async def fetch_html(url: str) -> str:
    """Fetch HTML from URL using global connection pool."""
    log_step("02_fetch_start", url=url)
    validate_public_url(url)

    client = await get_http_client()

    try:
        response = await client.get(url, headers=HTML_REQUEST_HEADERS, follow_redirects=True)
    except httpx.TimeoutException:
        log_step("02_fetch_fail", reason="timeout", url=url)
        raise ValueError("Timed out while fetching URL") from None
    except httpx.RequestError as e:
        log_step("02_fetch_fail", reason="request_error", error=str(e), url=url)
        raise ValueError(f"Could not fetch URL: {e}") from None

    ct = response.headers.get("content-type", "")
    log_step(
        "02_fetch_http",
        status=response.status_code,
        bytes=len(response.content),
        content_type=_safe_ct(ct),
        final_url=str(response.url),
    )

    if response.status_code >= 400:
        log_step("02_fetch_fail", reason="http_status", status=response.status_code)
        raise ValueError(f"URL returned HTTP {response.status_code}")

    if len(response.content) > settings.MAX_CONTENT_SIZE_MB * 1024 * 1024:
        log_step("02_fetch_fail", reason="too_large", bytes=len(response.content))
        raise ValueError("Content too large")

    text = response.text
    declared = _declared_as_html(ct)
    sniff = _body_looks_like_html(text)
    log_step(
        "02_fetch_body_check",
        declared_html=declared,
        sniff_html=sniff,
        text_chars=len(text),
    )
    if not declared and not sniff:
        log_step("02_fetch_fail", reason="not_html", content_type=_safe_ct(ct))
        raise ValueError(
            "URL does not return HTML content (unexpected content-type or body)"
        )

    log_step("02_fetch_done", text_chars=len(text))
    return text


def _safe_ct(ct: str) -> str:
    return (ct or "").split(";")[0].strip()[:80]
