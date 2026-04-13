"""
When KeyBERT returns no/few phrases, still produce candidates for semantic scoring.
"""
from __future__ import annotations

import re
from collections import Counter
from typing import Any, Iterator

# Light stopword list — only to thin noise for fallback picks, not NLP-grade.
_STOP = frozenset(
    """
    a an the and or but if in on at to for of as is was are were been be
    has have had do does did will would could should may might must can
    this that these those it its we you your our their they them from by
    with without about into over under more most less least very just only
    also not no yes all any each every some such than then so too very
    what which who when where why how up out off down
    """.split()
)


def _norm_token(t: str) -> str:
    return re.sub(r"^[^\w]+|[^\w]+$", "", t.lower())


def _yield_heading_phrases(headings: list[str]) -> Iterator[str]:
    for h in headings or []:
        h = (h or "").strip()
        if 3 <= len(h) <= 120:
            yield h


def _yield_title_phrases(title: str | None) -> Iterator[str]:
    if not title:
        return
    t = title.strip()
    if len(t) >= 3:
        yield t
    tokens = [_norm_token(x) for x in t.split() if len(_norm_token(x)) > 2]
    tokens = [x for x in tokens if x not in _STOP]
    for i in range(len(tokens) - 1):
        yield f"{tokens[i]} {tokens[i + 1]}"


def _yield_description_phrases(description: str | None) -> Iterator[str]:
    if not description:
        return
    d = description.strip()
    if len(d) < 3:
        return
    yield d[:160].rsplit(" ", 1)[0] if len(d) > 160 else d


def _yield_body_unigrams(text: str, max_from_counter: int = 12) -> Iterator[str]:
    if not text:
        return
    sample = text[:6000]
    tokens = [_norm_token(x) for x in sample.split()]
    tokens = [x for x in tokens if len(x) > 2 and x not in _STOP]
    if not tokens:
        return
    for word, _ in Counter(tokens).most_common(max_from_counter):
        yield word


def iter_fallback_candidates(content: dict[str, Any]) -> Iterator[dict[str, float]]:
    """Yield {phrase, score} dicts (score 0 for non-KeyBERT)."""
    seen: set[str] = set()

    def _take(phrase: str) -> str | None:
        p = (phrase or "").strip()
        if len(p) < 3:
            return None
        low = p.lower()
        if low in seen:
            return None
        seen.add(low)
        return p

    for phrase in _yield_heading_phrases(content.get("headings") or []):
        p = _take(phrase)
        if p:
            yield {"phrase": p, "score": 0.0}

    for phrase in _yield_title_phrases(content.get("title")):
        p = _take(phrase)
        if p:
            yield {"phrase": p, "score": 0.0}

    for phrase in _yield_description_phrases(content.get("description")):
        p = _take(phrase)
        if p:
            yield {"phrase": p, "score": 0.0}

    for phrase in _yield_body_unigrams(content.get("text") or ""):
        p = _take(phrase)
        if p:
            yield {"phrase": p, "score": 0.0}


def merge_keywords_with_fallback(
    keywords: list[dict],
    content: dict[str, Any],
    *,
    max_phrases: int = 10,
) -> list[dict]:
    """
    Prefer KeyBERT order/scores first, then fill with fallback phrases up to max_phrases.
    """
    out: list[dict] = [
        dict(k) for k in (keywords or []) if isinstance(k, dict) and k.get("phrase")
    ]
    seen = {k["phrase"].lower() for k in out}
    for item in iter_fallback_candidates(content):
        if len(out) >= max_phrases:
            break
        low = item["phrase"].lower()
        if low in seen:
            continue
        seen.add(low)
        out.append(item)
    return out[:max_phrases]
