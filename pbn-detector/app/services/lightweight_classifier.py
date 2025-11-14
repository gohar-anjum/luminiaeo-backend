from __future__ import annotations

import numpy as np

from app.schemas import BacklinkSignal


class LightweightClassifier:
    def __init__(self) -> None:
        self.weights = {
            'domain_rank': 0.14,
            'domain_age': 0.14,
            'ip_reuse': 0.18,
            'registrar_reuse': 0.14,
            'link_velocity': 0.13,
            'anchor_quality': 0.12,
            'dofollow': 0.05,
            'safe_browsing': 0.08,
        }
        
        self.thresholds = {
            'domain_rank_low': 100,
            'domain_rank_medium': 500,
            'domain_age_new': 365,
            'domain_age_young': 1095,
            'ip_reuse_high': 0.3,
            'registrar_reuse_high': 0.3,
            'velocity_high': 0.5,
        }
    
    def predict_proba(self, features: np.ndarray, backlink: BacklinkSignal) -> float:
        if len(features) == 8:
            features = np.append(features, [0.0, 0.0, 0.5])
        elif len(features) == 10:
            features = np.append(features, [0.5])
        elif len(features) != 11:
            return 0.5
        
        anchor_length, money_anchor, domain_rank, dofollow, domain_age, \
        ip_reuse, registrar_reuse, link_velocity, domain_name_suspicious, \
        hosting_pattern, spam_score_normalized = features
        
        scores = {}
        
        if domain_rank <= 0:
            scores['domain_rank'] = 0.5
        elif domain_rank < self.thresholds['domain_rank_low']:
            scores['domain_rank'] = 0.9
        elif domain_rank < self.thresholds['domain_rank_medium']:
            scores['domain_rank'] = 0.6
        elif domain_rank < 1000:
            scores['domain_rank'] = 0.3
        else:
            scores['domain_rank'] = 0.1
        
        if domain_age <= 0:
            scores['domain_age'] = 0.5
        elif domain_age < self.thresholds['domain_age_new']:
            scores['domain_age'] = 0.9
        elif domain_age < self.thresholds['domain_age_young']:
            scores['domain_age'] = 0.6
        elif domain_age < 3650:
            scores['domain_age'] = 0.3
        else:
            scores['domain_age'] = 0.1
        
        if ip_reuse >= self.thresholds['ip_reuse_high']:
            scores['ip_reuse'] = 0.9
        elif ip_reuse >= 0.2:
            scores['ip_reuse'] = 0.6
        elif ip_reuse >= 0.1:
            scores['ip_reuse'] = 0.3
        else:
            scores['ip_reuse'] = 0.1
        
        if registrar_reuse >= self.thresholds['registrar_reuse_high']:
            scores['registrar_reuse'] = 0.8
        elif registrar_reuse >= 0.2:
            scores['registrar_reuse'] = 0.5
        elif registrar_reuse >= 0.1:
            scores['registrar_reuse'] = 0.3
        else:
            scores['registrar_reuse'] = 0.1
        
        if link_velocity >= self.thresholds['velocity_high']:
            scores['link_velocity'] = 0.8
        elif link_velocity >= 0.3:
            scores['link_velocity'] = 0.5
        elif link_velocity >= 0.1:
            scores['link_velocity'] = 0.3
        else:
            scores['link_velocity'] = 0.1
        
        if money_anchor > 0:
            scores['anchor_quality'] = 0.9
        elif anchor_length < 5:
            scores['anchor_quality'] = 0.6
        elif anchor_length > 100:
            scores['anchor_quality'] = 0.4
        else:
            scores['anchor_quality'] = 0.2
        
        scores['dofollow'] = 0.6 if dofollow > 0 else 0.3
        
        if backlink.safe_browsing_status == "flagged":
            scores['safe_browsing'] = 0.95
        elif backlink.safe_browsing_status == "clean":
            scores['safe_browsing'] = 0.1
        else:
            scores['safe_browsing'] = 0.5
        
        scores['domain_name_pattern'] = domain_name_suspicious
        scores['hosting_pattern'] = hosting_pattern
        scores['spam_score'] = spam_score_normalized
        
        base_probability = sum(scores.get(key, 0.5) * weight for key, weight in self.weights.items())
        base_probability += domain_name_suspicious * 0.08
        base_probability += hosting_pattern * 0.07
        base_probability += spam_score_normalized * 0.20
        
        composite_boosts = self._compute_composite_signals(
            domain_rank, domain_age, ip_reuse, registrar_reuse,
            link_velocity, money_anchor, domain_name_suspicious, spam_score_normalized
        )
        
        if composite_boosts['high_risk_network']:
            base_probability *= 1.2
        if composite_boosts['new_domain_cluster']:
            base_probability *= 1.15
        if composite_boosts['spam_network']:
            base_probability *= 1.25
        
        return float(max(0.0, min(1.0, base_probability)))
    
    def _compute_composite_signals(
        self, domain_rank: float, domain_age: float, ip_reuse: float,
        registrar_reuse: float, link_velocity: float, money_anchor: float,
        domain_name_suspicious: float, spam_score: float
    ) -> dict[str, bool]:
        signals = {
            'high_risk_network': False,
            'new_domain_cluster': False,
            'spam_network': False,
        }
        
        # High-risk network: Low rank + high IP/registrar reuse
        if (domain_rank < 500 and (ip_reuse > 0.3 or registrar_reuse > 0.3)):
            signals['high_risk_network'] = True
        
        # New domain cluster: New domain + high clustering + high velocity
        if (domain_age < 365 and (ip_reuse > 0.2 or registrar_reuse > 0.2) and link_velocity > 0.4):
            signals['new_domain_cluster'] = True
        
        # Spam network: Spam anchors + clustering + suspicious domain names
        if (money_anchor > 0.5 and (ip_reuse > 0.2 or registrar_reuse > 0.2) and domain_name_suspicious > 0.5):
            signals['spam_network'] = True
        
        # High spam score network: High DataForSEO spam score + clustering
        if (spam_score > 0.6 and (ip_reuse > 0.2 or registrar_reuse > 0.2)):
            signals['spam_network'] = True
        
        # Very high spam score: DataForSEO indicates high spam risk
        if spam_score > 0.8:
            signals['spam_network'] = True
        
        return signals
    


lightweight_classifier = LightweightClassifier()

