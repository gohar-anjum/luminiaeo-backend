"""
Embedding generator with text size guardrail.
Limits processed text to ~7000 tokens to prevent embedding explosion.
all-MiniLM-L6-v2 uses ~256 tokens per 1000 chars; ~4 chars per token.
"""
from app.core.config import settings
from app.core.models import get_embedding_model
from app.core.pipeline_log import log_step

# Approximate: 1 token ~= 4 chars for English
CHARS_PER_TOKEN = 4
MAX_CHARS = settings.MAX_TOKENS * CHARS_PER_TOKEN


def truncate_for_embedding(text: str) -> str:
    """Truncate text to stay within token limit."""
    if not text:
        return ""
    if len(text) <= MAX_CHARS:
        return text
    return text[:MAX_CHARS].rsplit(" ", 1)[0] or text[:MAX_CHARS]


def generate_embedding(text: str, *, log_label: str | None = None) -> list[float]:
    """Generate embedding for text, with size guardrail."""
    empty_source = not (text or "").strip()
    truncated = truncate_for_embedding(text).strip()
    if not truncated:
        truncated = "."
    if log_label:
        log_step(
            "07_embedding_encode",
            label=log_label,
            char_len=len(truncated),
            empty_source=empty_source,
        )
    model = get_embedding_model()
    return model.encode(truncated).tolist()
