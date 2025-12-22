# Keyword Clustering Microservice

Python microservice for semantic keyword clustering using K-Means and sentence transformers.

## Features

- ✅ Semantic embeddings using sentence-transformers (MPNet)
- ✅ K-Means clustering on embeddings
- ✅ Automatic cluster labeling
- ✅ FastAPI REST API
- ✅ Health check endpoint

## Setup

### Using Docker (Recommended)

The service is fully dockerized and runs as part of the main docker-compose setup.

**From project root:**

```bash
# Start all services (including clustering)
docker-compose up -d

# Or start just clustering service
docker-compose up -d clustering

# View logs
docker-compose logs -f clustering

# Check health
curl http://localhost:8001/health
```

**Standalone Docker build:**

```bash
cd keyword-clustering-service

# Build image
docker build -t clustering-service .

# Run container
docker run -p 8001:8001 clustering-service
```

### Configure Laravel

Add to `.env`:
```env
KEYWORD_CLUSTERING_SERVICE_URL=http://localhost:8001
KEYWORD_CLUSTERING_TIMEOUT=120
```

## API Endpoints

### POST /cluster

Cluster keywords using K-Means.

**Request**:
```json
{
    "keywords": [
        "car insurance",
        "auto insurance",
        "health insurance",
        "life insurance",
        "home insurance"
    ],
    "num_clusters": 3,
    "model_name": "sentence-transformers/all-mpnet-base-v2"
}
```

**Response**:
```json
{
    "cluster_map": {
        "car insurance": 0,
        "auto insurance": 0,
        "health insurance": 1,
        "life insurance": 1,
        "home insurance": 2
    },
    "cluster_labels": [
        "Car Auto",
        "Health Life",
        "Home"
    ],
    "num_clusters": 3,
    "cluster_sizes": {
        "0": 2,
        "1": 2,
        "2": 1
    }
}
```

### GET /health

Health check endpoint.

**Response**:
```json
{
    "status": "healthy",
    "model_loaded": true
}
```

## How It Works

1. **Embeddings Generation**: Uses `sentence-transformers/all-mpnet-base-v2` to convert keywords into 768-dimensional vectors
2. **K-Means Clustering**: Applies K-Means algorithm on the embedding space
3. **Cluster Labeling**: Finds representative keywords for each cluster and generates labels

## Performance

- Model loading: ~2-3 seconds (one-time on startup)
- Embedding generation: ~0.1-0.5 seconds per 100 keywords
- Clustering: ~0.01-0.1 seconds for typical datasets

## Docker Configuration

The service is containerized with:
- Python 3.11 base image
- All dependencies pre-installed
- Model directory mounted as volume
- Health checks configured
- Automatic model loading on startup

## Training Custom Model

The service supports custom trained models for better keyword clustering accuracy. **All training is done via Docker - no virtual environments needed!**

### Quick Start

```bash
# From project root directory

# 1. Prepare training data
docker-compose run --rm clustering-train python prepare_dataset_for_training.py \
    /app/data/dataset_10000000_pairs.json \
    --output /app/data/training_pairs.json \
    --target-pairs 2000000

# 2. Train model
docker-compose run --rm clustering-train python train_model_complete.py \
    /app/data/training_pairs.json

# 3. Restart service to load new model
docker-compose restart clustering
```

### Training Files

- `train_model_complete.py` - Complete non-interactive training script
- `prepare_dataset_for_training.py` - Comprehensive data preparation script
- `prepare_training_data.py` - Alternative data preparation with JSON/CSV support
- `TRAINING_GUIDE.md` - Complete step-by-step Docker-based training guide

### Model Deployment

- Trained models are saved to `./models/custom-keyword-clustering/`
- Service automatically detects and loads custom model on startup
- Set `CUSTOM_MODEL_PATH` environment variable if using custom location
- Model directory is mounted as volume in Docker

## Notes

- First request will be slower as the model downloads (if not cached)
- Model is loaded once and cached in memory
- Supports up to 10,000 keywords per request (adjustable)
- Default model: `all-mpnet-base-v2` (768 dimensions, good balance of speed/quality)
- Custom models are automatically detected if present in the models directory

