from __future__ import annotations

from pathlib import Path
from typing import Tuple

import joblib
import numpy as np
from loguru import logger
from sklearn.linear_model import LogisticRegression

from app.config import get_settings
from app.schemas import BacklinkSignal

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
            except Exception as e:
                logger.warning("ML model prediction failed", error=str(e))
                pass

        if lightweight_classifier and backlink:
            try:
                result = lightweight_classifier.predict_proba(features, backlink)
                logger.info("Lightweight classifier result",
                           probability=result,
                           features_len=len(features),
                           spam_score=backlink.backlink_spam_score,
                           domain_rank=backlink.domain_rank,
                           backlink=str(backlink.source_url))
                return result
            except Exception as e:
                logger.error("Lightweight classifier failed",
                           error=str(e),
                           features_len=len(features),
                           backlink=str(backlink.source_url) if backlink else None,
                           exc_info=True)

        logger.warning("Classifier falling back to default 0.5",
                      use_ml_model=self.use_ml_model,
                      has_lightweight=lightweight_classifier is not None,
                      has_backlink=backlink is not None,
                      features_len=len(features) if 'features' in locals() else None)
        return 0.5

classifier_service = ClassifierService()
