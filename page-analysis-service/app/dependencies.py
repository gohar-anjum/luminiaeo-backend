from sentence_transformers import SentenceTransformer
from keybert import KeyBERT

embedding_model = SentenceTransformer("all-MiniLM-L6-v2")
kw_model = KeyBERT(embedding_model)
