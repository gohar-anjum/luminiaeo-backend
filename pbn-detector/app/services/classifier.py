from __future__ import annotations

from pathlib import Path
from typing import Tuple

import joblib
import numpy as np
from loguru import logger
from sklearn.linear_model import LogisticRegression

from app.config import get_settings


class ClassifierService:
    def __init__(self) -> None:
        self.settings = get_settings()
        self.model_path = Path(self.settings.classifier_model_path)
        self.model: LogisticRegression | None = None

    def load(self) -> None:
        if self.model:
            return
        if not self.model_path.exists():
            logger.warning("Classifier model not found at {}", self.model_path)
            self.model = LogisticRegression()
            self.model.coef_ = np.zeros((1, 8))
            self.model.intercept_ = np.zeros(1)
            self.model.classes_ = np.array([0, 1])
            return
        self.model = joblib.load(self.model_path)

    def predict_proba(self, features: np.ndarray) -> float:
        self.load()
        if not self.model:
            return 0.0
        probability = float(self.model.predict_proba([features])[0][1])
        return probability


classifier_service = ClassifierService()

