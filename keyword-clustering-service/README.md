# Keyword Clustering Microservice

Python microservice for semantic keyword clustering using K-Means and sentence transformers.

## Features

- ✅ Semantic embeddings using sentence-transformers (MPNet)
- ✅ K-Means clustering on embeddings
- ✅ Automatic cluster labeling
- ✅ FastAPI REST API
- ✅ Health check endpoint

## Setup

### 1. Install Dependencies

```bash
cd keyword-clustering-service
pip install -r requirements.txt
```

### 2. Run the Service

```bash
# Development
uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload

# Production
uvicorn app.main:app --host 0.0.0.0 --port 8001 --workers 4
```

### 3. Configure Laravel

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

## Docker Support (Optional)

```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app/ ./app/

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
```

## Notes

- First request will be slower as the model downloads (if not cached)
- Model is loaded once and cached in memory
- Supports up to 10,000 keywords per request (adjustable)
- Default model: `all-mpnet-base-v2` (768 dimensions, good balance of speed/quality)

