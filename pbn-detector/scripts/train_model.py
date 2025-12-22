from __future__ import annotations

import argparse
import json
from pathlib import Path

import joblib
import numpy as np
from loguru import logger
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
from sklearn.model_selection import train_test_split

import sys
sys.path.insert(0, str(Path(__file__).parent.parent))

from app.services.feature_extractor import feature_extractor
from app.schemas import BacklinkSignal

def load_training_data(data_path: Path) -> tuple[list, list]:
    logger.info("Loading training data from {}", data_path)

    with open(data_path, 'r') as f:
        data = json.load(f)

    X = []
    y = []

    for item in data:
        try:
            backlink = BacklinkSignal(**item['backlink'])
            peers = [BacklinkSignal(**p) for p in item.get('peers', [])]
            label = int(item['label'])

            features = feature_extractor.build_feature_vector(backlink, peers)
            X.append(features)
            y.append(label)
        except Exception as e:
            logger.warning("Skipping invalid training sample: {}", e)
            continue

    logger.info("Loaded {} samples", len(X))
    return X, y

def train_model(X: list, y: list, test_size: float = 0.2) -> LogisticRegression:
    logger.info("Training model with {} samples", len(X))

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=test_size, random_state=42, stratify=y
    )

    logger.info("Train set: {} samples, Test set: {} samples", len(X_train), len(X_test))

    model = LogisticRegression(
        max_iter=1000,
        random_state=42,
        class_weight='balanced'
    )

    model.fit(X_train, y_train)

    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)

    logger.info("Model accuracy: {:.2%}", accuracy)
    logger.info("\nClassification Report:\n{}", classification_report(y_test, y_pred))
    logger.info("\nConfusion Matrix:\n{}", confusion_matrix(y_test, y_pred))

    feature_names = [
        'anchor_length', 'money_anchor', 'domain_rank', 'dofollow',
        'domain_age', 'ip_reuse', 'registrar_reuse', 'link_velocity'
    ]

    logger.info("\nFeature Importance (coefficients):")
    for name, coef in zip(feature_names, model.coef_[0]):
        logger.info("  {}: {:.4f}", name, coef)

    return model

def main():
    parser = argparse.ArgumentParser(description='Train PBN detection model')
    parser.add_argument('--data', required=True, type=Path, help='Training data JSON file')
    parser.add_argument('--output', required=True, type=Path, help='Output model file path')
    parser.add_argument('--test-size', type=float, default=0.2, help='Test set size (default: 0.2)')

    args = parser.parse_args()

    X, y = load_training_data(args.data)

    if len(X) < 10:
        logger.error("Insufficient training data: {} samples (need at least 10)", len(X))
        return 1

    model = train_model(X, y, test_size=args.test_size)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(model, args.output)
    logger.info("Model saved to {}", args.output)

    return 0

if __name__ == '__main__':
    exit(main())
