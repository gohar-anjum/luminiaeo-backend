from __future__ import annotations

from typing import Dict, Optional

from loguru import logger

from app.config import get_settings

class AdaptiveThresholds:

    def __init__(self) -> None:
        self.settings = get_settings()
        self.base_high_risk = self.settings.high_risk_threshold
        self.base_medium_risk = self.settings.medium_risk_threshold

    def adjust_thresholds(
        self,
        total_backlinks: int,
        domain_context: Optional[Dict] = None
    ) -> Dict[str, float]:
        adjusted_high = self.base_high_risk
        adjusted_medium = self.base_medium_risk

        if total_backlinks > 10000:
            adjusted_high = min(self.base_high_risk + 0.05, 0.95)
            adjusted_medium = min(self.base_medium_risk + 0.05, 0.85)
        elif total_backlinks > 5000:
            adjusted_high = min(self.base_high_risk + 0.03, 0.90)
            adjusted_medium = min(self.base_medium_risk + 0.03, 0.80)
        elif total_backlinks < 100:

            adjusted_high = max(self.base_high_risk - 0.05, 0.60)
            adjusted_medium = max(self.base_medium_risk - 0.05, 0.40)

        if domain_context:
            domain_authority = domain_context.get('domain_authority', None)
            if domain_authority:

                if domain_authority > 80:
                    adjusted_high = min(adjusted_high + 0.03, 0.95)
                    adjusted_medium = min(adjusted_medium + 0.03, 0.85)

                elif domain_authority < 30:
                    adjusted_high = max(adjusted_high - 0.03, 0.60)
                    adjusted_medium = max(adjusted_medium - 0.03, 0.40)

            historical_pbn_rate = domain_context.get('historical_pbn_rate', None)
            if historical_pbn_rate:

                if historical_pbn_rate > 0.3:
                    adjusted_high = min(adjusted_high + 0.05, 0.95)
                    adjusted_medium = min(adjusted_medium + 0.05, 0.85)

                elif historical_pbn_rate < 0.1:
                    adjusted_high = max(adjusted_high - 0.03, 0.60)
                    adjusted_medium = max(adjusted_medium - 0.03, 0.40)

        return {
            'high_risk': adjusted_high,
            'medium_risk': adjusted_medium
        }

    def get_risk_level(self, probability: float, thresholds: Optional[Dict[str, float]] = None) -> str:
        if thresholds:
            if probability >= thresholds.get('high_risk', self.base_high_risk):
                return "high"
            if probability >= thresholds.get('medium_risk', self.base_medium_risk):
                return "medium"
            return "low"
        else:
            if probability >= self.base_high_risk:
                return "high"
            if probability >= self.base_medium_risk:
                return "medium"
            return "low"