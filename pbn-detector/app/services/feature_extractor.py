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
        
        # Additional advanced features
        domain_name_suspicious = self._domain_name_pattern_score(backlink.domain_from)
        hosting_pattern = self._hosting_provider_pattern(backlink, peers)
        
        spam_score_normalized = self._normalize_spam_score(backlink.backlink_spam_score)

        return np.array([
            anchor_length, money_anchor, domain_rank, dofollow, domain_age,
            ip_reuse, registrar_reuse, link_velocity, domain_name_suspicious,
            hosting_pattern, spam_score_normalized,
        ], dtype=float)
    
    def _domain_name_pattern_score(self, domain: str | None) -> float:
        """Detect suspicious domain name patterns (random strings, numbers)."""
        if not domain:
            return 0.0
        
        domain_lower = domain.lower()
        score = 0.0
        
        # Random string patterns (e.g., "abc123xyz", "random1234")
        import re
        if re.search(r'[a-z]{3,}\d{3,}', domain_lower):
            score += 0.4
        
        # Excessive numbers
        digit_ratio = sum(c.isdigit() for c in domain_lower) / max(len(domain_lower), 1)
        if digit_ratio > 0.3:
            score += 0.3
        
        # Very short domains (< 6 chars) or very long (> 30 chars)
        if len(domain_lower) < 6 or len(domain_lower) > 30:
            score += 0.2
        
        # Hyphen patterns (common in PBNs)
        if domain_lower.count('-') > 2:
            score += 0.2
        
        return min(score, 1.0)
    
    def _hosting_provider_pattern(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        """Detect hosting provider clustering patterns."""
        # Use IP clustering as proxy for hosting provider clustering
        # If we had hosting_provider field, we'd check that too
        return self._ip_reuse_ratio(backlink.ip, peers)

    def _money_anchor_score(self, anchor: str | None) -> float:
        if not anchor:
            return 0.0
        
        anchor_lower = anchor.lower()
        high_risk_keywords = ["casino", "poker", "adult", "viagra", "cialis", "loan", "debt", "forex", "crypto", "bitcoin"]
        medium_risk_keywords = ["buy", "cheap", "discount", "free", "click here", "visit now", "order now"]
        
        if any(word in anchor_lower for word in high_risk_keywords):
            return 1.0
        if any(word in anchor_lower for word in medium_risk_keywords):
            return 0.6
        if any(pattern in anchor_lower for pattern in ["!!!", "$$$", "www.", "http"]):
            return 0.4
        if len(anchor) > 5 and anchor.isupper():
            return 0.3
        return 0.0

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
        if not backlink.first_seen or not peers:
            return 0.0
        
        windows = {'7_days': 7, '30_days': 30, '90_days': 90}
        velocities = []
        for days in windows.values():
            recent = [p for p in peers if p.first_seen and (self.now - p.first_seen).days <= days]
            velocities.append(len(recent) / max(len(peers), 1))
        
        return velocities[0] * 0.5 + velocities[1] * 0.3 + velocities[2] * 0.2
    
    def _normalize_spam_score(self, spam_score: int | None) -> float:
        if spam_score is None:
            return 0.5
        return min(max(spam_score / 100.0, 0.0), 1.0)


feature_extractor = FeatureExtractor()

