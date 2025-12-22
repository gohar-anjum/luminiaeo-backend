from __future__ import annotations

from typing import Any, Dict, Optional

import httpx
from loguru import logger

from app.config import get_settings

class IpInfoService:
    def __init__(self) -> None:
        settings = get_settings()
        self.token = settings.ipinfo_token
        self.base_url = "https://ipinfo.io"
        self.timeout = 10.0

    async def lookup(self, ip_address: str) -> Dict[str, Any]:
        if not ip_address:
            return {}

        params = {}
        headers = {}
        if self.token:
            params["token"] = self.token

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            try:
                response = await client.get(f"{self.base_url}/{ip_address}/json", params=params, headers=headers)
                response.raise_for_status()
                data = response.json()
                return {
                    "asn": data.get("org"),
                    "country": data.get("country"),
                    "city": data.get("city"),
                    "hostname": data.get("hostname"),
                    "loc": data.get("loc"),
                    "raw": data,
                }
            except httpx.HTTPError as exc:
                logger.warning("IP lookup failed", ip=ip_address, error=str(exc))
                return {}

ipinfo_service = IpInfoService()
