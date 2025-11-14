from __future__ import annotations

from pathlib import Path
from typing import Tuple

import joblib
import numpy as np
from loguru import logger
from sklearn.linear_model import LogisticRegression

from app.config import get_settings
from app.schemas import BacklinkSignal

# Import lightweight classifier as fallback
try:
    from app.services.lightweight_classifier import lightweight_classifier
except ImportError:
    lightweight_classifier = None


class ClassifierService:
    def __init__(self) -> None:
        self.settings = get_settings()
        self.model_path = Path(self.settings.classifier_model_path)
        self.model: LogisticRegression | None = None
        self.use_ml_model = False

    def load(self) -> None:
        if self.model:
            return
        
        if self.model_path.exists():
            try:
                self.model = joblib.load(self.model_path)
                self.use_ml_model = True
                return
            except Exception:
                pass
        
        self.use_ml_model = False

    def predict_proba(self, features: np.ndarray, backlink: BacklinkSignal | None = None) -> float:
        self.load()
        
        if self.use_ml_model and self.model:
            try:
                return float(self.model.predict_proba([features])[0][1])
            except Exception:
                pass
        
        if lightweight_classifier and backlink:
            return lightweight_classifier.predict_proba(features, backlink)
        
        return 0.5


classifier_service = ClassifierService()

