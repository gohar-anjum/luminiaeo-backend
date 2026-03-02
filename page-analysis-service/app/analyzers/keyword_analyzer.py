from app.dependencies import kw_model

def extract_keywords(text: str, headings: list):
    weighted_text = text + " ".join(headings * 2)

    keywords = kw_model.extract_keywords(
        weighted_text,
        keyphrase_ngram_range=(1,3),
        stop_words="english",
        use_mmr=True,
        top_n=10
    )

    return [{"phrase": k[0], "score": float(k[1])} for k in keywords]
    from app.services.cache import generate_cache_key, get_cache, set_cache
    import time
    import logging

    logger = logging.getLogger(__name__)

    async def analyze_page(request):

        cache_key = generate_cache_key(
            request.url,
            request.analysis,
            request.compare_to
        )

        cached = await get_cache(cache_key)
        if cached:
            cached["cached"] = True
            return cached

        start_time = time.time()

        html = await fetch_html(request.url)
        content = extract_content(html)

        analysis_result = {}

        if "keywords" in request.analysis:
            analysis_result["keywords"] = extract_keywords(
                content["text"],
                content["headings"]
            )

        embedding = None

        if "embedding" in request.analysis or "semantic_score" in request.analysis:
            embedding = generate_embedding(content["text"])
            analysis_result["embedding"] = embedding

        if "semantic_score" in request.analysis and request.compare_to:
            compare_embedding = generate_embedding(request.compare_to)
            score = compute_similarity(embedding, compare_embedding)
            analysis_result["semantic_score"] = score

        result = {
            "url": request.url,
            "meta": {
                "title": content["title"],
                "description": content["description"]
            },
            "content": {
                "word_count": content["word_count"],
                "headings": content["headings"]
            },
            "analysis": analysis_result,
            "cached": False
        }

        await set_cache(cache_key, result)

        elapsed = time.time() - start_time
        logger.info(f"Analysis completed in {elapsed:.2f}s")

        return result
