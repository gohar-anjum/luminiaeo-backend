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
    cache_key = generate_cache_key(
        url=str(request.url),
        analysis=request.analysis,
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
        # Ensure we have keywords to derive primary topic
        if "keywords" not in analysis_result:
            analysis_result["keywords"] = extract_keywords(
                content["text"],
                content["headings"],
            )

        keywords = analysis_result["keywords"] or []
        if keywords:
            first = keywords[0]
            primary_keyword = first["phrase"] if isinstance(first, dict) else str(first)
        else:
            # Fallbacks if keyword extraction fails
            primary_keyword = content["title"] or (content["headings"][0] if content["headings"] else "page")

        keyword_embedding = generate_embedding(primary_keyword)

        if page_embedding is None:
            page_embedding = generate_embedding(content["text"])

        score = compute_similarity(page_embedding, keyword_embedding)
        analysis_result["semantic_score"] = score
        analysis_result["primary_keyword"] = primary_keyword

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
