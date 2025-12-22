from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Optional
import numpy as np
from sklearn.cluster import KMeans
from sklearn.metrics import silhouette_score
from sentence_transformers import SentenceTransformer
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Keyword Clustering & Intent Analysis Service", version="2.0.0")

model = None

class KeywordMetadata(BaseModel):
    keyword: str
    search_volume: Optional[int] = None
    competition: Optional[float] = None
    cpc: Optional[float] = None
    difficulty: Optional[int] = None
    intent: Optional[str] = None

class ClusterRequest(BaseModel):
    keywords: List[str] = Field(..., description="List of keywords to cluster")
    keyword_metadata: Optional[Dict[str, Dict]] = Field(
        None,
        description="Optional metadata for keywords (search_volume, competition, etc.)"
    )
    num_clusters: Optional[int] = Field(None, description="Number of clusters (auto if None)")
    min_cluster_size: int = Field(3, ge=2, description="Minimum keywords per cluster")
    optimize_clusters: bool = Field(True, description="Auto-optimize cluster count using silhouette score")

class ClusterAnalysis(BaseModel):
    cluster_id: int
    topic_name: str
    keywords: List[str]
    keyword_count: int
    avg_search_volume: Optional[float] = None
    avg_competition: Optional[float] = None
    avg_cpc: Optional[float] = None
    avg_difficulty: Optional[float] = None
    dominant_intent: Optional[str] = None
    intent_distribution: Dict[str, int] = Field(default_factory=dict)
    quality_score: float = Field(..., description="Cluster quality score (0-100)")
    semantic_coherence: float = Field(..., description="How semantically similar keywords are (0-1)")

class ClusteringResponse(BaseModel):
    cluster_map: Dict[str, int] = Field(..., description="Keyword to cluster ID mapping")
    clusters: List[ClusterAnalysis] = Field(..., description="Detailed cluster analysis")
    num_clusters: int = Field(..., description="Actual number of clusters created")
    silhouette_score: Optional[float] = Field(None, description="Overall clustering quality score")
    recommendations: Dict[str, any] = Field(default_factory=dict, description="Recommendations for keyword strategy")

@app.on_event("startup")
async def load_model():
    global model
    try:
        logger.info("Loading sentence transformer model...")

        model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")
        logger.info("Model loaded successfully")
    except Exception as e:
        logger.error(f"Failed to load model: {e}")
        raise

def generate_embeddings(keywords: List[str]) -> np.ndarray:
    if model is None:
        raise RuntimeError("Model not loaded")
    return model.encode(keywords)