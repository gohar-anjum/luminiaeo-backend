from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import numpy as np

from app.schemas import BacklinkSignal
from app.utils.cache import cache_client

class NetworkFeatures:
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
        ip_counts = Counter(p.ip for p in peers if p.ip)
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        velocity_windows: Dict[str, List[BacklinkSignal]] = {}
        for peer in peers:
            if peer.ip:
                if peer.ip not in velocity_windows:
                    velocity_windows[peer.ip] = []
                velocity_windows[peer.ip].append(peer)
        return NetworkFeatures(
            ip_counts=dict(ip_counts),
            registrar_counts=dict(registrar_counts),
            velocity_windows=velocity_windows,
            total_peers=len(peers)
        )

    def build_feature_vector(self, backlink: BacklinkSignal, network_features: NetworkFeatures) -> np.ndarray:
        """Extract 11 features from a backlink for classification."""
        features = []

        # 1. Anchor Length
        anchor_text = backlink.anchor_text or ""
        features.append(len(anchor_text))

        # 2. Money Anchor Score (commercial/spam keywords)
        money_keywords = {'buy', 'cheap', 'discount', 'deal', 'sale', 'price', 'cost', 'affordable'}
        anchor_lower = anchor_text.lower()
        money_score = sum(1 for kw in money_keywords if kw in anchor_lower) / max(len(anchor_text.split()), 1)
        features.append(min(money_score, 1.0))

        # 3. Domain Rank (normalized 0-1, higher rank = lower value)
        domain_rank = backlink.domain_rank if backlink.domain_rank is not None else 50
        features.append(1.0 - (domain_rank / 100.0))

        # 4. Dofollow (1 if dofollow, 0 if nofollow)
        features.append(1.0 if backlink.dofollow else 0.0)

        # 5. Domain Age (normalized)
        domain_age = backlink.domain_age_days if backlink.domain_age_days is not None else 365
        features.append(min(domain_age / 3650.0, 1.0))  # Normalize to 10 years max

        # 6. IP Reuse Ratio
        ip = backlink.ip or ""
        ip_count = network_features.ip_counts.get(ip, 0)
        features.append(ip_count / max(network_features.total_peers, 1))

        # 7. Registrar Reuse Ratio
        registrar = backlink.whois_registrar or ""
        registrar_count = network_features.registrar_counts.get(registrar, 0)
        features.append(registrar_count / max(network_features.total_peers, 1))

        # 8. Link Velocity (temporal clustering)
        velocity = 0.0
        if ip and ip in network_features.velocity_windows:
            velocity_peers = network_features.velocity_windows[ip]
            if len(velocity_peers) > 1:
                # Simple velocity: number of peers on same IP
                velocity = min(len(velocity_peers) / 10.0, 1.0)
        features.append(velocity)

        # 9. Domain Name Pattern (suspicious patterns)
        source_url = backlink.source_url or ""
        domain = source_url.split('/')[2] if '/' in source_url else source_url
        suspicious_score = 0.0
        if domain:
            # Check for random strings, excessive numbers
            if sum(c.isdigit() for c in domain) > len(domain) * 0.3:
                suspicious_score += 0.3
            if len(domain.split('.')) > 3:  # Too many subdomains
                suspicious_score += 0.2
            if any(len(part) > 20 for part in domain.split('.')):  # Very long parts
                suspicious_score += 0.2
        features.append(min(suspicious_score, 1.0))

        # 10. Hosting Pattern (same as IP reuse for simplicity)
        features.append(ip_count / max(network_features.total_peers, 1))

        # 11. Spam Score Normalized (0-100 → 0-1)
        spam_score = backlink.backlink_spam_score if backlink.backlink_spam_score is not None else 0
        features.append(spam_score / 100.0)

        return np.array(features, dtype=np.float32)


# Create a singleton instance
feature_extractor = FeatureExtractor()