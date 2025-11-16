#!/bin/bash
# Test script for PBN microservice

PBN_URL="${PBN_DETECTOR_URL:-http://localhost:8000}"

echo "Testing PBN Microservice at: $PBN_URL"
echo "========================================"

# Test health endpoint
echo -e "\n1. Testing health endpoint..."
curl -s "$PBN_URL/health" | python3 -m json.tool || echo "Health check failed - is microservice running?"

# Test detect endpoint with sample data
echo -e "\n2. Testing detect endpoint with sample backlink..."
curl -s -X POST "$PBN_URL/detect" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "https://example.com",
    "task_id": "test-123",
    "backlinks": [
      {
        "source_url": "https://test-source.com/page",
        "domain_from": "test-source.com",
        "anchor": "test anchor",
        "domain_rank": 50.0,
        "ip": "1.2.3.4",
        "whois_registrar": "GoDaddy",
        "domain_age_days": 365,
        "dofollow": true,
        "backlink_spam_score": 70,
        "safe_browsing_status": "clean"
      }
    ],
    "summary": {}
  }' | python3 -m json.tool

echo -e "\n3. Testing with multiple backlinks (same IP/registrar)..."
curl -s -X POST "$PBN_URL/detect" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "https://example.com",
    "task_id": "test-456",
    "backlinks": [
      {
        "source_url": "https://test1.com/page",
        "domain_from": "test1.com",
        "anchor": "casino poker",
        "domain_rank": 50.0,
        "ip": "1.2.3.4",
        "whois_registrar": "GoDaddy",
        "domain_age_days": 100,
        "dofollow": true,
        "backlink_spam_score": 85,
        "safe_browsing_status": "clean"
      },
      {
        "source_url": "https://test2.com/page",
        "domain_from": "test2.com",
        "ip": "1.2.3.4",
        "whois_registrar": "GoDaddy",
        "domain_rank": 50.0,
        "backlink_spam_score": 80
      },
      {
        "source_url": "https://test3.com/page",
        "domain_from": "test3.com",
        "ip": "1.2.3.4",
        "whois_registrar": "GoDaddy",
        "domain_rank": 50.0,
        "backlink_spam_score": 75
      }
    ],
    "summary": {}
  }' | python3 -m json.tool

echo -e "\n========================================"
echo "Test complete"
