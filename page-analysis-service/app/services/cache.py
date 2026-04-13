import hashlib
import json
import logging

import redis.asyncio as redis

from app.core.config import settings
from app.core.pipeline_log import log_step

logger = logging.getLogger(__name__)

redis_client = redis.from_url(settings.REDIS_URL, decode_responses=True)


def generate_cache_key(
    url: str,
    analysis: list,
    extra: str = "",
) -> str:
    """
    Cache key includes URL, sorted analysis types, and optional extra context (e.g. user keyword).
    """
    raw = json.dumps(
        {
            "url": url,
            "analysis": sorted(analysis),
            "extra": extra,
        },
        sort_keys=True,
    )
    hashed = hashlib.sha256(raw.encode()).hexdigest()
    return f"analysis:{hashed}"


async def get_cache(key: str) -> dict | None:
    try:
        cached = await redis_client.get(key)
    except redis.RedisError as e:
        logger.warning("redis get failed for %s: %s", key, e)
        log_step("01_redis_get_fail", cache_key_tail=key[-16:], error=str(e))
        return None
    if not cached:
        log_step("01_redis_miss", cache_key_tail=key[-16:])
        return None
    try:
        data = json.loads(cached)
    except json.JSONDecodeError as e:
        logger.warning("redis cache corrupt for %s: %s", key, e)
        log_step("01_redis_corrupt", cache_key_tail=key[-16:], error=str(e))
        return None
    log_step("01_redis_hit", cache_key_tail=key[-16:])
    return data


async def set_cache(key: str, value: dict) -> None:
    try:
        await redis_client.setex(key, settings.CACHE_TTL, json.dumps(value))
        log_step("10_redis_set_ok", cache_key_tail=key[-16:], ttl_s=settings.CACHE_TTL)
    except (redis.RedisError, TypeError, ValueError) as e:
        logger.warning("redis set failed for %s: %s", key, e)
        log_step("10_redis_set_fail", cache_key_tail=key[-16:], error=str(e))
