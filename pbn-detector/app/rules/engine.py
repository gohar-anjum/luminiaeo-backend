from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Dict, List, Optional, Any
from loguru import logger

from app.schemas import BacklinkSignal

class NetworkStats:
    def __init__(
        self,
        ip_counts: Dict[str, int],
        registrar_counts: Dict[str, int],
        velocity_data: Dict[str, List[BacklinkSignal]],
        total_peers: int
    ):
        self.ip_counts = ip_counts
        self.registrar_counts = registrar_counts
        self.velocity_data = velocity_data
        self.total_peers = total_peers

class RuleEngine:
    def __init__(self) -> None:

        self.rule_dependencies = {
            'spam_network': ['dataforseo_spam_score', 'shared_ip_network'],
            'new_domain_cluster': ['shared_registrar_network', 'domain_quality'],
            'high_risk_network': ['shared_ip_network', 'domain_quality'],
        }

    def precompute_network_stats(self, peers: List[BacklinkSignal]) -> NetworkStats:
        ip_counts = Counter(p.ip for p in peers if p.ip)
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        velocity_data: Dict[str, List[BacklinkSignal]] = {}
        for peer in peers:
            if peer.ip:
                if peer.ip not in velocity_data:
                    velocity_data[peer.ip] = []
                velocity_data[peer.ip].append(peer)
        return NetworkStats(
            ip_counts=dict(ip_counts),
            registrar_counts=dict(registrar_counts),
            velocity_data=velocity_data,
            total_peers=len(peers)
        )

    def evaluate(self, backlink: BacklinkSignal, network_stats: NetworkStats) -> Dict[str, float]:
        """Evaluate a backlink against PBN detection rules and return rule scores."""
        scores: Dict[str, float] = {}

        # Shared IP Network Rule
        if backlink.ip and network_stats.ip_counts.get(backlink.ip, 0) > 3:
            scores['shared_ip_network'] = min(0.4, network_stats.ip_counts[backlink.ip] / 20.0)

        # Shared Registrar Network Rule
        if backlink.whois_registrar and network_stats.registrar_counts.get(backlink.whois_registrar, 0) > 2:
            scores['shared_registrar_network'] = min(0.3, network_stats.registrar_counts[backlink.whois_registrar] / 15.0)

        # Domain Quality Rule
        domain_quality_score = 0.0
        if backlink.domain_rank is not None:
            if backlink.domain_rank < 20:
                domain_quality_score += 0.3
            elif backlink.domain_rank < 40:
                domain_quality_score += 0.15
        if backlink.domain_age_days is not None and backlink.domain_age_days < 180:
            domain_quality_score += 0.2
        if domain_quality_score > 0:
            scores['domain_quality'] = min(0.5, domain_quality_score)

        # DataForSEO Spam Score Rule
        if backlink.backlink_spam_score is not None:
            if backlink.backlink_spam_score >= 60:
                scores['dataforseo_spam_score'] = 0.4
            elif backlink.backlink_spam_score >= 40:
                scores['dataforseo_spam_score'] = 0.2

        # Safe Browsing Rule
        if backlink.safe_browsing_status == "flagged":
            scores['safe_browsing_flagged'] = 0.3

        # Composite Rules (combine multiple signals)
        if 'shared_ip_network' in scores and 'domain_quality' in scores:
            scores['high_risk_network'] = 0.25
        if 'dataforseo_spam_score' in scores and 'shared_ip_network' in scores:
            scores['spam_network'] = 0.3
        if 'shared_registrar_network' in scores and 'domain_quality' in scores:
            scores['new_domain_cluster'] = 0.2

        return scores


# Create a singleton instance
rule_engine = RuleEngine()