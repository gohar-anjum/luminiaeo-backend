import os
from dotenv import load_dotenv

load_dotenv()

class Settings:
    REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379")
    REQUEST_TIMEOUT = 10
    MAX_CONTENT_SIZE_MB = 2
    CACHE_TTL = 3600

settings = Settings()
