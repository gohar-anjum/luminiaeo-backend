import hashlib
import json
import redis.asyncio as redis
from app.core.config import settings

redis_client = redis.from_url(settings.REDIS_URL, decode_responses=True)


def generate_cache_key(
    url: str,
    analysis: list,
) -> str:
    """
    Cache key includes URL and sorted analysis types.
    Uses SHA256 signature for consistency.
    """
    raw = json.dumps(
        {
            "url": url,
            "analysis": sorted(analysis),
        },
        sort_keys=True,
    )
    hashed = hashlib.sha256(raw.encode()).hexdigest()
    return f"analysis:{hashed}"


async def get_cache(key: str) -> dict | None:
    cached = await redis_client.get(key)
    if cached:
        return json.loads(cached)
    return None


async def set_cache(key: str, value: dict) -> None:
    await redis_client.setex(key, settings.CACHE_TTL, json.dumps(value))
