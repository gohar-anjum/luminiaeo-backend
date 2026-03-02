from fastapi import APIRouter, HTTPException
from app.models.request_models import AnalyzeRequest
from app.services.orchestrator import analyze_page

router = APIRouter()

@router.post("/analyze")
async def analyze(request: AnalyzeRequest):
    try:
        return await analyze_page(request)
    except Exception as e:
        raise HTTPException(status_code=422, detail=str(e))
