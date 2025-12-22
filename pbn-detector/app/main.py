from __future__ import annotations

import asyncio
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from typing import Any, Dict, List, Optional

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
from app.services.adaptive_thresholds import adaptive_thresholds
from app.services.classifier import classifier_service
from app.services.content import content_similarity_service
from app.services.enhanced_features import enhanced_feature_extractor
from app.services.ensemble_classifier import ensemble_classifier
from app.services.feature_extractor import feature_extractor
from app.utils.cache import init_cache, shutdown_cache

settings = get_settings()
app = FastAPI(title=settings.app_name)

@app.on_event("startup")
async def on_startup() -> None:
    await init_cache()

@app.on_event("shutdown")
async def on_shutdown() -> None:
    await shutdown_cache()

@app.get("/health")
async def healthcheck() -> dict[str, str]:
    return {"status": "ok", "timestamp": datetime.utcnow().isoformat()}

def _risk_level(prob: float, thresholds: Optional[dict[str, float]] = None) -> str:
    if thresholds:
        if prob >= thresholds['high_risk']:
            return "high"
        if prob >= thresholds['medium_risk']:
            return "medium"
        return "low"
    else:

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

        network_features = feature_extractor.precompute_network_features(peers)
        network_stats = rule_engine.precompute_network_stats(peers)

        try:
            snippets = [
                (signal.raw or {}).get("text_pre", "") + " " + (signal.raw or {}).get("text_post", "")
                for signal in peers
            ]
            network_content_similarity = content_similarity_service.detect_duplicates(snippets)
        except Exception as e:
            logger.warning("Content similarity calculation failed", error=str(e))
            network_content_similarity = 0.0

        adaptive_thresholds_dict = None
        if settings.use_ensemble or settings.use_enhanced_features:
            try:
                adaptive_thresholds_dict = adaptive_thresholds.adjust_thresholds(
                    total_backlinks=len(peers)
                )
            except Exception as e:
                logger.warning("Adaptive threshold adjustment failed", error=str(e))

        enhanced_features_map = {}
        if settings.use_enhanced_features:
            for backlink in peers:
                try:
                    enhanced_features_map[backlink.source_url] = enhanced_feature_extractor.extract_all_enhanced_features(
                        backlink, peers
                    )
                except Exception as e:
                    logger.warning("Enhanced feature extraction failed",
                                 backlink=str(backlink.source_url), error=str(e))
                    enhanced_features_map[backlink.source_url] = {}

        summary = DetectionSummary()
        if settings.use_parallel_processing and len(peers) > 50:
            items = await _process_backlinks_parallel(
                peers, network_features, network_stats, enhanced_features_map,
                network_content_similarity, adaptive_thresholds_dict, summary, settings
            )
        else:
            items = await _process_backlinks_sequential(
                peers, network_features, network_stats, enhanced_features_map,
                network_content_similarity, adaptive_thresholds_dict, summary, settings
            )

        latency_ms = int((time.perf_counter() - start) * 1000)
        model_version = "lightweight-v1.0" if not classifier_service.use_ml_model else "lr-1.0"
        meta = DetectionMeta(latency_ms=latency_ms, model_version=model_version)

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

async def _process_backlinks_sequential(
    peers: List[BacklinkSignal],
    network_features: Any,
    network_stats: Any,
    enhanced_features_map: Dict,
    network_content_similarity: float,
    adaptive_thresholds_dict: Optional[Dict],
    summary: DetectionSummary,
    settings: Any
) -> List[DetectionItem]:
    items: list[DetectionItem] = []

    for backlink in peers:
        try:

            features = feature_extractor.build_feature_vector(backlink, network_features)
            probability = classifier_service.predict_proba(features, backlink)

            if settings.use_enhanced_features:
                enhanced_features = enhanced_features_map.get(backlink.source_url, {})
                if enhanced_features:

                    if enhanced_features.get('link_stability', 0.5) > 0.6:
                        probability += 0.1
                    if enhanced_features.get('temporal_clustering', 0) > 0.5:
                        probability += 0.15
                    if enhanced_features.get('clustering_coefficient', 0) > 0.5:
                        probability += 0.1
                    if enhanced_features.get('rank_z_score', 0) > 0.7:
                        probability += 0.1
                    if enhanced_features.get('spam_z_score', 0) > 0.7:
                        probability += 0.1

                    probability = min(probability, 0.99)
            logger.debug("Classifier result",
                       backlink=str(backlink.source_url),
                       probability=probability,
                       spam_score=backlink.backlink_spam_score,
                       domain_rank=backlink.domain_rank,
                       features_len=len(features))
        except Exception as e:
            logger.error("Feature extraction/classification failed",
                       error=str(e), backlink=str(backlink.source_url), exc_info=True)
            probability = 0.5

        try:

            rule_scores = rule_engine.evaluate(backlink, network_stats)
            rules_boost = sum(rule_scores.values())

            if settings.use_ensemble:
                try:
                    ensemble_prob, confidence = ensemble_classifier.predict_proba(
                        features, backlink, rule_scores, probability
                    )
                    probability = ensemble_prob
                    logger.debug("Ensemble classifier result",
                               backlink=str(backlink.source_url),
                               ensemble_prob=ensemble_prob,
                               confidence=confidence)
                except Exception as e:
                    logger.warning("Ensemble classifier failed, using base probability",
                                 backlink=str(backlink.source_url), error=str(e))
            logger.debug("Rule evaluation result",
                       backlink=str(backlink.source_url),
                       rule_scores=rule_scores,
                       rules_boost=rules_boost,
                       spam_score=backlink.backlink_spam_score,
                       domain_rank=backlink.domain_rank)
        except Exception as e:
            logger.error("Rule evaluation failed",
                       error=str(e), backlink=str(backlink.source_url), exc_info=True)
            rule_scores = {}
            rules_boost = 0.0

        reasons = [name for name in rule_scores.keys()]

        if backlink.safe_browsing_status == "flagged":
            rules_boost += 0.3
            reasons.append("safe_browsing_flagged")

        rule_weight = 0.3
        content_weight = 0.15
        base_weight = 1.0 - rule_weight - content_weight

        normalized_rule_boost = min(rules_boost, 1.0)

        is_high_risk_signal = (
            (backlink.backlink_spam_score and backlink.backlink_spam_score >= 60) or
            (backlink.domain_rank and backlink.domain_rank < 20)
        )

        if is_high_risk_signal:

            rule_weight = 0.4
            base_weight = 1.0 - rule_weight - content_weight

        boosted_probability = (
            probability * base_weight +
            normalized_rule_boost * rule_weight +
            network_content_similarity * content_weight
        )

        if is_high_risk_signal and normalized_rule_boost > 0:

            if "dataforseo_spam_score" in rule_scores and "domain_quality" in rule_scores:
                boosted_probability += 0.25
            elif normalized_rule_boost >= 0.3:
                boosted_probability += 0.15

        boosted_probability = min(max(boosted_probability, 0.0), 0.999)
        risk = _risk_level(boosted_probability, adaptive_thresholds_dict)

        if risk == "high":
            summary.high_risk_count += 1
        elif risk == "medium":
            summary.medium_risk_count += 1
        else:
            summary.low_risk_count += 1

        if network_content_similarity >= settings.minhash_threshold:
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
                    "content_similarity": network_content_similarity,
                    "rules": rule_scores if rule_scores else {},
                    "safe_browsing_status": backlink.safe_browsing_status,
                    "safe_browsing_threats": backlink.safe_browsing_threats,
                    "backlink_spam_score": backlink.backlink_spam_score,
                },
            )
        )

    return items

async def _process_backlink_single(
    backlink: BacklinkSignal,
    network_features: Any,
    network_stats: Any,
    enhanced_features_map: Dict,
    network_content_similarity: float,
    adaptive_thresholds_dict: Optional[Dict],
    settings: Any
) -> DetectionItem:
    try:
        features = feature_extractor.build_feature_vector(backlink, network_features)
        probability = classifier_service.predict_proba(features, backlink)

        if settings.use_enhanced_features:
            enhanced_features = enhanced_features_map.get(backlink.source_url, {})
            if enhanced_features:
                if enhanced_features.get('link_stability', 0.5) > 0.6:
                    probability += 0.1
                if enhanced_features.get('temporal_clustering', 0) > 0.5:
                    probability += 0.15
                if enhanced_features.get('clustering_coefficient', 0) > 0.5:
                    probability += 0.1
                if enhanced_features.get('rank_z_score', 0) > 0.7:
                    probability += 0.1
                if enhanced_features.get('spam_z_score', 0) > 0.7:
                    probability += 0.1
                probability = min(probability, 0.99)
    except Exception as e:
        logger.error("Feature extraction/classification failed",
                   error=str(e), backlink=str(backlink.source_url), exc_info=True)
        probability = 0.5

    try:
        rule_scores = rule_engine.evaluate(backlink, network_stats)
        rules_boost = sum(rule_scores.values())

        if settings.use_ensemble:
            try:
                ensemble_prob, confidence = ensemble_classifier.predict_proba(
                    features, backlink, rule_scores, probability
                )
                probability = ensemble_prob
            except Exception as e:
                logger.warning("Ensemble classifier failed",
                             backlink=str(backlink.source_url), error=str(e))
    except Exception as e:
        logger.error("Rule evaluation failed",
                   error=str(e), backlink=str(backlink.source_url), exc_info=True)
        rule_scores = {}
        rules_boost = 0.0

    reasons = [name for name in rule_scores.keys()]

    if backlink.safe_browsing_status == "flagged":
        rules_boost += 0.3
        reasons.append("safe_browsing_flagged")

    rule_weight = 0.3
    content_weight = 0.15
    base_weight = 1.0 - rule_weight - content_weight

    normalized_rule_boost = min(rules_boost, 1.0)

    is_high_risk_signal = (
        (backlink.backlink_spam_score and backlink.backlink_spam_score >= 60) or
        (backlink.domain_rank and backlink.domain_rank < 20)
    )

    if is_high_risk_signal:
        rule_weight = 0.4
        base_weight = 1.0 - rule_weight - content_weight

    boosted_probability = (
        probability * base_weight +
        normalized_rule_boost * rule_weight +
        network_content_similarity * content_weight
    )

    if is_high_risk_signal and normalized_rule_boost > 0:
        if "dataforseo_spam_score" in rule_scores and "domain_quality" in rule_scores:
            boosted_probability += 0.25
        elif normalized_rule_boost >= 0.3:
            boosted_probability += 0.15

    boosted_probability = min(max(boosted_probability, 0.0), 0.999)
    risk = _risk_level(boosted_probability, adaptive_thresholds_dict)

    if network_content_similarity >= settings.minhash_threshold:
        reasons.append("content_similarity_high")

    return DetectionItem(
        source_url=backlink.source_url,
        pbn_probability=round(boosted_probability, 4),
        risk_level=risk,
        reasons=reasons or ["baseline_score"],
        signals={
            "ip": backlink.ip,
            "whois_registrar": backlink.whois_registrar,
            "domain_age_days": backlink.domain_age_days,
            "domain_rank": backlink.domain_rank,
            "content_similarity": network_content_similarity,
            "rules": rule_scores if rule_scores else {},
            "safe_browsing_status": backlink.safe_browsing_status,
            "safe_browsing_threats": backlink.safe_browsing_threats,
            "backlink_spam_score": backlink.backlink_spam_score,
        },
    )

async def _process_backlinks_parallel(
    peers: List[BacklinkSignal],
    network_features: Any,
    network_stats: Any,
    enhanced_features_map: Dict,
    network_content_similarity: float,
    adaptive_thresholds_dict: Optional[Dict],
    summary: DetectionSummary,
    settings: Any
) -> List[DetectionItem]:
    items: list[DetectionItem] = []

    tasks = [
        _process_backlink_single(
            bl, network_features, network_stats, enhanced_features_map,
            network_content_similarity, adaptive_thresholds_dict, settings
        )
        for bl in peers
    ]

    results = await asyncio.gather(*tasks, return_exceptions=True)

    for result in results:
        if isinstance(result, Exception):
            logger.error("Parallel processing error", error=str(result))
            continue

        items.append(result)

        if result.risk_level == "high":
            summary.high_risk_count += 1
        elif result.risk_level == "medium":
            summary.medium_risk_count += 1
        else:
            summary.low_risk_count += 1

    return items
