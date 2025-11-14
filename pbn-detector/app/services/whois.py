from __future__ import annotations

import asyncio
from typing import Any, Dict, Optional

import httpx
from loguru import logger

from app.config import get_settings


class WhoisService:
    def __init__(self) -> None:
        settings = get_settings()
        self.base_url = settings.whois_base_url
        self.api_key = settings.whois_api_key
        self.timeout = 15.0

    async def lookup(self, domain: str) -> Dict[str, Any]:
        if not self.api_key:
            return {}

        params = {
            "apiKey": self.api_key,
            "domainName": domain,
            "outputFormat": "JSON",
        }

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            try:
                response = await client.get(self.base_url, params=params)
                response.raise_for_status()
                return response.json()
            except httpx.HTTPError as exc:
                logger.warning("WHOIS lookup failed", domain=domain, error=str(exc))
                return {}

    def extract_signals(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        record = payload.get("WhoisRecord", {})
        registrar = record.get("registrarName")
        estimated_age = record.get("estimatedDomainAge")
        registered = record.get("dataError") != "MISSING_WHOIS_DATA"
        return {
            "registrar": registrar,
            "domain_age_days": estimated_age,
            "registered": registered,
        }


whois_service = WhoisService()

