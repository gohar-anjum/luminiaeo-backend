from app.services.fetcher import fetch_html
from app.services.extractor import extract_content
from app.analyzers.keyword_analyzer import extract_keywords
from app.analyzers.embedding_generator import generate_embedding
from app.analyzers.semantic_scorer import compute_similarity

async def analyze_page(request):

    html = await fetch_html(request.url)
    content = extract_content(html)

    analysis_result = {}

    if "keywords" in request.analysis:
        analysis_result["keywords"] = extract_keywords(
            content["text"],
            content["headings"]
        )

    if "embedding" in request.analysis:
        embedding = generate_embedding(content["text"])
        analysis_result["embedding"] = embedding

        if request.compare_to:
            compare_embedding = generate_embedding(request.compare_to)
            score = compute_similarity(embedding, compare_embedding)
            analysis_result["semantic_score"] = score

    return {
        "url": request.url,
        "meta": {
            "title": content["title"],
            "description": content["description"]
        },
        "content": {
            "word_count": content["word_count"],
            "headings": content["headings"]
        },
        "analysis": analysis_result
    }
    if "semantic_score" in request.analysis:

        if embedding is None:
            embedding = generate_embedding(content["text"])

        if request.compare_to:
            compare_embedding = generate_embedding(request.compare_to)
            score = compute_similarity(embedding, compare_embedding)
            analysis_result["semantic_score"] = score

        elif request.compare_url:
            compare_html = await fetch_html(request.compare_url)
            compare_content = extract_content(compare_html)
            compare_embedding = generate_embedding(compare_content["text"])
            score = compute_similarity(embedding, compare_embedding)
            analysis_result["semantic_score"] = score

        else:
            raise ValueError("semantic_score requires compare_to or compare_url")
