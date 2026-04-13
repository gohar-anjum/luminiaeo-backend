import logging

from app.core.models import get_keybert_model
from app.core.pipeline_log import log_step

logger = logging.getLogger(__name__)


def extract_keywords(text: str, headings: list) -> list[dict]:
    """Extract keywords using KeyBERT (wraps embedding model)."""
    log_step(
        "04_keybert_inner_start",
        text_chars=len(text or ""),
        headings_count=len(headings or []),
    )
    kw_model = get_keybert_model()
    weighted_text = (text + " " + " ".join(headings * 2)).strip()
    if not weighted_text:
        log_step("04_keybert_inner_skip", reason="empty_weighted_text")
        return []

    try:
        keywords = kw_model.extract_keywords(
            weighted_text,
            keyphrase_ngram_range=(1, 3),
            stop_words="english",
            use_mmr=True,
            top_n=10,
        )
    except ValueError as e:
        # CountVectorizer: "After pruning, no terms remain" on very thin/stopword-only text.
        logger.info("KeyBERT skipped for document: %s", e)
        log_step("04_keybert_inner_fail", reason="value_error", error=str(e))
        return []

    out = [{"phrase": k[0], "score": float(k[1])} for k in keywords]
    log_step("04_keybert_inner_done", keyword_count=len(out))
    return out
