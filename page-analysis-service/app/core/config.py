import os
import re

from dotenv import load_dotenv

load_dotenv()


def sanitize_plain_text(value: str | None) -> str:
    """Strip NUL/C0 controls so HTML never confuses multimodal embedding stacks."""
    if not value:
        return ""
    cleaned = value.replace("\x00", " ")
    cleaned = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", " ", cleaned)
    return re.sub(r"[ \t]+", " ", cleaned).strip()


class Settings:
    REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379")
    REQUEST_TIMEOUT = 15
    MAX_CONTENT_SIZE_MB = 5
    CACHE_TTL = int(os.getenv("CACHE_TTL", 86400))  # 24 hours
    MAX_TOKENS = 100000  # Limit text for embedding to prevent explosion

settings = Settings()
