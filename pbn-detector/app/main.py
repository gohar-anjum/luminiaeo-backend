from __future__ import annotations

import time
from datetime import datetime

from fastapi import FastAPI, HTTPException
from loguru import logger

from app.config import get_settings
from app.rules.engine import rule_engine
from app.schemas import (
    BacklinkDetectionRequest,
    BacklinkDetectionResponse,
    DetectionItem,
    DetectionMeta,
    DetectionSummary,
)
from app.services.classifier import classifier_service
from app.services.content import content_similarity_service
from app.services.feature_extractor import feature_extractor
from app.utils.cache import init_cache, shutdown_cache

settings = get_settings()
app = FastAPI(title=settings.app_name)


@app.on_event("startup")
async def on_startup() -> None:
    await init_cache()
    logger.info("PBN detector started", environment=settings.environment)


@app.on_event("shutdown")
async def on_shutdown() -> None:
    await shutdown_cache()
    logger.info("PBN detector stopped")


@app.get("/health")
async def healthcheck() -> dict[str, str]:
    return {"status": "ok", "timestamp": datetime.utcnow().isoformat()}


def _risk_level(prob: float) -> str:
    if prob >= settings.high_risk_threshold:
        return "high"
    if prob >= settings.medium_risk_threshold:
        return "medium"
    return "low"


@app.post("/detect", response_model=BacklinkDetectionResponse)
async def detect(request: BacklinkDetectionRequest) -> BacklinkDetectionResponse:
    try:
        if not request.backlinks:
            raise HTTPException(status_code=400, detail="Backlinks payload cannot be empty")

        start = time.perf_counter()
        peers = request.backlinks
        items: list[DetectionItem] = []
        summary = DetectionSummary()

        try:
            snippets = [
                (signal.raw or {}).get("text_pre", "") + " " + (signal.raw or {}).get("text_post", "")
                for signal in peers
            ]
            content_similarity = content_similarity_service.detect_duplicates(snippets)
        except Exception as e:
            logger.error("Content similarity detection failed", error=str(e), exc_info=True)
            content_similarity = 0.0

        for backlink in peers:
            try:
                features = feature_extractor.build_feature_vector(backlink, peers)
                probability = classifier_service.predict_proba(features)
            except Exception as e:
                logger.error("Feature extraction or classification failed", error=str(e), source_url=str(backlink.source_url), exc_info=True)
                probability = 0.5  # Default probability

            try:
                rule_scores = rule_engine.evaluate(backlink, peers)
                rules_boost = sum(rule_scores.values())
            except Exception as e:
                logger.error("Rule evaluation failed", error=str(e), source_url=str(backlink.source_url), exc_info=True)
                rule_scores = {}
                rules_boost = 0.0

            reasons = [name for name in rule_scores.keys()]

            if backlink.safe_browsing_status == "flagged":
                rules_boost += 0.3
                reasons.append("safe_browsing_flagged")
            boosted_probability = min(probability + rules_boost + content_similarity * 0.1, 0.999)
            risk = _risk_level(boosted_probability)

            if risk == "high":
                summary.high_risk_count += 1
            elif risk == "medium":
                summary.medium_risk_count += 1
            else:
                summary.low_risk_count += 1

            if content_similarity >= settings.minhash_threshold:
                reasons.append("content_similarity_high")

            items.append(
                DetectionItem(
                    source_url=backlink.source_url,
                    pbn_probability=round(boosted_probability, 4),
                    risk_level=risk,
                    reasons=reasons or ["baseline_score"],
                    signals={
                        "ip": backlink.ip,
                        "whois_registrar": backlink.whois_registrar,
                        "domain_age_days": backlink.domain_age_days,
                        "domain_rank": backlink.domain_rank,
                        "content_similarity": content_similarity,
                        "rules": rule_scores,
                        "safe_browsing_status": backlink.safe_browsing_status,
                        "safe_browsing_threats": backlink.safe_browsing_threats,
                    },
                )
            )

        latency_ms = int((time.perf_counter() - start) * 1000)
        meta = DetectionMeta(latency_ms=latency_ms, model_version="lr-1.0")

        return BacklinkDetectionResponse(
            domain=request.domain,
            task_id=request.task_id,
            generated_at=datetime.utcnow(),
            items=items,
            summary=summary,
            meta=meta,
        )
    except HTTPException:
        raise
    except Exception as e:
        logger.error("PBN detection failed", error=str(e), exc_info=True)
        raise HTTPException(status_code=500, detail=f"Internal server error: {str(e)}")

