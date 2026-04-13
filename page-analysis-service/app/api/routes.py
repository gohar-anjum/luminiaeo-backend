import logging

from fastapi import APIRouter, HTTPException

from app.core.pipeline_log import log_step
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
        log_step("XX_http_422", detail=str(e))
        logger.warning("analyze rejected: %s", e)
        raise HTTPException(status_code=422, detail=str(e))
    except Exception as e:
        log_step(
            "XX_http_500",
            exc_type=type(e).__name__,
            detail=str(e)[:500],
        )
        logger.exception("analyze failed: unexpected error")
        raise HTTPException(status_code=500, detail=str(e))
