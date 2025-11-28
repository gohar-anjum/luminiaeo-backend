"""
Keyword Clustering Microservice
Generates semantic embeddings and performs K-Means clustering on keywords
"""

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Optional
import numpy as np
from sklearn.cluster import KMeans
from sentence_transformers import SentenceTransformer
import logging

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Keyword Clustering Service", version="1.0.0")

# Load model once at startup (cached)
model = None


class ClusterRequest(BaseModel):
    keywords: List[str] = Field(..., description="List of keywords to cluster")
    num_clusters: int = Field(5, ge=2, le=50, description="Number of clusters to create")
    model_name: Optional[str] = Field(
        "sentence-transformers/all-mpnet-base-v2",
        description="Sentence transformer model name"
    )


class ClusterResponse(BaseModel):
    cluster_map: Dict[str, int] = Field(..., description="Keyword to cluster ID mapping")
    cluster_labels: List[str] = Field(..., description="Human-readable cluster labels")
    num_clusters: int = Field(..., description="Actual number of clusters created")
    cluster_sizes: Dict[int, int] = Field(..., description="Number of keywords per cluster")


@app.on_event("startup")
async def load_model():
    """Load the sentence transformer model on startup"""
    global model
    try:
        logger.info("Loading sentence transformer model...")
        model = SentenceTransformer("sentence-transformers/all-mpnet-base-v2")
        logger.info("Model loaded successfully")
    except Exception as e:
        logger.error(f"Failed to load model: {e}")
        raise


def generate_embeddings(keywords: List[str]) -> np.ndarray:
    """
    Generate semantic embeddings for keywords using sentence transformers
    
    Args:
        keywords: List of keyword strings
        
    Returns:
        numpy array of embeddings (n_keywords, embedding_dim)
    """
    if model is None:
        raise HTTPException(status_code=500, detail="Model not loaded")
    
    logger.info(f"Generating embeddings for {len(keywords)} keywords")
    embeddings = model.encode(keywords, show_progress_bar=False)
    return embeddings


def perform_kmeans(embeddings: np.ndarray, num_clusters: int) -> np.ndarray:
    """
    Perform K-Means clustering on embeddings
    
    Args:
        embeddings: numpy array of embeddings
        num_clusters: number of clusters to create
        
    Returns:
        numpy array of cluster labels
    """
    # Adjust num_clusters if we have fewer keywords
    actual_clusters = min(num_clusters, len(embeddings))
    
    logger.info(f"Performing K-Means clustering with {actual_clusters} clusters")
    
    kmeans = KMeans(
        n_clusters=actual_clusters,
        random_state=42,
        n_init=10,
        max_iter=300
    )
    
    cluster_labels = kmeans.fit_predict(embeddings)
    return cluster_labels


def generate_cluster_labels(
    keywords: List[str],
    cluster_labels: np.ndarray,
    embeddings: np.ndarray
) -> List[str]:
    """
    Generate human-readable labels for clusters
    
    Strategy:
    1. Find the keyword closest to cluster center
    2. Use that keyword as the label (or extract main terms)
    
    Args:
        keywords: List of keywords
        cluster_labels: Cluster assignments
        embeddings: Keyword embeddings
        
    Returns:
        List of cluster labels
    """
    num_clusters = len(set(cluster_labels))
    labels = []
    
    for cluster_id in range(num_clusters):
        # Get keywords in this cluster
        cluster_mask = cluster_labels == cluster_id
        cluster_keywords = [keywords[i] for i in range(len(keywords)) if cluster_mask[i]]
        cluster_embeddings = embeddings[cluster_mask]
        
        if not cluster_keywords:
            labels.append(f"Cluster {cluster_id + 1}")
            continue
        
        # Find centroid of cluster
        cluster_center = cluster_embeddings.mean(axis=0)
        
        # Find keyword closest to center
        distances = np.linalg.norm(cluster_embeddings - cluster_center, axis=1)
        closest_idx = np.argmin(distances)
        representative_keyword = cluster_keywords[closest_idx]
        
        # Extract main terms (first 2-3 words, capitalized)
        words = representative_keyword.split()[:3]
        label = " ".join(word.capitalize() for word in words)
        
        labels.append(label)
    
    return labels


@app.post("/cluster", response_model=ClusterResponse)
async def cluster_keywords(request: ClusterRequest):
    """
    Cluster keywords using semantic embeddings and K-Means
    
    Process:
    1. Generate embeddings for all keywords
    2. Apply K-Means clustering
    3. Generate cluster labels
    4. Return results
    """
    try:
        if not request.keywords:
            raise HTTPException(status_code=400, detail="Keywords list cannot be empty")
        
        if len(request.keywords) < 2:
            raise HTTPException(
                status_code=400,
                detail="Need at least 2 keywords for clustering"
            )
        
        logger.info(f"Processing {len(request.keywords)} keywords for {request.num_clusters} clusters")
        
        # Step 1: Generate embeddings
        embeddings = generate_embeddings(request.keywords)
        
        # Step 2: Perform K-Means clustering
        cluster_labels = perform_kmeans(embeddings, request.num_clusters)
        
        # Step 3: Generate cluster labels
        cluster_label_names = generate_cluster_labels(
            request.keywords,
            cluster_labels,
            embeddings
        )
        
        # Step 4: Build response
        cluster_map = {
            keyword: int(cluster_id)
            for keyword, cluster_id in zip(request.keywords, cluster_labels)
        }
        
        # Calculate cluster sizes
        cluster_sizes = {}
        for cluster_id in set(cluster_labels):
            cluster_sizes[int(cluster_id)] = int(np.sum(cluster_labels == cluster_id))
        
        response = ClusterResponse(
            cluster_map=cluster_map,
            cluster_labels=cluster_label_names,
            num_clusters=len(cluster_label_names),
            cluster_sizes=cluster_sizes
        )
        
        logger.info(f"Clustering complete: {response.num_clusters} clusters created")
        
        return response
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Clustering error: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Clustering failed: {str(e)}")


@app.get("/health")
async def health_check():
    """Health check endpoint"""
    return {
        "status": "healthy",
        "model_loaded": model is not None
    }


@app.get("/")
async def root():
    """Root endpoint"""
    return {
        "service": "Keyword Clustering Microservice",
        "version": "1.0.0",
        "endpoints": {
            "POST /cluster": "Cluster keywords using K-Means",
            "GET /health": "Health check"
        }
    }

