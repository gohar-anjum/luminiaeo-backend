from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Dict, List, Optional, Any
from loguru import logger

from app.schemas import BacklinkSignal


class NetworkStats:
    """Precomputed network statistics to avoid O(n²) complexity"""
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
        """Initialize rule engine with dependencies and fuzzy logic"""
        # Rule dependencies: rules that boost other rules when triggered
        self.rule_dependencies = {
            'spam_network': ['dataforseo_spam_score', 'shared_ip_network'],
            'new_domain_cluster': ['shared_registrar_network', 'domain_quality'],
            'high_risk_network': ['shared_ip_network', 'domain_quality'],
        }
    
    def precompute_network_stats(self, peers: List[BacklinkSignal]) -> NetworkStats:
        """
        Precompute network statistics once for all backlinks.
        Complexity: O(n) instead of O(n²) when called per backlink.
        """
        ip_counts = Counter(p.ip for p in peers if p.ip)
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        
        # Precompute velocity windows
        now = datetime.now(timezone.utc)
        velocity_data = {
            '7_days': [],
            '30_days': [],
            '90_days': []
        }
        
        for p in peers:
            if p.first_seen:
                first_seen = p.first_seen
                if first_seen.tzinfo is None:
                    first_seen = first_seen.replace(tzinfo=timezone.utc)
                delta_days = (now - first_seen).days
                
                if delta_days <= 7:
                    velocity_data['7_days'].append(p)
                if delta_days <= 30:
                    velocity_data['30_days'].append(p)
                if delta_days <= 90:
                    velocity_data['90_days'].append(p)
        
        return NetworkStats(
            ip_counts=ip_counts,
            registrar_counts=registrar_counts,
            velocity_data=velocity_data,
            total_peers=len(peers)
        )

    def evaluate(
        self, 
        backlink: BacklinkSignal, 
        peers_or_stats: List[BacklinkSignal] | NetworkStats
    ) -> Dict[str, float]:
        """
        Evaluate rules for a backlink.
        
        Args:
            backlink: The backlink to evaluate
            peers_or_stats: Either list of peers (legacy) or precomputed NetworkStats (optimized)
        
        Returns:
            Dictionary of rule scores
        """
        scores: Dict[str, float] = {}

        try:
            # Use precomputed stats if available, otherwise compute (backward compatibility)
            if isinstance(peers_or_stats, NetworkStats):
                network_stats = peers_or_stats
                ip_cluster_score = self._shared_ip_score_from_cache(backlink, network_stats)
                registrar_cluster_score = self._shared_registrar_score_from_cache(backlink, network_stats)
                velocity_score = self._link_velocity_score_from_cache(network_stats)
            else:
                # Legacy mode: compute on the fly
                peers = peers_or_stats
                ip_cluster_score = self._shared_ip_score(backlink, peers)
                registrar_cluster_score = self._shared_registrar_score(backlink, peers)
                velocity_score = self._link_velocity_score(peers)
            
            if ip_cluster_score > 0:
                scores["shared_ip_network"] = ip_cluster_score

            if registrar_cluster_score > 0:
                scores["shared_registrar_network"] = registrar_cluster_score

            anchor_score = self._anchor_quality_score(backlink)
            if anchor_score > 0:
                scores["anchor_quality"] = anchor_score

            if velocity_score > 0:
                scores["velocity_spike"] = velocity_score
            
            domain_score = self._domain_quality_score(backlink)
            if domain_score > 0:
                scores["domain_quality"] = domain_score
            
            # Use appropriate peers list for composite score
            if isinstance(peers_or_stats, NetworkStats):
                # Need peers list for composite score - extract from stats if needed
                # For now, pass empty list as composite doesn't need full peers
                composite_score = self._composite_risk_score_from_cache(backlink, network_stats)
            else:
                composite_score = self._composite_risk_score(backlink, peers_or_stats)
            
            if composite_score > 0:
                scores["composite_risk"] = composite_score
            
            spam_score_rule = self._spam_score_rule(backlink)
            if spam_score_rule > 0:
                scores["dataforseo_spam_score"] = spam_score_rule
            
            # Apply rule chaining and dependencies
            scores = self._apply_rule_dependencies(scores)
            
        except Exception as e:
            logger.error("Rule evaluation exception", 
                        error=str(e), 
                        backlink=str(backlink.source_url),
                        spam_score=backlink.backlink_spam_score,
                        domain_rank=backlink.domain_rank,
                        exc_info=True)
            # Return empty dict on error - will be caught by main.py
            return {}

        return scores
    
    def _apply_rule_dependencies(self, scores: Dict[str, float]) -> Dict[str, float]:
        """Apply rule chaining: boost dependent rules when parent rules trigger"""
        enhanced_scores = scores.copy()
        
        # Check for spam_network pattern
        if 'dataforseo_spam_score' in scores and 'shared_ip_network' in scores:
            # Boost both rules when they appear together
            enhanced_scores['dataforseo_spam_score'] *= 1.2
            enhanced_scores['shared_ip_network'] *= 1.2
        
        # Check for new_domain_cluster pattern
        if 'shared_registrar_network' in scores and 'domain_quality' in scores:
            # Boost domain_quality when registrar clustering detected
            enhanced_scores['domain_quality'] *= 1.3
        
        # Check for high_risk_network pattern
        if 'shared_ip_network' in scores and 'domain_quality' in scores:
            # Boost domain_quality when IP clustering detected
            enhanced_scores['domain_quality'] *= 1.2
        
        return enhanced_scores

    def _shared_ip_score_from_cache(self, backlink: BacklinkSignal, network_stats: NetworkStats) -> float:
        """Optimized version using precomputed counts - O(1) instead of O(n)"""
        if not backlink.ip:
            return 0.0
        
        count = network_stats.ip_counts.get(backlink.ip, 0)
        total = network_stats.total_peers
        
        if count >= 10 and count / total >= 0.4:
            return 0.3
        elif count >= 5 and count / total >= 0.2:
            return 0.2
        elif count >= 3:
            return 0.1
        return 0.0

    def _shared_ip_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        """Legacy method - computes on the fly (O(n))"""
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

    def _shared_registrar_score_from_cache(self, backlink: BacklinkSignal, network_stats: NetworkStats) -> float:
        """Optimized version using precomputed counts - O(1) instead of O(n)"""
        if not backlink.whois_registrar:
            return 0.0
        
        count = network_stats.registrar_counts.get(backlink.whois_registrar, 0)
        total = network_stats.total_peers
        
        if count >= 10 and count / total >= 0.4:
            return 0.25
        elif count >= 5 and count / total >= 0.2:
            return 0.15
        elif count >= 3:
            return 0.1
        return 0.0

    def _shared_registrar_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        """Legacy method - computes on the fly (O(n))"""
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

    def _link_velocity_score_from_cache(self, network_stats: NetworkStats) -> float:
        """Optimized version using precomputed velocity windows - O(1) instead of O(n)"""
        if network_stats.total_peers == 0:
            return 0.0
        
        windows = [(7, 0.2), (30, 0.15), (90, 0.1)]
        window_keys = ['7_days', '30_days', '90_days']
        
        max_score = 0.0
        for days_key, (days, base_score) in zip(window_keys, windows):
            recent_count = len(network_stats.velocity_data.get(days_key, []))
            if recent_count / max(network_stats.total_peers, 1) >= 0.5:
                max_score = max(max_score, base_score)
        
        return max_score

    def _link_velocity_score(self, peers: List[BacklinkSignal]) -> float:
        """Legacy method - computes on the fly (O(n))"""
        if not peers:
            return 0.0
        
        # Check if any peer has first_seen
        has_first_seen = any(p.first_seen for p in peers if p.first_seen)
        if not has_first_seen:
            return 0.0
        
        from datetime import datetime, timezone
        # Use timezone-aware datetime for comparison
        now = datetime.now(timezone.utc)
        windows = [(7, 0.2), (30, 0.15), (90, 0.1)]
        
        max_score = 0.0
        for days, base_score in windows:
            recent = []
            for p in peers:
                if p.first_seen:
                    # Handle both naive and aware datetimes
                    first_seen = p.first_seen
                    if first_seen.tzinfo is None:
                        # Naive datetime - assume UTC
                        first_seen = first_seen.replace(tzinfo=timezone.utc)
                    # Now both are aware, can subtract
                    delta_days = (now - first_seen).days
                    if delta_days <= days:
                        recent.append(p)
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
    
    def _composite_risk_score_from_cache(self, backlink: BacklinkSignal, network_stats: NetworkStats) -> float:
        """Optimized version using precomputed counts - O(1) instead of O(n)"""
        risk_factors = 0
        
        if (backlink.domain_rank and backlink.domain_rank < 200 and
            backlink.domain_age_days and backlink.domain_age_days < 365):
            risk_factors += 1
        
        # Use precomputed IP count
        if network_stats.ip_counts.get(backlink.ip, 0) >= 3:
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

    def _composite_risk_score(self, backlink: BacklinkSignal, peers: List[BacklinkSignal]) -> float:
        """Legacy method - computes on the fly (O(n))"""
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
        """Spam score rule with fuzzy logic for smoother transitions"""
        if backlink.backlink_spam_score is None:
            return 0.0
        
        spam_score = backlink.backlink_spam_score
        
        # Fuzzy membership function for high spam
        def high_spam_membership(score: int) -> float:
            if score >= 80:
                return 1.0
            elif score >= 60:
                # Linear interpolation between 60-80
                return 0.5 + ((score - 60) / 20.0) * 0.5
            elif score >= 40:
                # Linear interpolation between 40-60
                return (score - 40) / 20.0 * 0.5
            return 0.0
        
        membership = high_spam_membership(spam_score)
        
        # Weight by membership degree
        if membership >= 0.9:
            return 0.3
        elif membership >= 0.5:
            return 0.2
        elif membership > 0.0:
            return 0.1
        return 0.0


rule_engine = RuleEngine()

