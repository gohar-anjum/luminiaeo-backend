from __future__ import annotations

from datetime import datetime
from typing import Any, Dict, List

import numpy as np

from app.schemas import BacklinkSignal


class FeatureExtractor:
    def __init__(self) -> None:
        self.now = datetime.utcnow()

    def build_feature_vector(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> np.ndarray:
        anchor_length = len(backlink.anchor or "")
        money_anchor = self._money_anchor_score(backlink.anchor)
        domain_rank = backlink.domain_rank or 0
        dofollow = 1 if backlink.dofollow else 0
        domain_age = backlink.domain_age_days or 0
        ip_reuse = self._ip_reuse_ratio(backlink.ip, peers)
        registrar_reuse = self._registrar_reuse_ratio(backlink.whois_registrar, peers)
        link_velocity = self._link_velocity(backlink, peers)

        return np.array(
            [
                anchor_length,
                money_anchor,
                domain_rank,
                dofollow,
                domain_age,
                ip_reuse,
                registrar_reuse,
                link_velocity,
            ],
            dtype=float,
        )

    def _money_anchor_score(self, anchor: str | None) -> float:
        if not anchor:
            return 0.0
        keywords = ["buy", "cheap", "discount", "casino", "loan", "adult"]
        anchor_lower = anchor.lower()
        return 1.0 if any(word in anchor_lower for word in keywords) else 0.0

    def _ip_reuse_ratio(self, ip: str | None, peers: List[BacklinkSignal]) -> float:
        if not ip:
            return 0.0
        total = sum(1 for p in peers if p.ip == ip)
        return total / max(len(peers), 1)

    def _registrar_reuse_ratio(self, registrar: str | None, peers: List[BacklinkSignal]) -> float:
        if not registrar:
            return 0.0
        total = sum(1 for p in peers if p.whois_registrar == registrar)
        return total / max(len(peers), 1)

    def _link_velocity(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        if not backlink.first_seen:
            return 0.0
        recent_links = [
            p for p in peers if p.first_seen and (self.now - p.first_seen).days <= 30
        ]
        return len(recent_links) / max(len(peers), 1)


feature_extractor = FeatureExtractor()

