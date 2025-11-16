from __future__ import annotations

from typing import Dict, Optional

from loguru import logger

from app.config import get_settings


class AdaptiveThresholds:
    """
    Adaptive threshold adjustment based on domain context.
    
    Adjusts risk thresholds dynamically based on:
    - Total number of backlinks
    - Domain authority
    - Historical patterns
    """
    
    def __init__(self) -> None:
        self.settings = get_settings()
        self.base_high_risk = self.settings.high_risk_threshold
        self.base_medium_risk = self.settings.medium_risk_threshold
    
    def adjust_thresholds(
        self, 
        total_backlinks: int,
        domain_context: Optional[Dict] = None
    ) -> Dict[str, float]:
        """
        Adjust thresholds based on context.
        
        Args:
            total_backlinks: Total number of backlinks being analyzed
            domain_context: Optional domain-specific context
        
        Returns:
            Dictionary with adjusted thresholds
        """
        adjusted_high = self.base_high_risk
        adjusted_medium = self.base_medium_risk
        
        # Adjust based on total backlinks
        # More backlinks = be more strict (higher threshold)
        if total_backlinks > 10000:
            adjusted_high = min(self.base_high_risk + 0.05, 0.95)  # Stricter
            adjusted_medium = min(self.base_medium_risk + 0.05, 0.85)
        elif total_backlinks > 5000:
            adjusted_high = min(self.base_high_risk + 0.03, 0.90)
            adjusted_medium = min(self.base_medium_risk + 0.03, 0.80)
        elif total_backlinks < 100:
            # Few backlinks = be more lenient (lower threshold)
            adjusted_high = max(self.base_high_risk - 0.05, 0.60)
            adjusted_medium = max(self.base_medium_risk - 0.05, 0.40)
        
        # Adjust based on domain context if provided
        if domain_context:
            domain_authority = domain_context.get('domain_authority', None)
            if domain_authority:
                # High authority domains = be more strict
                if domain_authority > 80:
                    adjusted_high = min(adjusted_high + 0.03, 0.95)
                    adjusted_medium = min(adjusted_medium + 0.03, 0.85)
                # Low authority domains = be more lenient
                elif domain_authority < 30:
                    adjusted_high = max(adjusted_high - 0.03, 0.60)
                    adjusted_medium = max(adjusted_medium - 0.03, 0.40)
            
            # Historical PBN detection rate
            historical_pbn_rate = domain_context.get('historical_pbn_rate', None)
            if historical_pbn_rate:
                # High historical PBN rate = be more strict
                if historical_pbn_rate > 0.3:
                    adjusted_high = min(adjusted_high + 0.05, 0.95)
                    adjusted_medium = min(adjusted_medium + 0.05, 0.85)
                # Low historical PBN rate = be more lenient
                elif historical_pbn_rate < 0.1:
                    adjusted_high = max(adjusted_high - 0.03, 0.60)
                    adjusted_medium = max(adjusted_medium - 0.03, 0.40)
        
        return {
            'high_risk': adjusted_high,
            'medium_risk': adjusted_medium
        }
    
    def get_risk_level(self, probability: float, thresholds: Optional[Dict[str, float]] = None) -> str:
        """
        Determine risk level using adaptive thresholds.
        
        Args:
            probability: PBN probability
            thresholds: Optional custom thresholds (uses adjusted if not provided)
        
        Returns:
            Risk level: 'high', 'medium', or 'low'
        """
        if thresholds is None:
            thresholds = {
                'high_risk': self.base_high_risk,
                'medium_risk': self.base_medium_risk
            }
        
        if probability >= thresholds['high_risk']:
            return 'high'
        elif probability >= thresholds['medium_risk']:
            return 'medium'
        else:
            return 'low'


adaptive_thresholds = AdaptiveThresholds()

