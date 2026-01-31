"""
Keyword Intent Service – rank keywords by informational intent using spaCy.

Receives up to 1000 keywords from an external source and returns the top 100
by informational intent score.
"""
import logging
import os

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from app.config import MAX_KEYWORDS_INPUT, SPACY_MODEL, TOP_N_INFORMATIONAL
from app.intent_scorer import rank_keywords_by_informational_intent

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(
    title="Keyword Intent Service",
    description="Ranks keywords by informational intent using spaCy. Accepts up to 1000 keywords, returns top 100.",
    version="1.0.0",
)

nlp = None


class RankRequest(BaseModel):
    """Request body: list of keywords from an external source."""

    keywords: list[str] = Field(
        ...,
        description="List of keywords to rank (max 1000). Data is typically received from an outer source.",
        min_length=1,
        max_length=MAX_KEYWORDS_INPUT,
    )


class RankedKeyword(BaseModel):
    """A keyword with its informational intent score."""

    keyword: str
    informational_score: float


class RankResponse(BaseModel):
    """Top N keywords by informational intent."""

    top_keywords: list[RankedKeyword] = Field(
        ...,
        description="Top 100 keywords by informational intent score (or fewer if input had fewer).",
    )
    total_input: int = Field(..., description="Number of unique keywords processed.")
    top_n: int = Field(..., description="Requested/used top_n (default 100).")


@app.get("/health")
async def health_check():
    """Health check for orchestration and load balancers."""
    return {
        "status": "ok",
        "service": "keyword-intent",
        "spacy_loaded": nlp is not None,
    }


@app.on_event("startup")
async def load_spacy_model():
    """Load spaCy model at startup."""
    global nlp
    try:
        model_name = os.getenv("SPACY_MODEL", SPACY_MODEL)
        logger.info("Loading spaCy model: %s", model_name)
        import spacy

        nlp = spacy.load(model_name)
        logger.info("spaCy model loaded successfully")
    except Exception as e:
        logger.exception("Failed to load spaCy model: %s", e)
        nlp = None


@app.post("/rank", response_model=RankResponse)
async def rank_informational(request: RankRequest):
    """
    Rank keywords by informational intent.

    Accepts up to 1000 keywords (e.g. from an external source), scores each with
    spaCy-based rules, and returns the top 100 by informational intent score.
    """
    if nlp is None:
        raise HTTPException(
            status_code=503,
            detail="spaCy model not loaded; service unavailable",
        )

    top_n = min(TOP_N_INFORMATIONAL, len(request.keywords))
    ranked = rank_keywords_by_informational_intent(
        keywords=request.keywords,
        nlp=nlp,
        top_n=TOP_N_INFORMATIONAL,
    )

    return RankResponse(
        top_keywords=[
            RankedKeyword(keyword=k, informational_score=s) for k, s in ranked
        ],
        total_input=len(request.keywords),
        top_n=len(ranked),
    )
