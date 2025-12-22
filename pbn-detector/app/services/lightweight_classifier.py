from __future__ import annotations

import bisect
import numpy as np
from typing import List, Tuple

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

        self._domain_rank_lookup = self._build_domain_rank_lookup()
        self._domain_age_lookup = self._build_domain_age_lookup()
        self._ip_reuse_lookup = self._build_ip_reuse_lookup()
        self._registrar_reuse_lookup = self._build_registrar_reuse_lookup()
        self._link_velocity_lookup = self._build_link_velocity_lookup()

    def _build_domain_rank_lookup(self) -> Tuple[List[float], List[float]]:
        thresholds = [0.0, 100.0, 500.0, 1000.0, float('inf')]
        scores = [0.5, 0.9, 0.6, 0.3, 0.1]
        return thresholds, scores

    def _build_domain_age_lookup(self) -> Tuple[List[float], List[float]]:
        thresholds = [0.0, 365.0, 1095.0, 3650.0, float('inf')]
        scores = [0.5, 0.9, 0.6, 0.3, 0.1]
        return thresholds, scores

    def _build_ip_reuse_lookup(self) -> Tuple[List[float], List[float]]:
        thresholds = [0.0, 0.1, 0.2, 0.3, float('inf')]
        scores = [0.1, 0.3, 0.6, 0.9, 0.9]
        return thresholds, scores

    def _build_registrar_reuse_lookup(self) -> Tuple[List[float], List[float]]:
        thresholds = [0.0, 0.1, 0.2, 0.3, float('inf')]
        scores = [0.1, 0.3, 0.5, 0.8, 0.8]
        return thresholds, scores

    def _build_link_velocity_lookup(self) -> Tuple[List[float], List[float]]:
        thresholds = [0.0, 0.1, 0.3, 0.5, float('inf')]
        scores = [0.1, 0.3, 0.5, 0.8, 0.8]
        return thresholds, scores

    def _score_with_lookup(self, value: float, thresholds: List[float], scores: List[float]) -> float:
        idx = bisect.bisect_left(thresholds, value)
        return scores[idx] if idx < len(scores) else scores[-1]

    def predict_proba(self, features: np.ndarray, backlink: BacklinkSignal) -> float:

        if len(features) == 8:

            features = np.append(features, [0.0, 0.0, 0.5])
        elif len(features) == 10:

            features = np.append(features, [0.5])
        elif len(features) != 11:

            from loguru import logger
            logger.error(f"Invalid feature vector length: {len(features)}, expected 11")
            return 0.5

        anchor_length, money_anchor, domain_rank, dofollow, domain_age, \
        ip_reuse, registrar_reuse, link_velocity, domain_name_suspicious, \
        hosting_pattern, spam_score_normalized = features

        scores = {}

        thresholds, score_values = self._domain_rank_lookup
        scores['domain_rank'] = self._score_with_lookup(domain_rank, thresholds, score_values)

        thresholds, score_values = self._domain_age_lookup
        scores['domain_age'] = self._score_with_lookup(domain_age, thresholds, score_values)

        thresholds, score_values = self._ip_reuse_lookup
        scores['ip_reuse'] = self._score_with_lookup(ip_reuse, thresholds, score_values)

        thresholds, score_values = self._registrar_reuse_lookup
        scores['registrar_reuse'] = self._score_with_lookup(registrar_reuse, thresholds, score_values)

        thresholds, score_values = self._link_velocity_lookup
        scores['link_velocity'] = self._score_with_lookup(link_velocity, thresholds, score_values)

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

        if spam_score_normalized > 0.7:
            base_probability += 0.15
        elif spam_score_normalized > 0.5:
            base_probability += 0.10

        if domain_rank < 10:
            base_probability += 0.10
        elif domain_rank < 50:
            base_probability += 0.05

        return float(max(0.0, min(1.0, base_probability)))

    def predict_proba_batch(
        self,
        features_matrix: np.ndarray,
        backlinks: List[BacklinkSignal]
    ) -> np.ndarray:
        if len(features_matrix) != len(backlinks):
            raise ValueError("Features matrix and backlinks must have same length")

        if len(features_matrix) == 0:
            return np.array([])

        if features_matrix.shape[1] != 11:

            if features_matrix.shape[1] < 11:
                padding = np.zeros((features_matrix.shape[0], 11 - features_matrix.shape[1]))
                features_matrix = np.hstack([features_matrix, padding])
            else:
                features_matrix = features_matrix[:, :11]

        domain_ranks = features_matrix[:, 2]
        domain_ages = features_matrix[:, 4]
        ip_reuses = features_matrix[:, 5]
        registrar_reuses = features_matrix[:, 6]
        link_velocities = features_matrix[:, 7]
        money_anchors = features_matrix[:, 1]
        domain_name_suspicious = features_matrix[:, 8]
        spam_scores = features_matrix[:, 10]

        domain_rank_scores = np.select(
            [
                domain_ranks <= 0,
                domain_ranks < 100,
                domain_ranks < 500,
                domain_ranks < 1000
            ],
            [0.5, 0.9, 0.6, 0.3],
            default=0.1
        )

        domain_age_scores = np.select(
            [
                domain_ages <= 0,
                domain_ages < 365,
                domain_ages < 1095,
                domain_ages < 3650
            ],
            [0.5, 0.9, 0.6, 0.3],
            default=0.1
        )

        ip_reuse_scores = np.select(
            [
                ip_reuses >= 0.3,
                ip_reuses >= 0.2,
                ip_reuses >= 0.1
            ],
            [0.9, 0.6, 0.3],
            default=0.1
        )

        registrar_reuse_scores = np.select(
            [
                registrar_reuses >= 0.3,
                registrar_reuses >= 0.2,
                registrar_reuses >= 0.1
            ],
            [0.8, 0.5, 0.3],
            default=0.1
        )

        link_velocity_scores = np.select(
            [
                link_velocities >= 0.5,
                link_velocities >= 0.3,
                link_velocities >= 0.1
            ],
            [0.8, 0.5, 0.3],
            default=0.1
        )

        base_probabilities = (
            domain_rank_scores * self.weights['domain_rank'] +
            domain_age_scores * self.weights['domain_age'] +
            ip_reuse_scores * self.weights['ip_reuse'] +
            registrar_reuse_scores * self.weights['registrar_reuse'] +
            link_velocity_scores * self.weights['link_velocity'] +
            domain_name_suspicious * 0.08 +
            spam_scores * 0.20
        )

        high_risk_network = (domain_ranks < 500) & ((ip_reuses > 0.3) | (registrar_reuses > 0.3))
        new_domain_cluster = (domain_ages < 365) & ((ip_reuses > 0.2) | (registrar_reuses > 0.2)) & (link_velocities > 0.4)
        spam_network = (spam_scores > 0.7) | ((spam_scores > 0.6) & ((ip_reuses > 0.2) | (registrar_reuses > 0.2)))

        base_probabilities[high_risk_network] *= 1.2
        base_probabilities[new_domain_cluster] *= 1.15
        base_probabilities[spam_network] *= 1.25

        base_probabilities[spam_scores > 0.7] += 0.15
        base_probabilities[(spam_scores > 0.5) & (spam_scores <= 0.7)] += 0.10
        base_probabilities[domain_ranks < 10] += 0.10
        base_probabilities[(domain_ranks >= 10) & (domain_ranks < 50)] += 0.05

        return np.clip(base_probabilities, 0.0, 1.0)

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

        if (domain_rank < 500 and (ip_reuse > 0.3 or registrar_reuse > 0.3)):
            signals['high_risk_network'] = True

        if (domain_age < 365 and (ip_reuse > 0.2 or registrar_reuse > 0.2) and link_velocity > 0.4):
            signals['new_domain_cluster'] = True

        if (money_anchor > 0.5 and (ip_reuse > 0.2 or registrar_reuse > 0.2) and domain_name_suspicious > 0.5):
            signals['spam_network'] = True

        if (spam_score > 0.6 and (ip_reuse > 0.2 or registrar_reuse > 0.2)):
            signals['spam_network'] = True

        if spam_score > 0.8:
            signals['spam_network'] = True

        if spam_score > 0.7:
            signals['spam_network'] = True

        return signals

lightweight_classifier = LightweightClassifier()
