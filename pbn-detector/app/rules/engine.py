from __future__ import annotations

from collections import Counter
from typing import Dict, List

from app.schemas import BacklinkSignal


class RuleEngine:
    def evaluate(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> Dict[str, float]:
        scores: Dict[str, float] = {}

        ip_cluster_score = self._shared_ip_score(backlink, peers)
        if ip_cluster_score > 0:
            scores["shared_ip_network"] = ip_cluster_score

        registrar_cluster_score = self._shared_registrar_score(backlink, peers)
        if registrar_cluster_score > 0:
            scores["shared_registrar_network"] = registrar_cluster_score

        anchor_score = self._anchor_quality_score(backlink)
        if anchor_score > 0:
            scores["anchor_quality"] = anchor_score

        velocity_score = self._link_velocity_score(peers)
        if velocity_score > 0:
            scores["velocity_spike"] = velocity_score
        
        domain_score = self._domain_quality_score(backlink)
        if domain_score > 0:
            scores["domain_quality"] = domain_score
        
        composite_score = self._composite_risk_score(backlink, peers)
        if composite_score > 0:
            scores["composite_risk"] = composite_score
        
        spam_score_rule = self._spam_score_rule(backlink)
        if spam_score_rule > 0:
            scores["dataforseo_spam_score"] = spam_score_rule

        return scores

    def _shared_ip_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        if not backlink.ip:
            return 0.0
        ip_counts = Counter(p.ip for p in peers if p.ip)
        count = ip_counts.get(backlink.ip, 0)
        total = len(peers)
        
        if count >= 10 and count / total >= 0.4:
            return 0.3
        elif count >= 5 and count / total >= 0.2:
            return 0.2
        elif count >= 3:
            return 0.1
        return 0.0

    def _shared_registrar_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        if not backlink.whois_registrar:
            return 0.0
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        count = registrar_counts.get(backlink.whois_registrar, 0)
        total = len(peers)
        
        if count >= 10 and count / total >= 0.4:
            return 0.25
        elif count >= 5 and count / total >= 0.2:
            return 0.15
        elif count >= 3:
            return 0.1
        return 0.0

    def _anchor_quality_score(self, backlink: BacklinkSignal) -> float:
        if not backlink.anchor:
            return 0.0
        
        anchor_lower = backlink.anchor.lower()
        high_risk = ["casino", "poker", "adult", "viagra", "cialis", "loan", "debt"]
        if any(word in anchor_lower for word in high_risk):
            return 0.3
        elif any(word in anchor_lower for word in ["buy", "cheap", "discount", "free"]):
            return 0.2
        elif any(pattern in anchor_lower for pattern in ["!!!", "$$$", "click here"]):
            return 0.15
        return 0.0

    def _link_velocity_score(self, peers: List[BacklinkSignal]) -> float:
        if not peers or not peers[0].first_seen:
            return 0.0
        
        from datetime import datetime
        now = datetime.utcnow()
        windows = [(7, 0.2), (30, 0.15), (90, 0.1)]
        
        max_score = 0.0
        for days, base_score in windows:
            recent = [p for p in peers if p.first_seen and (now - p.first_seen).days <= days]
            if len(recent) / max(len(peers), 1) >= 0.5:
                max_score = max(max_score, base_score)
        
        return max_score
    
    def _domain_quality_score(self, backlink: BacklinkSignal) -> float:
        score = 0.0
        
        if backlink.domain_rank and backlink.domain_rank < 50:
            score += 0.15
        if backlink.domain_age_days and backlink.domain_age_days < 180:
            score += 0.1
        if backlink.domain_from:
            import re
            domain = backlink.domain_from.lower()
            if re.search(r'\d{4,}', domain) or len(domain) < 6:
                score += 0.1
        
        return min(score, 0.25)
    
    def _composite_risk_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        risk_factors = 0
        
        if (backlink.domain_rank and backlink.domain_rank < 200 and
            backlink.domain_age_days and backlink.domain_age_days < 365):
            risk_factors += 1
        
        ip_counts = Counter(p.ip for p in peers if p.ip and p.ip == backlink.ip)
        if ip_counts.get(backlink.ip, 0) >= 3:
            risk_factors += 1
        
        if backlink.anchor and any(word in backlink.anchor.lower() for word in ["buy", "cheap", "casino"]):
            risk_factors += 1
        
        if risk_factors >= 3:
            return 0.2
        elif risk_factors >= 2:
            return 0.12
        elif risk_factors >= 1:
            return 0.05
        return 0.0
    
    def _spam_score_rule(self, backlink: BacklinkSignal) -> float:
        if backlink.backlink_spam_score is None:
            return 0.0
        
        spam_score = backlink.backlink_spam_score
        if spam_score >= 80:
            return 0.3
        elif spam_score >= 60:
            return 0.2
        elif spam_score >= 40:
            return 0.1
        return 0.0


rule_engine = RuleEngine()

