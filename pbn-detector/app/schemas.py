from datetime import datetime
from typing import Any, List, Optional

from pydantic import BaseModel, Field, HttpUrl

class BacklinkSignal(BaseModel):
    source_url: HttpUrl | str
    domain_from: Optional[str] = None
    anchor: Optional[str] = None
    link_type: Optional[str] = None
    domain_rank: Optional[float] = None
    ip: Optional[str] = None
    whois_registrar: Optional[str] = None
    domain_age_days: Optional[int] = None
    first_seen: Optional[datetime] = None
    last_seen: Optional[datetime] = None
    dofollow: Optional[bool] = None
    links_count: Optional[int] = None
    raw: Optional[dict[str, Any]] = None
    safe_browsing_status: Optional[str] = None
    safe_browsing_threats: Optional[List[dict[str, Any]]] = None
    safe_browsing_checked_at: Optional[datetime] = None
    backlink_spam_score: Optional[int] = None

class BacklinkDetectionRequest(BaseModel):
    domain: str
    task_id: str
    backlinks: List[BacklinkSignal]
    summary: dict[str, Any] = Field(default_factory=dict)

class DetectionItem(BaseModel):
    source_url: HttpUrl | str
    pbn_probability: float
    risk_level: str
    reasons: List[str]
    signals: dict[str, Any] = Field(default_factory=dict)

class DetectionSummary(BaseModel):
    high_risk_count: int = 0
    medium_risk_count: int = 0
    low_risk_count: int = 0

class DetectionMeta(BaseModel):
    latency_ms: int
    model_version: str

class BacklinkDetectionResponse(BaseModel):
    domain: str
    task_id: str
    generated_at: datetime
    items: List[DetectionItem]
    summary: DetectionSummary
    meta: DetectionMeta
