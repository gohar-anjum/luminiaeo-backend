import logging

from fastapi import APIRouter, HTTPException

from app.models.request_models import AnalyzeRequest
from app.services.orchestrator import analyze_page

logger = logging.getLogger(__name__)

router = APIRouter()


@router.post("/analyze")
async def analyze(request: AnalyzeRequest):
    logger.info(
        "analyze request received payload=%s",
        request.model_dump(mode="json"),
    )
    try:
        return await analyze_page(request)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
