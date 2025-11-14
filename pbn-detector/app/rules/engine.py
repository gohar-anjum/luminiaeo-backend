from __future__ import annotations

from collections import Counter
from typing import Dict, List

from app.schemas import BacklinkSignal


class RuleEngine:
    def evaluate(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> Dict[str, float]:
        scores: Dict[str, float] = {}

        if self._shared_ip(backlink, peers):
            scores["shared_ip_network"] = 0.2

        if self._shared_registrar(backlink, peers):
            scores["shared_registrar_network"] = 0.2

        if self._money_anchor(backlink):
            scores["money_anchor"] = 0.25

        if self._rapid_link_velocity(peers):
            scores["velocity_spike"] = 0.15

        return scores

    def _shared_ip(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> bool:
        if not backlink.ip:
            return False
        ip_counts = Counter(p.ip for p in peers if p.ip)
        return ip_counts.get(backlink.ip, 0) >= 5

    def _shared_registrar(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> bool:
        if not backlink.whois_registrar:
            return False
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        return registrar_counts.get(backlink.whois_registrar, 0) >= 5

    def _money_anchor(self, backlink: BacklinkSignal) -> bool:
        return bool(backlink.anchor and any(word in backlink.anchor.lower() for word in ["buy", "cheap", "casino"]))

    def _rapid_link_velocity(self, peers: List[BacklinkSignal]) -> bool:
        if not peers or not peers[0].first_seen:
            return False
        baseline = peers[0].first_seen
        recent = [p for p in peers if p.first_seen and (p.first_seen - baseline).days <= 7]
        return len(recent) >= len(peers) * 0.5


rule_engine = RuleEngine()

