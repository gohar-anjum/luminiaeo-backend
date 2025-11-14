"""
Training script for PBN Detection ML Model

This script can be used to train a LogisticRegression model when labeled
training data becomes available.

Usage:
    python scripts/train_model.py --data training_data.json --output models/pbn_lr.joblib

Training Data Format (JSON):
    [
        {
            "backlink": {
                "source_url": "...",
                "anchor": "...",
                "domain_rank": 500,
                "domain_age_days": 365,
                "ip": "...",
                "whois_registrar": "...",
                "dofollow": true,
                "first_seen": "2024-01-01T00:00:00Z",
                ...
            },
            "peers": [...],  // Other backlinks for context
            "label": 1  // 1 = PBN, 0 = legitimate
        },
        ...
    ]
"""

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

# Add parent directory to path for imports
import sys
sys.path.insert(0, str(Path(__file__).parent.parent))

from app.services.feature_extractor import feature_extractor
from app.schemas import BacklinkSignal


def load_training_data(data_path: Path) -> tuple[list, list]:
    """Load and parse training data."""
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
            
            # Extract features
            features = feature_extractor.build_feature_vector(backlink, peers)
            X.append(features)
            y.append(label)
        except Exception as e:
            logger.warning("Skipping invalid training sample: {}", e)
            continue
    
    logger.info("Loaded {} samples", len(X))
    return X, y


def train_model(X: list, y: list, test_size: float = 0.2) -> LogisticRegression:
    """Train LogisticRegression model."""
    logger.info("Training model with {} samples", len(X))
    
    # Split data
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=test_size, random_state=42, stratify=y
    )
    
    logger.info("Train set: {} samples, Test set: {} samples", len(X_train), len(X_test))
    
    # Train model
    model = LogisticRegression(
        max_iter=1000,
        random_state=42,
        class_weight='balanced'  # Handle imbalanced data
    )
    
    model.fit(X_train, y_train)
    
    # Evaluate
    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)
    
    logger.info("Model accuracy: {:.2%}", accuracy)
    logger.info("\nClassification Report:\n{}", classification_report(y_test, y_pred))
    logger.info("\nConfusion Matrix:\n{}", confusion_matrix(y_test, y_pred))
    
    # Feature importance
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
    
    # Load data
    X, y = load_training_data(args.data)
    
    if len(X) < 10:
        logger.error("Insufficient training data: {} samples (need at least 10)", len(X))
        return 1
    
    # Train model
    model = train_model(X, y, test_size=args.test_size)
    
    # Save model
    args.output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(model, args.output)
    logger.info("Model saved to {}", args.output)
    
    return 0


if __name__ == '__main__':
    exit(main())

