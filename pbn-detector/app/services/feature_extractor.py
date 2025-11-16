from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import numpy as np

from app.schemas import BacklinkSignal
from app.utils.cache import cache_client


class NetworkFeatures:
    """Precomputed network-level features to avoid O(n²) complexity"""
    def __init__(
        self,
        ip_counts: Dict[str, int],
        registrar_counts: Dict[str, int],
        velocity_windows: Dict[str, List[BacklinkSignal]],
        total_peers: int
    ):
        self.ip_counts = ip_counts
        self.registrar_counts = registrar_counts
        self.velocity_windows = velocity_windows
        self.total_peers = total_peers


class FeatureExtractor:
    def __init__(self) -> None:
        self.now = datetime.now(timezone.utc)
        self._regex_cache: Dict[str, Any] = {}

    def precompute_network_features(self, peers: List[BacklinkSignal]) -> NetworkFeatures:
        """
        Precompute network-level features once for all backlinks.
        Complexity: O(n) instead of O(n²) when called per backlink.
        
        Returns:
            NetworkFeatures object with precomputed counts and velocity windows
        """
        # Precompute IP and registrar counts - O(n)
        ip_counts = Counter(p.ip for p in peers if p.ip)
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        
        # Precompute velocity windows - O(n)
        velocity_windows = {
            '7_days': [],
            '30_days': [],
            '90_days': []
        }
        
        for p in peers:
            if p.first_seen:
                first_seen = p.first_seen
                if first_seen.tzinfo is None:
                    first_seen = first_seen.replace(tzinfo=timezone.utc)
                delta_days = (self.now - first_seen).days
                
                if delta_days <= 7:
                    velocity_windows['7_days'].append(p)
                if delta_days <= 30:
                    velocity_windows['30_days'].append(p)
                if delta_days <= 90:
                    velocity_windows['90_days'].append(p)
        
        return NetworkFeatures(
            ip_counts=ip_counts,
            registrar_counts=registrar_counts,
            velocity_windows=velocity_windows,
            total_peers=len(peers)
        )

    def build_feature_vector(
        self, 
        backlink: BacklinkSignal, 
        peers_or_features: List[BacklinkSignal] | NetworkFeatures
    ) -> np.ndarray:
        """
        Build feature vector for a backlink.
        
        Args:
            backlink: The backlink to extract features for
            peers_or_features: Either list of peers (legacy) or precomputed NetworkFeatures (optimized)
        
        Returns:
            numpy array of 11 features
        """
        anchor_length = len(backlink.anchor or "")
        money_anchor = self._money_anchor_score(backlink.anchor)
        domain_rank = backlink.domain_rank or 0
        dofollow = 1 if backlink.dofollow else 0
        domain_age = backlink.domain_age_days or 0
        
        # Use precomputed features if available, otherwise compute (backward compatibility)
        if isinstance(peers_or_features, NetworkFeatures):
            network_features = peers_or_features
            ip_reuse = network_features.ip_counts.get(backlink.ip, 0) / max(network_features.total_peers, 1)
            registrar_reuse = network_features.registrar_counts.get(backlink.whois_registrar, 0) / max(network_features.total_peers, 1)
            link_velocity = self._link_velocity_from_cache(backlink, network_features.velocity_windows, network_features.total_peers)
        else:
            # Legacy mode: compute on the fly (O(n) per backlink)
            peers = peers_or_features
            ip_reuse = self._ip_reuse_ratio(backlink.ip, peers)
            registrar_reuse = self._registrar_reuse_ratio(backlink.whois_registrar, peers)
            link_velocity = self._link_velocity(backlink, peers)
        
        # Additional advanced features
        domain_name_suspicious = self._domain_name_pattern_score(backlink.domain_from)
        
        # Hosting pattern uses IP reuse, which is already computed above
        if isinstance(peers_or_features, NetworkFeatures):
            hosting_pattern = ip_reuse  # Same as IP reuse ratio
        else:
            hosting_pattern = self._hosting_provider_pattern(backlink, peers_or_features)
        
        spam_score_normalized = self._normalize_spam_score(backlink.backlink_spam_score)

        return np.array([
            anchor_length, money_anchor, domain_rank, dofollow, domain_age,
            ip_reuse, registrar_reuse, link_velocity, domain_name_suspicious,
            hosting_pattern, spam_score_normalized,
        ], dtype=float)
    
    async def _domain_name_pattern_score_async(self, domain: str | None) -> float:
        """Detect suspicious domain name patterns with Redis caching (async)"""
        if not domain:
            return 0.0
        
        # Try Redis cache first
        cache_key = cache_client._hash_key(domain, "domain_pattern")
        cached_score = await cache_client.get(cache_key)
        if cached_score:
            return float(cached_score)
        
        # Compute score
        score = await self._compute_domain_pattern_score(domain)
        
        # Cache in Redis
        await cache_client.set(cache_key, str(score), ttl=86400)  # 24 hours
        
        return score

    def _compute_domain_pattern_score(self, domain: str) -> float:
        """Compute domain pattern score (internal method)"""
        domain_lower = domain.lower()
        score = 0.0
        
        # Random string patterns (e.g., "abc123xyz", "random1234")
        import re
        pattern_key = 'domain_pattern'
        if pattern_key not in self._regex_cache:
            self._regex_cache[pattern_key] = re.compile(r'[a-z]{3,}\d{3,}')
        
        if self._regex_cache[pattern_key].search(domain_lower):
            score += 0.4

    def _domain_name_pattern_score(self, domain: str | None) -> float:
        """Detect suspicious domain name patterns (random strings, numbers)."""
        if not domain:
            return 0.0
        
        domain_lower = domain.lower()
        score = 0.0
        
        # Random string patterns (e.g., "abc123xyz", "random1234")
        # Cache compiled regex for performance
        import re
        pattern_key = 'domain_pattern'
        if pattern_key not in self._regex_cache:
            self._regex_cache[pattern_key] = re.compile(r'[a-z]{3,}\d{3,}')
        
        if self._regex_cache[pattern_key].search(domain_lower):
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
        """Detect money/commercial anchor text patterns - optimized with sets."""
        if not anchor:
            return 0.0
        
        anchor_lower = anchor.lower()
        
        # Use sets for O(1) membership testing instead of O(n) iteration
        high_risk_keywords = {"casino", "poker", "adult", "viagra", "cialis", "loan", "debt", "forex", "crypto", "bitcoin"}
        medium_risk_keywords = {"buy", "cheap", "discount", "free", "click here", "visit now", "order now"}
        
        # Split anchor into words and check set intersection - O(min(len(anchor_words), len(keywords)))
        anchor_words = set(anchor_lower.split())
        
        if anchor_words & high_risk_keywords:  # Set intersection
            return 1.0
        if anchor_words & medium_risk_keywords:
            return 0.6
        
        # Pattern checks (keep as-is for substring matching)
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

    def _link_velocity_from_cache(
        self, 
        backlink: BacklinkSignal, 
        velocity_windows: Dict[str, List[BacklinkSignal]],
        total_peers: int
    ) -> float:
        """Calculate link velocity using precomputed windows - O(1) instead of O(n)"""
        if not backlink.first_seen or total_peers == 0:
            return 0.0
        
        # Check which windows this backlink belongs to
        first_seen = backlink.first_seen
        if first_seen.tzinfo is None:
            first_seen = first_seen.replace(tzinfo=timezone.utc)
        delta_days = (self.now - first_seen).days
        
        # Count backlinks in same windows
        recent_7 = len([p for p in velocity_windows['7_days'] if delta_days <= 7])
        recent_30 = len([p for p in velocity_windows['30_days'] if delta_days <= 30])
        recent_90 = len([p for p in velocity_windows['90_days'] if delta_days <= 90])
        
        velocities = [
            recent_7 / max(total_peers, 1),
            recent_30 / max(total_peers, 1),
            recent_90 / max(total_peers, 1)
        ]
        
        return velocities[0] * 0.5 + velocities[1] * 0.3 + velocities[2] * 0.2
    
    def _link_velocity(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        """Legacy method - computes velocity on the fly (O(n))"""
        if not backlink.first_seen or not peers:
            return 0.0
        
        windows = {'7_days': 7, '30_days': 30, '90_days': 90}
        velocities = []
        for days in windows.values():
            recent = []
            for p in peers:
                if p.first_seen:
                    first_seen = p.first_seen
                    if first_seen.tzinfo is None:
                        first_seen = first_seen.replace(tzinfo=timezone.utc)
                    delta_days = (self.now - first_seen).days
                    if delta_days <= days:
                        recent.append(p)
            velocities.append(len(recent) / max(len(peers), 1))
        
        return velocities[0] * 0.5 + velocities[1] * 0.3 + velocities[2] * 0.2
    
    def _normalize_spam_score(self, spam_score: int | None) -> float:
        if spam_score is None:
            return 0.5
        return min(max(spam_score / 100.0, 0.0), 1.0)


feature_extractor = FeatureExtractor()

