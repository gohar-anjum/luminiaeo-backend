"""Service configuration."""
import os

# Max keywords per request (input cap)
MAX_KEYWORDS_INPUT = 1000

# Number of top keywords to return
TOP_N_INFORMATIONAL = 100

# spaCy model name (small English model)
SPACY_MODEL = os.getenv("SPACY_MODEL", "en_core_web_sm")

# Server
HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8002"))
