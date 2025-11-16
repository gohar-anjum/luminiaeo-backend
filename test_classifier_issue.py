#!/usr/bin/env python3
"""Test to see why classifier returns 0.5"""
import sys
sys.path.insert(0, '/var/www/luminiaeo-backend/pbn-detector')

# Test with actual data from response
from datetime import datetime
from app.schemas import BacklinkSignal
from app.services.feature_extractor import FeatureExtractor
from app.services.classifier import classifier_service

# Create a backlink matching the real data
backlink = BacklinkSignal(
    source_url="http://innewyorkguides.com/",
    domain_from="innewyorkguides.com",
    domain_rank=7.0,
    backlink_spam_score=75,
    ip="199.188.201.75",
    whois_registrar=None,
    domain_age_days=None,
    dofollow=True,
    safe_browsing_status="clean",
    first_seen=datetime.fromisoformat("2025-06-21T20:06:28+00:00"),
)

peers = [backlink]

# Test feature extraction
extractor = FeatureExtractor()
try:
    features = extractor.build_feature_vector(backlink, peers)
    print(f"Features extracted: {len(features)} features")
    print(f"Features: {features}")
    
    # Test classifier
    prob = classifier_service.predict_proba(features, backlink)
    print(f"\nClassifier probability: {prob}")
    print(f"Expected: ~0.45, Got: {prob}")
    
    if prob == 0.5:
        print("\n⚠️  ISSUE: Classifier returning default 0.5!")
        print("This means lightweight classifier is failing or not being called")
except Exception as e:
    print(f"ERROR: {e}")
    import traceback
    traceback.print_exc()
