# PBN Detector Microservice

A high-performance FastAPI-based microservice for detecting Private Blog Network (PBN) backlinks using a sophisticated multi-layered detection system combining rule-based heuristics, machine learning classifiers, and network analysis.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Core Components](#core-components)
- [Features](#features)
- [API Documentation](#api-documentation)
- [Installation & Setup](#installation--setup)
- [Configuration](#configuration)
- [Performance Optimizations](#performance-optimizations)
- [Testing](#testing)
- [Deployment](#deployment)

## Overview

The PBN Detector microservice analyzes backlink networks to identify potential Private Blog Networks (PBNs) - artificially created link networks used for SEO manipulation. The service employs a multi-layered detection approach:

1. **Rule-Based Engine**: Evaluates backlinks against heuristic rules (IP clustering, registrar patterns, domain quality, etc.)
2. **Machine Learning Classifiers**: Uses both trained ML models and lightweight rule-based classifiers
3. **Ensemble Classification**: Combines multiple models for improved accuracy
4. **Network Analysis**: Analyzes graph structures, temporal patterns, and statistical anomalies
5. **Content Similarity Detection**: Uses MinHash LSH to detect duplicate content patterns

### Key Capabilities

- **Multi-Signal Detection**: Combines 11+ feature types including domain rank, spam scores, IP clustering, registrar patterns, anchor quality, and temporal patterns
- **Adaptive Thresholds**: Dynamically adjusts risk thresholds based on context (backlink volume, domain authority, historical patterns)
- **High Performance**: Optimized for O(n) complexity with precomputed network features instead of O(n²)
- **Parallel Processing**: Supports parallel backlink processing for large datasets (>50 backlinks)
- **Caching**: Redis-based caching for MinHash computations and domain pattern analysis
- **Fault Tolerance**: Graceful degradation with fallback mechanisms for all components

## Architecture

### System Design

```
┌─────────────────────────────────────────────────────────────┐
│                    FastAPI Application                       │
│                    (app/main.py)                            │
└───────────────────────┬─────────────────────────────────────┘
                        │
        ┌───────────────┼───────────────┐
        │               │               │
┌───────▼──────┐ ┌─────▼──────┐ ┌─────▼──────────┐
│ Rule Engine  │ │ Classifier  │ │ Feature       │
│              │ │ Service     │ │ Extractor     │
└──────┬───────┘ └─────┬───────┘ └──────┬────────┘
       │               │                 │
       │      ┌────────┼────────┐       │
       │      │        │        │       │
┌──────▼──────▼───┐ ┌──▼──────┐ │ ┌─────▼──────────┐
│ Ensemble        │ │ Lightweight│ │ Enhanced       │
│ Classifier      │ │ Classifier │ │ Features       │
└─────────────────┘ └────────────┘ └────────────────┘
       │
       │
┌──────▼──────────────────────────────────────────┐
│ Content Similarity Service (MinHash LSH)         │
│ Adaptive Thresholds                             │
│ Cache Client (Redis)                             │
└──────────────────────────────────────────────────┘
```

### Request Flow

1. **Request Reception**: FastAPI receives backlink detection request
2. **Network Precomputation**: Precomputes network-level features (IP counts, registrar counts, velocity windows) - O(n)
3. **Feature Extraction**: Extracts 11 features per backlink using precomputed network data - O(1) per backlink
4. **Classification**: 
   - Base probability from ML model or lightweight classifier
   - Rule-based scoring from rule engine
   - Enhanced features boost (if enabled)
   - Ensemble classifier combination (if enabled)
5. **Probability Boosting**: Combines base probability with rule scores and content similarity
6. **Risk Assessment**: Applies adaptive thresholds to determine risk level
7. **Response Generation**: Returns detection results with probabilities, risk levels, and reasons

## Core Components

### 1. Rule Engine (`app/rules/engine.py`)

Evaluates backlinks against heuristic rules to identify PBN patterns.

**Rules Implemented:**
- **Shared IP Network**: Detects clustering of backlinks on same IP addresses
- **Shared Registrar Network**: Identifies registrar clustering patterns
- **Domain Quality**: Evaluates domain rank, age, and naming patterns
- **Anchor Quality**: Detects spammy/commercial anchor text
- **Link Velocity**: Identifies unnatural link acquisition spikes
- **Composite Risk**: Multi-factor risk assessment
- **DataForSEO Spam Score**: Integrates external spam scoring

**Optimizations:**
- Precomputed network statistics (O(1) lookups instead of O(n))
- Rule dependency chaining (rules boost each other when triggered together)
- Fuzzy logic for smooth threshold transitions

### 2. Feature Extractor (`app/services/feature_extractor.py`)

Extracts 11 features from each backlink:

1. **Anchor Length**: Length of anchor text
2. **Money Anchor Score**: Commercial/spam keyword detection
3. **Domain Rank**: Domain authority metric
4. **Dofollow**: Link type indicator
5. **Domain Age**: Age in days
6. **IP Reuse Ratio**: Fraction of backlinks sharing same IP
7. **Registrar Reuse Ratio**: Fraction sharing same registrar
8. **Link Velocity**: Temporal clustering of link acquisition
9. **Domain Name Pattern**: Suspicious naming patterns (random strings, excessive numbers)
10. **Hosting Pattern**: Hosting provider clustering
11. **Spam Score Normalized**: DataForSEO spam score (0-100 → 0-1)

**Optimizations:**
- Precomputed network features (NetworkFeatures class)
- Cached regex patterns for domain analysis
- Set-based keyword matching for anchor analysis

### 3. Classifier Service (`app/services/classifier.py`)

Orchestrates classification with fallback chain:

1. **Primary**: Trained Logistic Regression model (if available)
2. **Fallback**: Lightweight rule-based classifier
3. **Default**: 0.5 probability

### 4. Lightweight Classifier (`app/services/lightweight_classifier.py`)

Rule-based classifier with optimized lookup tables:

- **Weighted Feature Scoring**: 8 core features with tuned weights
- **Composite Signal Detection**: Identifies high-risk network patterns
- **Batch Processing**: Vectorized operations for multiple backlinks
- **O(log n) Threshold Checks**: Binary search lookup tables

**Feature Weights:**
- Domain Rank: 14%
- Domain Age: 14%
- IP Reuse: 18%
- Registrar Reuse: 14%
- Link Velocity: 13%
- Anchor Quality: 12%
- Dofollow: 5%
- Safe Browsing: 8%

### 5. Ensemble Classifier (`app/services/ensemble_classifier.py`)

Combines multiple models for improved accuracy:

- **Weighted Voting**: Combines lightweight classifier, ML model, and rule-based signals
- **Confidence Scoring**: Calculates confidence based on model agreement
- **Graceful Degradation**: Falls back to base probability if ensemble fails

**Default Weights:**
- Lightweight Classifier: 40%
- ML Model: 30%
- Rule-Based Signals: 30%

### 6. Enhanced Features (`app/services/enhanced_features.py`)

Advanced feature extraction for improved detection:

**Temporal Features:**
- Link Stability: Lifespan-based risk assessment
- Temporal Clustering: Burst pattern detection

**Graph Features:**
- Clustering Coefficient: Network interconnectivity analysis
- Network Density: Connection ratio analysis

**Statistical Features:**
- Z-Score Analysis: Outlier detection for domain rank, age, spam score
- Distribution Analysis: Anomaly identification

### 7. Content Similarity Service (`app/services/content.py`)

Detects duplicate content using MinHash LSH:

- **MinHash Algorithm**: Efficient similarity estimation
- **LSH (Locality-Sensitive Hashing)**: O(n log n) complexity instead of O(n²)
- **Caching**: Redis caching for MinHash objects
- **Shingle-based Analysis**: 4-word shingles for text comparison

### 8. Adaptive Thresholds (`app/services/adaptive_thresholds.py`)

Dynamically adjusts risk thresholds:

- **Volume-based**: Adjusts based on total backlink count
- **Domain Context**: Considers domain authority and historical patterns
- **Context-aware**: More strict for high-authority domains with PBN history

### 9. Cache Client (`app/utils/cache.py`)

Redis-based caching layer:

- **MinHash Caching**: Caches computed MinHash objects
- **Domain Pattern Caching**: Caches domain pattern analysis
- **Graceful Degradation**: Works without Redis (caching disabled)
- **TTL Management**: Configurable cache expiration

## Features

### Detection Signals

The service evaluates multiple signals to determine PBN probability:

1. **Network Clustering**
   - IP address clustering (shared hosting patterns)
   - Registrar clustering (bulk registration patterns)
   - Temporal clustering (burst link acquisition)

2. **Domain Quality**
   - Low domain rank (< 50)
   - New domains (< 180 days)
   - Suspicious naming patterns (random strings, excessive numbers)

3. **Link Characteristics**
   - Spammy anchor text (commercial keywords, money terms)
   - High link velocity (unnatural acquisition rate)
   - Short link lifespan (temporary links)

4. **External Signals**
   - DataForSEO spam score (0-100)
   - Safe Browsing status (Google Safe Browsing API)
   - Domain age and authority metrics

5. **Content Patterns**
   - Duplicate content across backlink sources
   - Similar anchor text patterns

### Risk Levels

- **High Risk** (≥ 0.75): Strong PBN indicators, likely manipulation
- **Medium Risk** (≥ 0.50): Suspicious patterns, requires review
- **Low Risk** (< 0.50): Appears legitimate

*Thresholds are adaptive and adjust based on context*

## API Documentation

### Health Check

```http
GET /health
```

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2025-01-15T10:30:00.000000"
}
```

### Detect PBN Backlinks

```http
POST /detect
Content-Type: application/json
```

**Request Body:**
```json
{
  "domain": "https://example.com",
  "task_id": "task-123",
  "backlinks": [
    {
      "source_url": "https://backlink1.com/page",
      "domain_from": "backlink1.com",
      "anchor": "example anchor text",
      "link_type": "dofollow",
      "domain_rank": 45.0,
      "ip": "192.168.1.1",
      "whois_registrar": "Registrar Inc",
      "domain_age_days": 365,
      "first_seen": "2024-01-15T10:00:00+00:00",
      "last_seen": "2025-01-15T10:00:00+00:00",
      "dofollow": true,
      "links_count": 5,
      "safe_browsing_status": "clean",
      "safe_browsing_threats": [],
      "backlink_spam_score": 65
    }
  ]
}
```

**Response:**
```json
{
  "domain": "https://example.com",
  "task_id": "task-123",
  "generated_at": "2025-01-15T10:30:00.000000",
  "items": [
    {
      "source_url": "https://backlink1.com/page",
      "pbn_probability": 0.8234,
      "risk_level": "high",
      "reasons": [
        "shared_ip_network",
        "dataforseo_spam_score",
        "domain_quality",
        "content_similarity_high"
      ],
      "signals": {
        "ip": "192.168.1.1",
        "whois_registrar": "Registrar Inc",
        "domain_age_days": 365,
        "domain_rank": 45.0,
        "content_similarity": 0.85,
        "rules": {
          "shared_ip_network": 0.3,
          "dataforseo_spam_score": 0.2,
          "domain_quality": 0.15
        },
        "safe_browsing_status": "clean",
        "safe_browsing_threats": [],
        "backlink_spam_score": 65
      }
    }
  ],
  "summary": {
    "high_risk_count": 1,
    "medium_risk_count": 0,
    "low_risk_count": 0
  },
  "meta": {
    "latency_ms": 125,
    "model_version": "lightweight-v1.0"
  }
}
```

### Response Fields

**DetectionItem:**
- `source_url`: Backlink source URL
- `pbn_probability`: PBN probability score (0.0 - 0.999)
- `risk_level`: Risk classification ("high", "medium", "low")
- `reasons`: List of triggered rule names
- `signals`: Detailed signal data

**DetectionSummary:**
- `high_risk_count`: Number of high-risk backlinks
- `medium_risk_count`: Number of medium-risk backlinks
- `low_risk_count`: Number of low-risk backlinks

**DetectionMeta:**
- `latency_ms`: Request processing time in milliseconds
- `model_version`: Model version used ("lightweight-v1.0" or "lr-1.0")

## Installation & Setup

### Prerequisites

- Python 3.11+
- Poetry (dependency management)
- Redis (optional, for caching)
- Trained ML model file (optional, `models/pbn_lr.joblib`)

### Installation

#### 1. Install Poetry (if not already installed)

```bash
# Install Poetry using the official installer
curl -sSL https://install.python-poetry.org | python3 -
```

Or follow the [official Poetry installation guide](https://python-poetry.org/docs/#installation).

#### 2. Set Up the Poetry Virtual Environment

```bash
# Navigate to service directory
cd pbn-detector

# Install dependencies and create virtual environment
# Poetry will automatically create a virtual environment for this project
poetry install

# Activate the virtual environment
poetry shell
```

**Note:** Poetry automatically creates and manages a virtual environment for this project. When you run `poetry install`, it will:
- Create a virtual environment in Poetry's cache directory (typically `~/.cache/pypoetry/virtualenvs/`)
- Install all dependencies specified in `pyproject.toml`
- Set up the project in isolated environment

#### 3. Verify Installation

```bash
# Check that you're in the virtual environment (you should see the env path)
poetry env info

# Verify Python version
python --version  # Should be 3.11+

# Verify dependencies are installed
poetry show
```

**Alternative: Run commands without activating shell**

If you prefer not to activate the virtual environment, you can run commands directly using `poetry run`:

```bash
poetry run python --version
poetry run uvicorn app.main:app --reload
```

### Running Locally

#### Option 1: With Activated Virtual Environment

```bash
# Navigate to pbn-detector directory
cd pbn-detector

# Activate the Poetry virtual environment (if not already activated)
poetry shell

# Development mode with auto-reload
uvicorn app.main:app --reload --host 0.0.0.0 --port 8000

# Production mode
uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 4
```

#### Option 2: Using Poetry Run (No Activation Required)

```bash
# Navigate to pbn-detector directory
cd pbn-detector

# Development mode with auto-reload
poetry run uvicorn app.main:app --reload --host 0.0.0.0 --port 8000

# Production mode
poetry run uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 4
```

**Managing the Virtual Environment:**

```bash
# View virtual environment information
poetry env info

# List all virtual environments
poetry env list

# Remove the virtual environment (if needed)
poetry env remove python3.11

# Recreate virtual environment
poetry install
```

**Note:** The service loads environment variables from the `.env` file in the current working directory. If your `.env` file is in the root directory, you may need to run the service from the root directory, or ensure environment variables are accessible from where you're running the service.

## Configuration

Configuration is managed via environment variables and Pydantic settings.

### Environment Variables

The service reads environment variables from a `.env` file. Since your `.env` file is located in the **root directory** of the project (one level up from `pbn-detector`), you have a few options:

1. **Run from root directory**: Run the service from the root directory so it can access the `.env` file
2. **Set environment variables directly**: Export variables in your shell before running
3. **Symlink or copy**: Create a symlink or copy the `.env` file to the `pbn-detector` directory

Add the following PBN detector variables to your root `.env` file:

```bash
# Application
APP_NAME=PBN Detector
ENVIRONMENT=production
LOG_LEVEL=INFO

# Redis Cache (optional)
REDIS_URL=redis://localhost:6379/0

# External APIs (optional)
WHOIS_BASE_URL=https://www.whoisxmlapi.com/whoisserver/WhoisService
WHOIS_API_KEY=your_whois_api_key
IPINFO_TOKEN=your_ipinfo_token

# Model Configuration
CLASSIFIER_MODEL_PATH=models/pbn_lr.joblib

# Detection Thresholds
MINHASH_THRESHOLD=0.8
HIGH_RISK_THRESHOLD=0.75
MEDIUM_RISK_THRESHOLD=0.5

# Feature Flags
USE_ENSEMBLE=true
USE_ENHANCED_FEATURES=true
USE_PARALLEL_PROCESSING=true
PARALLEL_WORKERS=4
```

### Configuration Options

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `APP_NAME` | string | "PBN Detector" | Application name |
| `ENVIRONMENT` | string | "development" | Environment (development/production) |
| `LOG_LEVEL` | string | "INFO" | Logging level |
| `REDIS_URL` | string | None | Redis connection URL (optional) |
| `WHOIS_BASE_URL` | string | - | WHOIS API base URL (optional) |
| `WHOIS_API_KEY` | string | None | WHOIS API key (optional) |
| `IPINFO_TOKEN` | string | None | IPInfo API token (optional) |
| `CLASSIFIER_MODEL_PATH` | string | "models/pbn_lr.joblib" | Path to trained ML model |
| `MINHASH_THRESHOLD` | float | 0.8 | Content similarity threshold |
| `HIGH_RISK_THRESHOLD` | float | 0.75 | High risk probability threshold |
| `MEDIUM_RISK_THRESHOLD` | float | 0.5 | Medium risk probability threshold |
| `USE_ENSEMBLE` | bool | true | Enable ensemble classifier |
| `USE_ENHANCED_FEATURES` | bool | true | Enable enhanced feature extraction |
| `USE_PARALLEL_PROCESSING` | bool | true | Enable parallel processing |
| `PARALLEL_WORKERS` | int | 4 | Number of parallel workers |

## Performance Optimizations

### Algorithmic Optimizations

1. **Precomputed Network Features**
   - Network statistics computed once: O(n)
   - Per-backlink lookups: O(1) instead of O(n)
   - Reduces complexity from O(n²) to O(n) for large datasets

2. **MinHash LSH**
   - Content similarity: O(n log n) instead of O(n²)
   - Locality-Sensitive Hashing for efficient duplicate detection

3. **Binary Search Lookups**
   - Lightweight classifier uses O(log n) threshold checks
   - Precomputed lookup tables for fast scoring

4. **Set-Based Operations**
   - Anchor keyword matching: O(1) set intersection
   - Cached regex patterns for domain analysis

5. **Parallel Processing**
   - Automatic parallelization for datasets >50 backlinks
   - Async/await for concurrent backlink processing

### Caching Strategy

- **MinHash Objects**: Cached in Redis (2-hour TTL)
- **Domain Patterns**: Cached in Redis (24-hour TTL)
- **In-Memory Cache**: Fallback when Redis unavailable

### Performance Characteristics

- **Small Datasets** (<50 backlinks): Sequential processing, ~50-100ms
- **Medium Datasets** (50-1000 backlinks): Parallel processing, ~100-500ms
- **Large Datasets** (>1000 backlinks): Optimized parallel processing, ~500-2000ms

*Performance varies based on feature flags, model availability, and cache hit rates*

## Testing

### Unit Tests

```bash
# Run all tests
poetry run pytest

# Run with coverage
poetry run pytest --cov=app --cov-report=html

# Run specific test file
poetry run pytest tests/test_detector.py
```

### Integration Testing

Test with real payload structure:

```bash
# Run test script
python test_real_payload.py
```

### Test Payload Example

See `test_real_payload.py` for example payload structure matching Laravel integration.

## Deployment

### Production Considerations

1. **Model Deployment**
   - Ensure trained model file is available at `CLASSIFIER_MODEL_PATH`
   - Model is loaded lazily on first prediction
   - Falls back to lightweight classifier if model unavailable

2. **Redis Configuration**
   - Recommended for production (improves performance)
   - Service works without Redis (caching disabled)
   - Configure appropriate TTL values

3. **Scaling**
   - Stateless service (horizontally scalable)
   - Use load balancer for multiple instances
   - Shared Redis for cache consistency

4. **Monitoring**
   - Monitor `/health` endpoint
   - Track latency metrics (`meta.latency_ms`)
   - Monitor error rates and fallback usage

5. **Logging**
   - Structured logging with Loguru
   - Log level configurable via `LOG_LEVEL`
   - Error tracking with full stack traces

### Health Checks

The service exposes a `/health` endpoint for monitoring:

```bash
curl http://localhost:8000/health
```

### Error Handling

- **Graceful Degradation**: All components have fallback mechanisms
- **Error Logging**: Comprehensive error logging with context
- **HTTP Status Codes**: Proper status codes (400, 500) for client/server errors

## Architecture Decisions

### Why FastAPI?

- **High Performance**: Async/await support for concurrent requests
- **Type Safety**: Pydantic models for request/response validation
- **Auto Documentation**: OpenAPI/Swagger documentation
- **Modern Python**: Python 3.11+ features

### Why Multi-Layered Detection?

- **Accuracy**: Combining multiple signals improves detection accuracy
- **Robustness**: Fallback mechanisms ensure service availability
- **Flexibility**: Can enable/disable features based on requirements

### Why Precomputed Features?

- **Performance**: O(n) complexity instead of O(n²) for large datasets
- **Scalability**: Handles thousands of backlinks efficiently
- **Memory Efficiency**: Shared network statistics reduce memory usage

## Contributing

When contributing to this service:

1. Maintain backward compatibility with API contracts
2. Add tests for new features
3. Update this README for significant changes
4. Follow existing code style and patterns
5. Document performance implications

## License

MIT License - See LICENSE file for details

## Support

For issues, questions, or contributions, please contact the development team.

---

**Version**: 0.1.0  
**Last Updated**: 2025-01-15  
**Maintainer**: Luminiaeo Platform Team
