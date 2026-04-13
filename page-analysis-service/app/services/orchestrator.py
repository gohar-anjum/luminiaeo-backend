"""
Main analysis orchestrator.
Uses Redis cache, supports keywords, embedding, semantic_score, intent.
"""
import logging
import time

from app.analyzers.embedding_generator import generate_embedding
from app.analyzers.intent_classifier import classify_intent
from app.analyzers.keyword_analyzer import extract_keywords
from app.analyzers.semantic_scorer import compute_similarity
from app.core.pipeline_log import log_duration, log_step
from app.services.cache import generate_cache_key, get_cache, set_cache
from app.services.extractor import extract_content
from app.services.fetcher import fetch_html

logger = logging.getLogger(__name__)


async def analyze_page(request) -> dict:
    """Orchestrate page analysis with cache, all analysis types, and intent."""
    url = str(request.url)
    analysis_types = list(request.analysis)
    user_keyword = getattr(request, "keyword", None) or ""

    log_step(
        "00_pipeline_start",
        url=url,
        analysis=",".join(sorted(analysis_types)),
        has_user_keyword=bool(user_keyword.strip()),
    )

    cache_key = generate_cache_key(
        url=url,
        analysis=request.analysis,
        extra=user_keyword.strip().lower() if user_keyword else "",
    )
    log_step("00_cache_key", cache_key_tail=cache_key[-16:])

    cached = await get_cache(cache_key)
    if cached:
        cached["cached"] = True
        log_step("00_pipeline_done", path="cache_hit", url=url)
        return cached

    log_step("00_cache_continue", path="compute_fresh")

    html = await fetch_html(url)
    content = extract_content(html)

    analysis_result: dict = {}

    if "keywords" in request.analysis or "semantic_score" in request.analysis:
        log_step("04_keywords_phase_start", url=url)
        analysis_result["keywords"] = extract_keywords(
            content["text"],
            content["headings"],
        )
        log_step(
            "04_keywords_phase_done",
            count=len(analysis_result.get("keywords") or []),
        )

    combined_text = content["text"] + " " + " ".join(content["headings"])
    log_step("05_intent_phase_start", combined_text_chars=len(combined_text))
    analysis_result["intent"] = classify_intent(combined_text)
    log_step("05_intent_phase_done", intent=analysis_result["intent"])

    page_embedding = None
    if "embedding" in request.analysis or "semantic_score" in request.analysis:
        log_step("06_page_embedding_start", need_embedding="embedding" in request.analysis)
        page_embedding = generate_embedding(content["text"], log_label="page_body")
        log_step("06_page_embedding_done", dims=len(page_embedding))
        if "embedding" in request.analysis:
            analysis_result["embedding"] = page_embedding

    if "semantic_score" in request.analysis:
        with log_duration("08_semantic_score_block", url=url):
            if "keywords" not in analysis_result:
                log_step("08_semantic_refetch_keywords")
                analysis_result["keywords"] = extract_keywords(
                    content["text"],
                    content["headings"],
                )

            keywords = analysis_result["keywords"] or []

            user_kw = getattr(request, "keyword", None)
            if user_kw and user_kw.strip():
                primary_keyword = user_kw.strip()
            elif keywords:
                first = keywords[0]
                primary_keyword = first["phrase"] if isinstance(first, dict) else str(first)
            else:
                primary_keyword = content["title"] or (
                    content["headings"][0] if content["headings"] else "page"
                )

            log_step(
                "08_semantic_primary_pick",
                primary_keyword=primary_keyword,
                extracted_kw_count=len(keywords),
            )

            if page_embedding is None:
                log_step("08_semantic_page_embed_retry")
                page_embedding = generate_embedding(content["text"], log_label="page_body_retry")

            keyword_embedding = generate_embedding(primary_keyword, log_label="primary_keyword")
            score = compute_similarity(page_embedding, keyword_embedding)
            log_step(
                "08_semantic_primary_score",
                score=round(score, 4),
            )
            analysis_result["semantic_score"] = score
            analysis_result["primary_keyword"] = primary_keyword

            keyword_scores = []
            t_kw = time.perf_counter()
            for i, kw in enumerate(keywords):
                phrase = kw["phrase"] if isinstance(kw, dict) else str(kw)
                kw_score = kw.get("score", 0) if isinstance(kw, dict) else 0
                kw_embedding = generate_embedding(phrase, log_label=f"kw_{i}")
                semantic = compute_similarity(page_embedding, kw_embedding)
                keyword_scores.append(
                    {
                        "phrase": phrase,
                        "extraction_score": round(kw_score, 4),
                        "semantic_score": round(semantic, 4),
                    }
                )
            log_step(
                "08_semantic_keyword_scores_loop_done",
                phrases=len(keywords),
                elapsed_ms=round((time.perf_counter() - t_kw) * 1000, 1),
            )

            if user_kw and user_kw.strip():
                existing_phrases = {s["phrase"].lower() for s in keyword_scores}
                if primary_keyword.lower() not in existing_phrases:
                    keyword_scores.insert(
                        0,
                        {
                            "phrase": primary_keyword,
                            "extraction_score": 0,
                            "semantic_score": round(score, 4),
                        },
                    )

            keyword_scores.sort(key=lambda x: x["semantic_score"], reverse=True)
            analysis_result["keyword_scores"] = keyword_scores
            log_step(
                "08_semantic_done",
                keyword_scores_rows=len(keyword_scores),
            )

    result = {
        "url": url,
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

    log_step("09_result_built", analysis_keys=list(analysis_result.keys()))
    await set_cache(cache_key, result)
    log_step("00_pipeline_done", path="fresh_response", url=url)
    return result
