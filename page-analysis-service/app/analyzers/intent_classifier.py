"""
Rule-based intent classifier for meta tag optimization.
Classifies content as commercial, informational, or comparative.
"""
import re

from app.core.pipeline_log import log_step


def classify_intent(text: str) -> str:
    """
    Classify content intent based on keyword patterns.
    Returns: 'commercial' | 'informational' | 'comparative' | 'informational' (default)
    """
    if not text or not isinstance(text, str):
        return "informational"

    text_lower = text.lower().strip()

    # Comparative: "vs", "compare", "comparison", "versus"
    comparative_patterns = [
        r"\bvs\b",
        r"\bversus\b",
        r"\bcompare\b",
        r"\bcomparison\b",
        r"\bcompared to\b",
        r"\bvs\.\b",
    ]
    for pattern in comparative_patterns:
        if re.search(pattern, text_lower):
            log_step("05_intent_inner", path="comparative", pattern=pattern)
            return "comparative"

    # Commercial: buy, pricing, purchase, shop, order, deal, discount
    commercial_patterns = [
        r"\bbuy\b",
        r"\bpricing\b",
        r"\bprice\b",
        r"\bpurchase\b",
        r"\bshop\b",
        r"\border\b",
        r"\bdeal\b",
        r"\bdiscount\b",
        r"\bcheap\b",
        r"\bbest\s+price\b",
        r"\bwhere to buy\b",
    ]
    for pattern in commercial_patterns:
        if re.search(pattern, text_lower):
            return "commercial"

    # Informational: how to, guide, learn, tutorial, what is
    informational_patterns = [
        r"\bhow to\b",
        r"\bguide\b",
        r"\blearn\b",
        r"\btutorial\b",
        r"\bwhat is\b",
        r"\bwhat are\b",
        r"\bwhy\b",
        r"\bcomplete guide\b",
        r"\bstep by step\b",
    ]
    for pattern in informational_patterns:
        if re.search(pattern, text_lower):
            log_step("05_intent_inner", path="informational_matched", pattern=pattern)
            return "informational"

    log_step("05_intent_inner", path="informational_default")
    return "informational"
