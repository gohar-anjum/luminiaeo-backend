# PBN Detector Microservice - Deep Analysis

## Executive Summary

**Current Status**: The microservice is partially functional but using **DUMMY ML predictions**. The rule-based engine and content similarity are working correctly, but the ML classifier is not trained and returns constant probabilities.

## Component Analysis

### 1. ML Classifier Service ⚠️ **CRITICAL ISSUE**

**Current Implementation:**
- Uses LogisticRegression from scikit-learn
- **Model file does NOT exist** (`models/pbn_lr.joblib` is missing)
- Falls back to dummy model with:
  - All-zero coefficients (`coef_ = zeros((1, 8))`)
  - Zero intercept (`intercept_ = zeros(1)`)
  - **Result: Always returns 0.5 probability (50%) for ALL backlinks**

**Impact**: The ML component is not contributing to detection accuracy.

**Recommendation**: Implement a lightweight rule-based scoring system that doesn't require training data, or create a pre-trained model with domain knowledge.

---

### 2. Feature Extractor ✅ **WORKING**

**Features Extracted (8 total):**
1. `anchor_length` - Length of anchor text (0-100+)
2. `money_anchor` - Binary flag for spam keywords (0 or 1)
3. `domain_rank` - Domain authority/rank (0-1000+)
4. `dofollow` - Link type flag (0 or 1)
5. `domain_age_days` - Domain age in days (0-10000+)
6. `ip_reuse` - Ratio of backlinks sharing same IP (0-1)
7. `registrar_reuse` - Ratio sharing same registrar (0-1)
8. `link_velocity` - Ratio of recent links (0-1)

**Issues:**
- ❌ **No feature normalization/scaling** - Features have vastly different scales
- ❌ **Domain knowledge not fully utilized** - Missing features like:
  - ASN clustering
  - Hosting provider patterns
  - Safe Browsing integration in features
  - Anchor text diversity
  - Link distribution patterns

**Recommendation**: Add feature scaling and additional domain-specific features.

---

### 3. Rule Engine ✅ **WORKING**

**Rules Implemented:**
- `shared_ip_network` (+0.2): 5+ backlinks from same IP
- `shared_registrar_network` (+0.2): 5+ backlinks from same registrar
- `money_anchor` (+0.25): Spam keywords in anchor
- `velocity_spike` (+0.15): 50%+ links created within 7 days

**Status**: Real implementation, working correctly.

**Recommendation**: Add more sophisticated rules:
- ASN clustering
- Hosting provider patterns
- Domain name patterns (random strings, numbers)
- Content quality signals

---

### 4. Content Similarity Service ✅ **WORKING**

**Implementation:**
- Uses MinHash with Jaccard similarity
- 4-gram shingles
- 128 permutations
- Detects duplicate content across backlinks

**Status**: Real implementation, working correctly.

**Recommendation**: Consider adding:
- TF-IDF similarity for more nuanced detection
- Semantic similarity (if embeddings available)

---

### 5. Probability Combination Logic ⚠️ **NEEDS IMPROVEMENT**

**Current Formula:**
```python
boosted_probability = min(probability + rules_boost + content_similarity * 0.1, 0.999)
```

**Issues:**
- Adding raw scores without proper weighting
- ML probability (0-1) + rule boost (0-0.8) can exceed 1.0
- Content similarity contribution is minimal (0.1 multiplier)
- No consideration for feature importance

**Recommendation**: Use weighted combination or ensemble approach.

---

## Proposed Improvements

### Option 1: Lightweight Rule-Based ML (No Training Data Required) ⭐ **RECOMMENDED**

Create a deterministic scoring system based on domain knowledge:

1. **Feature-Based Scoring**: Convert features to risk scores using heuristics
2. **Weighted Combination**: Use domain knowledge to weight features
3. **Ensemble with Rules**: Combine rule-based and feature-based scores

**Advantages:**
- No training data needed
- Interpretable
- Fast and lightweight
- Works immediately

### Option 2: Train a Real ML Model

1. Collect labeled training data
2. Normalize features
3. Train LogisticRegression or GradientBoosting
4. Save model file

**Advantages:**
- Can learn complex patterns
- Potentially more accurate

**Disadvantages:**
- Requires labeled data
- Needs retraining as patterns evolve
- More complex deployment

### Option 3: Hybrid Approach (Best of Both)

Combine:
- Rule-based scoring (immediate, interpretable)
- Feature-based ML scoring (learned patterns)
- Content similarity (duplicate detection)
- Safe Browsing signals (external validation)

---

## Implementation Plan

1. ✅ **Immediate**: Implement lightweight rule-based ML scoring
2. ✅ **Short-term**: Add feature normalization
3. ✅ **Short-term**: Improve probability combination logic
4. ⏳ **Long-term**: Collect training data and train real model
5. ⏳ **Long-term**: Add more sophisticated features

---

## Metrics to Track

- Detection accuracy (if labeled data available)
- False positive rate
- False negative rate
- Processing latency
- Feature importance analysis

