"""
Main analysis orchestrator.
Uses Redis cache, supports keywords, embedding, semantic_score, intent.
"""
import logging
from app.services.fetcher import fetch_html
from app.services.extractor import extract_content
from app.services.cache import generate_cache_key, get_cache, set_cache
from app.analyzers.keyword_analyzer import extract_keywords
from app.analyzers.embedding_generator import generate_embedding
from app.analyzers.semantic_scorer import compute_similarity
from app.analyzers.intent_classifier import classify_intent

logger = logging.getLogger(__name__)


async def analyze_page(request) -> dict:
    """Orchestrate page analysis with cache, all analysis types, and intent."""
    user_keyword = getattr(request, "keyword", None) or ""
    cache_key = generate_cache_key(
        url=str(request.url),
        analysis=request.analysis,
        extra=user_keyword.strip().lower() if user_keyword else "",
    )

    cached = await get_cache(cache_key)
    if cached:
        cached["cached"] = True
        return cached

    html = await fetch_html(str(request.url))
    content = extract_content(html)

    analysis_result: dict = {}

    # Keywords (required for meta optimizer and semantic score)
    if "keywords" in request.analysis or "semantic_score" in request.analysis:
        analysis_result["keywords"] = extract_keywords(
            content["text"],
            content["headings"],
        )

    # Intent (required for meta optimizer)
    combined_text = content["text"] + " " + " ".join(content["headings"])
    analysis_result["intent"] = classify_intent(combined_text)

    # Embedding and semantic score
    page_embedding = None
    if "embedding" in request.analysis or "semantic_score" in request.analysis:
        page_embedding = generate_embedding(content["text"])
        if "embedding" in request.analysis:
            analysis_result["embedding"] = page_embedding

    if "semantic_score" in request.analysis:
        if "keywords" not in analysis_result:
            analysis_result["keywords"] = extract_keywords(
                content["text"],
                content["headings"],
            )

        keywords = analysis_result["keywords"] or []

        # Use user-provided keyword as primary if supplied
        user_keyword = getattr(request, "keyword", None)
        if user_keyword and user_keyword.strip():
            primary_keyword = user_keyword.strip()
        elif keywords:
            first = keywords[0]
            primary_keyword = first["phrase"] if isinstance(first, dict) else str(first)
        else:
            primary_keyword = content["title"] or (content["headings"][0] if content["headings"] else "page")

        if page_embedding is None:
            page_embedding = generate_embedding(content["text"])

        keyword_embedding = generate_embedding(primary_keyword)
        score = compute_similarity(page_embedding, keyword_embedding)
        analysis_result["semantic_score"] = score
        analysis_result["primary_keyword"] = primary_keyword

        # Per-keyword scores for all extracted keywords
        keyword_scores = []
        for kw in keywords:
            phrase = kw["phrase"] if isinstance(kw, dict) else str(kw)
            kw_score = kw.get("score", 0) if isinstance(kw, dict) else 0
            kw_embedding = generate_embedding(phrase)
            semantic = compute_similarity(page_embedding, kw_embedding)
            keyword_scores.append({
                "phrase": phrase,
                "extraction_score": round(kw_score, 4),
                "semantic_score": round(semantic, 4),
            })

        # Include user keyword in the scores if not already present
        if user_keyword and user_keyword.strip():
            existing_phrases = {s["phrase"].lower() for s in keyword_scores}
            if primary_keyword.lower() not in existing_phrases:
                keyword_scores.insert(0, {
                    "phrase": primary_keyword,
                    "extraction_score": 0,
                    "semantic_score": round(score, 4),
                })

        keyword_scores.sort(key=lambda x: x["semantic_score"], reverse=True)
        analysis_result["keyword_scores"] = keyword_scores

    result = {
        "url": str(request.url),
        "meta": {
            "title": content["title"],
            "description": content["description"],
        },
        "content": {
            "word_count": content["word_count"],
            "headings": content["headings"],
        },
        "analysis": analysis_result,
        "cached": False,
    }

    await set_cache(cache_key, result)
    return result
