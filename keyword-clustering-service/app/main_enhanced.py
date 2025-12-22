from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from typing import List, Dict, Optional
import numpy as np
from sklearn.cluster import KMeans
from sklearn.feature_extraction.text import TfidfVectorizer
from sentence_transformers import SentenceTransformer
import re
import logging
from collections import Counter
from itertools import combinations

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Enhanced Keyword Clustering Service", version="2.0.0")

model = None

class ClusterRequest(BaseModel):
    keywords: List[str] = Field(..., description="List of keywords to cluster")
    num_clusters: int = Field(5, ge=2, le=50, description="Number of clusters to create")
    model_name: Optional[str] = Field(
        "sentence-transformers/all-mpnet-base-v2",
        description="Sentence transformer model name"
    )
    include_metadata: bool = Field(True, description="Include article titles, questions, etc.")
    keyword_metadata: Optional[Dict[str, Dict]] = Field(
        None,
        description="Optional metadata for keywords (question_variations, etc.)"
    )

class ClusterMetadata(BaseModel):
    topic_name: str
    description: Optional[str] = None
    suggested_article_titles: List[str] = Field(default_factory=list)
    recommended_faq_questions: List[str] = Field(default_factory=list)
    schema_suggestions: List[str] = Field(default_factory=list)
    ai_visibility_projection: Optional[float] = None
    keyword_count: int

class EnhancedClusterResponse(BaseModel):
    cluster_map: Dict[str, int] = Field(..., description="Keyword to cluster ID mapping")
    cluster_labels: List[str] = Field(..., description="Human-readable cluster labels")
    num_clusters: int = Field(..., description="Actual number of clusters created")
    cluster_sizes: Dict[int, int] = Field(..., description="Number of keywords per cluster")
    clusters: List[ClusterMetadata] = Field(..., description="Detailed cluster metadata")

class ClusterResponse(BaseModel):
    cluster_map: Dict[str, int]
    cluster_labels: List[str]
    num_clusters: int
    cluster_sizes: Dict[int, int]

@app.on_event("startup")
async def load_model():
    global model
    try:
        logger.info("Loading sentence transformer model...")
        model = SentenceTransformer("sentence-transformers/all-mpnet-base-v2")
        logger.info("Model loaded successfully")
    except Exception as e:
        logger.error(f"Failed to load model: {e}")
        raise

def generate_embeddings(keywords: List[str]) -> np.ndarray:
    if model is None:
        raise RuntimeError("Model not loaded")
    return model.encode(keywords)