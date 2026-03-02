from app.core.models import get_keybert_model


def extract_keywords(text: str, headings: list) -> list[dict]:
    """Extract keywords using KeyBERT (wraps embedding model)."""
    kw_model = get_keybert_model()
    weighted_text = text + " " + " ".join(headings * 2)

    keywords = kw_model.extract_keywords(
        weighted_text,
        keyphrase_ngram_range=(1, 3),
        stop_words="english",
        use_mmr=True,
        top_n=10,
    )

    return [{"phrase": k[0], "score": float(k[1])} for k in keywords]
