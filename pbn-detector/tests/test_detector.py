import asyncio
from datetime import datetime

from fastapi.testclient import TestClient

from app.main import app


client = TestClient(app)


def test_detect_endpoint_returns_summary():
    payload = {
        "domain": "https://example.com",
        "task_id": "task-123",
        "summary": {"total_backlinks": 1},
        "backlinks": [
            {
                "source_url": "https://source.example.com/page",
                "domain_from": "source.example.com",
                "anchor": "buy cheap widgets",
                "link_type": "dofollow",
                "domain_rank": 120,
                "ip": "192.0.2.1",
                "whois_registrar": "Example Registrar",
                "domain_age_days": 200,
                "first_seen": datetime.utcnow().isoformat(),
                "dofollow": True,
                "safe_browsing_status": "clean",
            }
        ],
    }

    response = client.post("/detect", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["summary"]["high_risk_count"] + data["summary"]["medium_risk_count"] + data["summary"]["low_risk_count"] == 1
    assert data["items"][0]["risk_level"] in {"low", "medium", "high"}

