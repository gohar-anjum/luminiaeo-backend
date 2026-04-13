"""
Model singletons - load once at startup, never reinitialize.
SentenceTransformer and KeyBERT are heavy; reuse across requests.
"""
from sentence_transformers import SentenceTransformer
from keybert import KeyBERT

from app.core.pipeline_log import log_step

_embedding_model: SentenceTransformer | None = None
_kw_model: KeyBERT | None = None


def get_embedding_model() -> SentenceTransformer:
    """Get or load the SentenceTransformer model (singleton)."""
    global _embedding_model
    if _embedding_model is None:
        log_step("models_embedding_load_start", model="sentence-transformers/all-MiniLM-L6-v2")
        _embedding_model = SentenceTransformer("all-MiniLM-L6-v2")
        log_step("models_embedding_load_done", model="sentence-transformers/all-MiniLM-L6-v2")
    return _embedding_model


def get_keybert_model() -> KeyBERT:
    """Get or load KeyBERT wrapped around the embedding model (singleton)."""
    global _kw_model
    if _kw_model is None:
        log_step("models_keybert_load_start")
        _kw_model = KeyBERT(get_embedding_model())
        log_step("models_keybert_load_done")
    return _kw_model
