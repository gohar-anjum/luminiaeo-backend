from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Optional
import numpy as np
import os
import hashlib
import pickle
from sklearn.cluster import KMeans
from sentence_transformers import SentenceTransformer
import logging
from collections import Counter
import re
from difflib import SequenceMatcher

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Keyword Clustering Service", version="1.0.0")

model = None
redis_client = None

try:
    import redis
    redis_url = os.getenv('REDIS_URL', os.getenv('REDIS_HOST'))
    if redis_url:
        redis_client = redis.from_url(redis_url) if redis_url.startswith('redis://') else redis.Redis(
            host=os.getenv('REDIS_HOST', 'localhost'),
            port=int(os.getenv('REDIS_PORT', 6379)),
            db=int(os.getenv('REDIS_DB', 0)),
            password=os.getenv('REDIS_PASSWORD'),
            decode_responses=False
        )
        logger.info("Redis client initialized for embedding caching")
except ImportError:
    logger.warning("Redis not available, embedding caching disabled")
except Exception as e:
    logger.warning(f"Redis connection failed: {e}, embedding caching disabled")
    redis_client = None

class ClusterRequest(BaseModel):
    keywords: List[str] = Field(..., description="List of keywords to cluster")
    num_clusters: int = Field(5, ge=2, le=50, description="Number of clusters to create")
    model_name: Optional[str] = Field(
        "sentence-transformers/all-mpnet-base-v2",
        description="Sentence transformer model name"
    )
    use_ml: bool = Field(True, description="Use ML-based clustering (False for rule-based)")

class RuleBasedClusterRequest(BaseModel):
    keywords: List[str] = Field(..., description="List of keywords to cluster")
    num_clusters: Optional[int] = Field(None, ge=2, le=50, description="Number of clusters (auto-determined if None)")
    similarity_threshold: float = Field(0.3, ge=0.0, le=1.0, description="Minimum similarity for same cluster")

class ClusterResponse(BaseModel):
    cluster_map: Dict[str, int] = Field(..., description="Keyword to cluster ID mapping")
    cluster_labels: List[str] = Field(..., description="Human-readable cluster labels")
    num_clusters: int = Field(..., description="Actual number of clusters created")
    cluster_sizes: Dict[int, int] = Field(..., description="Number of keywords per cluster")

@app.get("/health")
async def health_check():
    """Health check endpoint for Docker health checks."""
    return {
        "status": "ok",
        "service": "keyword-clustering",
        "model_loaded": model is not None,
        "redis_available": redis_client is not None
    }

@app.on_event("startup")
async def load_model():
    global model
    try:
        custom_model_path = os.getenv("CUSTOM_MODEL_PATH", "./models/custom-keyword-clustering")

        if os.path.exists(custom_model_path) and os.path.isdir(custom_model_path):
            logger.info(f"Loading custom trained model from {custom_model_path}...")
            model = SentenceTransformer(custom_model_path)
            logger.info("Custom model loaded successfully")
        else:
            logger.info("Custom model not found, loading base model...")
            model_name = os.getenv("MODEL_NAME", "sentence-transformers/all-mpnet-base-v2")
            model = SentenceTransformer(model_name)
            logger.info(f"Base model {model_name} loaded successfully")
    except Exception as e:
        logger.error(f"Failed to load model: {e}")
        raise

def generate_embeddings(keywords: List[str]) -> np.ndarray:
    if model is None:
        raise RuntimeError("Model not loaded")
    
    if redis_client:
        keywords_sorted = sorted(keywords)
        cache_key = f"clustering:embedding:{hashlib.md5(' '.join(keywords_sorted).encode()).hexdigest()}"
        
        try:
            cached = redis_client.get(cache_key)
            if cached:
                logger.debug(f"Cache hit for embeddings: {len(keywords)} keywords")
                return pickle.loads(cached)
        except Exception as e:
            logger.warning(f"Redis cache read failed: {e}")
    
    embeddings = model.encode(keywords)
    
    if redis_client:
        try:
            redis_client.setex(cache_key, 86400, pickle.dumps(embeddings))
            logger.debug(f"Cached embeddings: {len(keywords)} keywords")
        except Exception as e:
            logger.warning(f"Redis cache write failed: {e}")
    
    return embeddings

@app.post("/cluster", response_model=ClusterResponse)
async def cluster_keywords(request: ClusterRequest):
    max_keywords = int(os.getenv('CLUSTERING_MAX_KEYWORDS', '1000'))
    if len(request.keywords) > max_keywords:
        raise HTTPException(
            status_code=400,
            detail=f"Maximum {max_keywords} keywords allowed per request"
        )
    
    if not request.keywords:
        raise HTTPException(status_code=400, detail="Keywords list cannot be empty")
    
    try:
        embeddings = generate_embeddings(request.keywords)
        
        kmeans = KMeans(n_clusters=request.num_clusters, random_state=42, n_init=10, max_iter=300)
        cluster_labels = kmeans.fit_predict(embeddings)
        
        cluster_map = {keyword: int(label) for keyword, label in zip(request.keywords, cluster_labels)}
        
        cluster_sizes = Counter(cluster_labels)
        cluster_labels_list = generate_cluster_labels(cluster_map, request.keywords)
        
        return ClusterResponse(
            cluster_map=cluster_map,
            cluster_labels=cluster_labels_list,
            num_clusters=request.num_clusters,
            cluster_sizes={int(k): int(v) for k, v in cluster_sizes.items()}
        )
    except Exception as e:
        logger.error(f"Clustering failed: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Clustering failed: {str(e)}")

@app.post("/cluster-rule-based", response_model=ClusterResponse)
async def cluster_rule_based(request: RuleBasedClusterRequest):
    max_keywords = int(os.getenv('CLUSTERING_MAX_KEYWORDS', '1000'))
    if len(request.keywords) > max_keywords:
        raise HTTPException(
            status_code=400,
            detail=f"Maximum {max_keywords} keywords allowed per request"
        )
    
    if not request.keywords:
        raise HTTPException(status_code=400, detail="Keywords list cannot be empty")
    
    try:
        cluster_map = {}
        clusters = []
        
        for keyword in request.keywords:
            assigned = False
            for cluster in clusters:
                for cluster_keyword in cluster:
                    similarity = SequenceMatcher(None, keyword.lower(), cluster_keyword.lower()).ratio()
                    if similarity >= request.similarity_threshold:
                        cluster.append(keyword)
                        cluster_map[keyword] = len(clusters) - 1
                        assigned = True
                        break
                if assigned:
                    break
            
            if not assigned:
                clusters.append([keyword])
                cluster_map[keyword] = len(clusters) - 1
        
        if request.num_clusters and len(clusters) > request.num_clusters:
            clusters = merge_smallest_clusters(clusters, request.num_clusters)
            cluster_map = rebuild_cluster_map(clusters)
        
        cluster_labels_list = generate_cluster_labels(cluster_map, request.keywords)
        cluster_sizes = {i: len(cluster) for i, cluster in enumerate(clusters)}
        
        return ClusterResponse(
            cluster_map=cluster_map,
            cluster_labels=cluster_labels_list,
            num_clusters=len(clusters),
            cluster_sizes=cluster_sizes
        )
    except Exception as e:
        logger.error(f"Rule-based clustering failed: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Clustering failed: {str(e)}")

def generate_cluster_labels(cluster_map: Dict[str, int], keywords: List[str]) -> List[str]:
    clusters = {}
    for keyword, cluster_id in cluster_map.items():
        if cluster_id not in clusters:
            clusters[cluster_id] = []
        clusters[cluster_id].append(keyword)
    
    labels = []
    for cluster_id in sorted(clusters.keys()):
        cluster_keywords = clusters[cluster_id]
        words = []
        for keyword in cluster_keywords:
            keyword_words = re.findall(r'\b\w+\b', keyword.lower())
            words.extend([w for w in keyword_words if len(w) > 3])
        
        word_counts = Counter(words)
        top_words = [word for word, _ in word_counts.most_common(2)]
        label = ' '.join(top_words).title() if top_words else f"Cluster {cluster_id + 1}"
        labels.append(label)
    
    return labels

def merge_smallest_clusters(clusters: List[List[str]], target_num: int) -> List[List[str]]:
    while len(clusters) > target_num:
        smallest_idx = min(range(len(clusters)), key=lambda i: len(clusters[i]))
        smallest = clusters.pop(smallest_idx)
        
        if clusters:
            closest_idx = min(
                range(len(clusters)),
                key=lambda i: min(
                    SequenceMatcher(None, kw1.lower(), kw2.lower()).ratio()
                    for kw1 in smallest
                    for kw2 in clusters[i]
                )
            )
            clusters[closest_idx].extend(smallest)
    
    return clusters

def rebuild_cluster_map(clusters: List[List[str]]) -> Dict[str, int]:
    cluster_map = {}
    for cluster_id, cluster in enumerate(clusters):
        for keyword in cluster:
            cluster_map[keyword] = cluster_id
    return cluster_map
