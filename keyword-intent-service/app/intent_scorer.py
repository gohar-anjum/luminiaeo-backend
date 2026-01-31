"""
Informational intent scoring using spaCy.

Scores keywords (0–100) based on signals that indicate informational search intent:
- Question words and interrogative patterns
- Informational lexical markers (guide, tutorial, learn, definition, etc.)
- Sentence structure (questions, length)
- POS and dependency features from spaCy
"""
import re
import logging
from typing import List, Tuple

logger = logging.getLogger(__name__)

# Question words that strongly indicate informational intent
QUESTION_WORDS = frozenset({
    "what", "how", "why", "when", "where", "who", "which", "whose", "whom",
    "can", "could", "does", "do", "did", "is", "are", "was", "were", "will",
    "should", "would", "may", "might", "must", "have", "has", "had",
})

# Informational intent lexical markers (higher weight if at start or present)
INFORMATIONAL_MARKERS = frozenset({
    "guide", "tutorial", "learn", "learning", "definition", "definitions",
    "meaning", "meanings", "explain", "explained", "explanation", "explains",
    "tips", "advice", "best practices", "how to", "what is", "what are",
    "why do", "when to", "where to", "who is", "which is", "difference",
    "differences", "compare", "comparison", "vs", "versus", "steps",
    "step by step", "examples", "example", "list of", "types of", "kind of",
    "kinds of", "benefits", "pros and cons", "alternatives", "overview",
    "introduction", "basics", "beginner", "advanced", "complete", "full",
    "help", "understand", "understanding", "course", "courses", "resource",
    "resources", "faq", "faqs", "questions", "answers", "article", "articles",
})

# Phrases that suggest informational (multi-word)
INFORMATIONAL_PHRASES = [
    r"\bhow to\b", r"\bwhat is\b", r"\bwhat are\b", r"\bwhy do\b", r"\bwhen to\b",
    r"\bwhere to\b", r"\bwho is\b", r"\bwhich (is|are|one)\b", r"\bbest way to\b",
    r"\bway to\b", r"\bdifference between\b", r"\bcompared to\b", r"\bstep by step\b",
    r"\bpros and cons\b", r"\btypes? of\b", r"\blist of\b", r"\bexamples? of\b",
    r"\bguide to\b", r"\btutorial on\b", r"\blearn (about|how)\b", r"\bmeaning of\b",
]


def _normalize(text: str) -> str:
    """Normalize keyword for scoring: strip, lowercase, collapse spaces."""
    if not text or not isinstance(text, str):
        return ""
    return " ".join(text.lower().strip().split())


def _score_question_words(text: str) -> float:
    """Score 0–1 based on question words at start and in text."""
    normalized = _normalize(text)
    if not normalized:
        return 0.0
    tokens = normalized.split()
    score = 0.0
    # Strong signal: starts with question word
    if tokens and tokens[0] in QUESTION_WORDS:
        score += 0.5
    # Any question word present
    count = sum(1 for t in tokens if t in QUESTION_WORDS)
    score += min(0.3, count * 0.1)
    return min(1.0, score)


def _score_informational_markers(text: str) -> float:
    """Score 0–1 based on informational lexical markers."""
    normalized = _normalize(text)
    if not normalized:
        return 0.0
    tokens = set(normalized.split())
    score = 0.0
    for marker in INFORMATIONAL_MARKERS:
        if marker in normalized:  # phrase or word
            if normalized.startswith(marker) or normalized.split()[0] == marker:
                score += 0.15
            else:
                score += 0.08
    # Multi-word phrase matches
    for pattern in INFORMATIONAL_PHRASES:
        if re.search(pattern, normalized):
            score += 0.12
    return min(1.0, score)


def _score_question_structure(text: str) -> float:
    """Score 0–1 for question structure (? or interrogative shape)."""
    s = (text or "").strip()
    if not s:
        return 0.0
    score = 0.0
    if s.endswith("?"):
        score += 0.4
    # Length: medium-long queries often informational
    word_count = len(s.split())
    if 3 <= word_count <= 8:
        score += 0.2
    elif word_count > 8:
        score += 0.15
    return min(1.0, score)


def _score_spacy(doc) -> float:
    """
    Score 0–1 using spaCy doc: POS (ADV, PRON for WH), root, aux.
    doc is a spaCy Doc; if None or not available, return 0.
    """
    if doc is None or len(doc) == 0:
        return 0.0
    score = 0.0
    first_tokens = list(doc)[:5]
    for token in first_tokens:
        # WH-words, adverbs, pronouns at start = question-like
        if token.pos_ in ("ADV", "PRON", "SCONJ") and token.text.lower() in QUESTION_WORDS:
            score += 0.15
        if token.dep_ in ("ROOT", "aux", "advcl") and token.text.lower() in QUESTION_WORDS:
            score += 0.1
    # Root is verb (common in questions)
    root = next((t for t in doc if t.dep_ == "ROOT"), None)
    if root and root.pos_ == "VERB":
        score += 0.1
    return min(1.0, score)


def score_keyword_informational_intent(
    keyword: str,
    nlp,
) -> float:
    """
    Compute informational intent score (0–100) for one keyword.

    Uses rule-based signals and optional spaCy analysis.
    """
    keyword = (keyword or "").strip()
    if not keyword:
        return 0.0

    # Weighted combination of signals
    w_question = 0.30
    w_markers = 0.35
    w_structure = 0.15
    w_spacy = 0.20

    s_question = _score_question_words(keyword)
    s_markers = _score_informational_markers(keyword)
    s_structure = _score_question_structure(keyword)

    s_spacy = 0.0
    if nlp is not None:
        try:
            doc = nlp(keyword)
            s_spacy = _score_spacy(doc)
        except Exception as e:
            logger.debug("spaCy analysis failed for %r: %s", keyword[:50], e)

    raw = (
        w_question * s_question
        + w_markers * s_markers
        + w_structure * s_structure
        + w_spacy * s_spacy
    )
    # Scale to 0–100 and round
    return round(min(100.0, max(0.0, raw * 100)), 2)


def rank_keywords_by_informational_intent(
    keywords: List[str],
    nlp,
    top_n: int = 100,
) -> List[Tuple[str, float]]:
    """
    Score all keywords and return top_n (keyword, score) sorted by score descending.
    """
    if not keywords:
        return []

    scored: List[Tuple[str, float]] = []
    seen = set()
    for k in keywords:
        k_clean = (k or "").strip()
        if not k_clean or k_clean in seen:
            continue
        seen.add(k_clean)
        score = score_keyword_informational_intent(k_clean, nlp)
        scored.append((k_clean, score))

    scored.sort(key=lambda x: (-x[1], x[0]))
    return scored[:top_n]
