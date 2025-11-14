from __future__ import annotations

import asyncio
from typing import Any, Optional

import redis.asyncio as redis

from app.config import get_settings


class CacheClient:
    def __init__(self) -> None:
        settings = get_settings()
        self._url = settings.redis_url
        self._client: Optional[redis.Redis] = None

    async def connect(self) -> None:
        if self._url and not self._client:
            self._client = redis.from_url(self._url, encoding="utf-8", decode_responses=True)

    async def close(self) -> None:
        if self._client:
            await self._client.aclose()
            self._client = None

    async def get(self, key: str) -> Optional[str]:
        if not self._client:
            return None
        return await self._client.get(key)

    async def set(self, key: str, value: Any, ttl: int = 3600) -> None:
        if not self._client:
            return
        await self._client.set(key, value, ex=ttl)


cache_client = CacheClient()


async def init_cache() -> None:
    await cache_client.connect()


async def shutdown_cache() -> None:
    await cache_client.close()

