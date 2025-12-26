from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Optional
import numpy as np
import os
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
        "model_loaded": model is not None
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
    return model.encode(keywords)