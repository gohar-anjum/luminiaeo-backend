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