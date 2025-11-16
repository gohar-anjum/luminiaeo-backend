from __future__ import annotations

import asyncio
import pickle
import hashlib
from typing import Any, Optional

import redis.asyncio as redis

from app.config import get_settings


class CacheClient:
    def __init__(self) -> None:
        settings = get_settings()
        self._url = settings.redis_url
        self._client: Optional[redis.Redis] = None
        self._use_cache = bool(self._url)

    async def connect(self) -> None:
        if self._url and not self._client:
            try:
                self._client = redis.from_url(self._url, encoding="utf-8", decode_responses=False)
                # Test connection
                await self._client.ping()
            except Exception:
                self._client = None
                self._use_cache = False

    async def close(self) -> None:
        if self._client:
            await self._client.aclose()
            self._client = None

    async def get(self, key: str) -> Optional[str]:
        if not self._client or not self._use_cache:
            return None
        try:
            result = await self._client.get(key)
            if result:
                return result.decode('utf-8') if isinstance(result, bytes) else result
        except Exception:
            pass
        return None

    async def get_bytes(self, key: str) -> Optional[bytes]:
        """Get binary data from cache"""
        if not self._client or not self._use_cache:
            return None
        try:
            return await self._client.get(key)
        except Exception:
            return None

    async def set(self, key: str, value: Any, ttl: int = 3600) -> None:
        if not self._client or not self._use_cache:
            return
        try:
            await self._client.set(key, value, ex=ttl)
        except Exception:
            pass

    async def set_bytes(self, key: str, value: bytes, ttl: int = 3600) -> None:
        """Set binary data in cache"""
        if not self._client or not self._use_cache:
            return
        try:
            await self._client.set(key, value, ex=ttl)
        except Exception:
            pass

    async def get_pickle(self, key: str) -> Optional[Any]:
        """Get pickled object from cache"""
        if not self._client or not self._use_cache:
            return None
        try:
            data = await self._client.get(key)
            if data:
                return pickle.loads(data)
        except Exception:
            pass
        return None

    async def set_pickle(self, key: str, value: Any, ttl: int = 3600) -> None:
        """Set pickled object in cache"""
        if not self._client or not self._use_cache:
            return
        try:
            data = pickle.dumps(value)
            await self._client.set(key, data, ex=ttl)
        except Exception:
            pass

    def _hash_key(self, text: str, prefix: str = "") -> str:
        """Generate cache key from text"""
        text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
        return f"pbn:{prefix}:{text_hash}" if prefix else f"pbn:{text_hash}"


cache_client = CacheClient()


async def init_cache() -> None:
    await cache_client.connect()


async def shutdown_cache() -> None:
    await cache_client.close()

