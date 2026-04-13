import os
from dotenv import load_dotenv

load_dotenv()

class Settings:
    REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379")
    REQUEST_TIMEOUT = 15
    MAX_CONTENT_SIZE_MB = 5
    CACHE_TTL = int(os.getenv("CACHE_TTL", 86400))  # 24 hours
    MAX_TOKENS = 100000  # Limit text for embedding to prevent explosion

settings = Settings()
