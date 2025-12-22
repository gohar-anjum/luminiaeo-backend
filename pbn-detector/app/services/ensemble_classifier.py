from __future__ import annotations

from typing import List, Tuple

import numpy as np
from loguru import logger

from app.schemas import BacklinkSignal
from app.services.classifier import classifier_service
from app.services.lightweight_classifier import lightweight_classifier

class EnsembleClassifier:

    def __init__(self) -> None:

        self.weights = {
            'lightweight': 0.4,
            'ml_model': 0.3,
            'rule_based': 0.3,
        }

    def predict_proba(
        self,
        features: np.ndarray,
        backlink: BacklinkSignal,
        rule_scores: dict[str, float],
        base_probability: float
    ) -> Tuple[float, float]:
        probabilities = []
        weights = []

        try:
            lightweight_prob = lightweight_classifier.predict_proba(features, backlink)
            probabilities.append(lightweight_prob)
            weights.append(self.weights['lightweight'])
        except Exception as e:
            logger.warning("Lightweight classifier failed in ensemble", error=str(e))

        if classifier_service.use_ml_model:
            try:
                ml_prob = classifier_service.predict_proba(features, backlink)
                probabilities.append(ml_prob)
                weights.append(self.weights['ml_model'])
            except Exception as e:
                logger.warning("ML model failed in ensemble", error=str(e))

        if rule_scores:
            try:

                rule_boost = sum(rule_scores.values())
                rule_prob = min(rule_boost, 1.0)
                probabilities.append(rule_prob)
                weights.append(self.weights['rule_based'])
            except Exception as e:
                logger.warning("Rule-based probability failed in ensemble", error=str(e))

        if not probabilities:
            return base_probability, 0.5

        total_weight = sum(weights)
        if total_weight == 0:
            return base_probability, 0.5

        normalized_weights = [w / total_weight for w in weights]

        ensemble_prob = sum(p * w for p, w in zip(probabilities, normalized_weights))

        if len(probabilities) > 1:

            std_dev = np.std(probabilities)
            confidence = 1.0 - min(std_dev, 0.5)
        else:
            confidence = 0.7

        return float(np.clip(ensemble_prob, 0.0, 1.0)), float(np.clip(confidence, 0.0, 1.0))

    def predict_proba_batch(
        self,
        features_matrix: np.ndarray,
        backlinks: List[BacklinkSignal],
        rule_scores_list: List[dict[str, float]],
        base_probabilities: np.ndarray
    ) -> Tuple[np.ndarray, np.ndarray]:
        if len(features_matrix) != len(backlinks):
            raise ValueError("Features matrix and backlinks must have same length")

        probabilities = []
        confidences = []

        for i, backlink in enumerate(backlinks):
            prob, conf = self.predict_proba(
                features_matrix[i],
                backlink,
                rule_scores_list[i] if i < len(rule_scores_list) else {},
                base_probabilities[i] if i < len(base_probabilities) else 0.5
            )
            probabilities.append(prob)
            confidences.append(conf)

        return np.array(probabilities), np.array(confidences)

ensemble_classifier = EnsembleClassifier()
