#!/usr/bin/env python3
"""
Test script to reproduce the 0.275 issue with real payload structure.
This tests the microservice with the exact payload format from Laravel.
"""

import json
import requests
from datetime import datetime
from typing import Dict, Any

# Test payload matching the actual Laravel payload structure
TEST_PAYLOAD = {
    "domain": "https://cricbuzz.com",
    "task_id": "test-real-payload-001",
    "backlinks": [
        {
            "source_url": "https://www.topblogs.de/",
            "domain_from": "www.topblogs.de",
            "anchor": None,
            "link_type": "dofollow",
            "domain_rank": 79.0,
            "ip": "78.46.71.15",
            "whois_registrar": None,
            "domain_age_days": None,
            "first_seen": "2025-05-26T14:38:20+00:00",
            "last_seen": "2025-05-26T14:38:50+00:00",
            "dofollow": True,
            "links_count": 4,
            "safe_browsing_status": "clean",
            "safe_browsing_threats": [],
            "safe_browsing_checked_at": "2025-11-14T18:35:45+00:00",
            "backlink_spam_score": 0,
        },
        {
            "source_url": "https://www.ampersand-seo.de/index.html",
            "domain_from": "www.ampersand-seo.de",
            "anchor": "GÜTSEL ONLINE",
            "link_type": "dofollow",
            "domain_rank": 70.0,
            "ip": "81.169.251.31",
            "whois_registrar": None,
            "domain_age_days": None,
            "first_seen": "2023-10-09T16:53:38+00:00",
            "last_seen": "2025-01-06T07:53:28+00:00",
            "dofollow": True,
            "links_count": 2,
            "safe_browsing_status": "clean",
            "safe_browsing_threats": [],
            "safe_browsing_checked_at": "2025-11-14T18:35:45+00:00",
            "backlink_spam_score": 20,
        },
        {
            "source_url": "https://www.textilwerbung-edenfeld.de/index.html",
            "domain_from": "www.textilwerbung-edenfeld.de",
            "anchor": "Gütsel Webcube CMS",
            "link_type": "dofollow",
            "domain_rank": 65.0,
            "ip": "81.169.251.31",
            "whois_registrar": None,
            "domain_age_days": None,
            "first_seen": "2024-02-12T03:09:59+00:00",
            "last_seen": "2025-09-06T14:31:21+00:00",
            "dofollow": True,
            "links_count": 1,
            "safe_browsing_status": "clean",
            "safe_browsing_threats": [],
            "safe_browsing_checked_at": "2025-11-14T18:35:45+00:00",
            "backlink_spam_score": 0,
        },
        {
            "source_url": "http://innewyorkguides.com/",
            "domain_from": "innewyorkguides.com",
            "anchor": "https://guetsel.de/",
            "link_type": "dofollow",
            "domain_rank": 7.0,  # LOW RANK - should trigger domain_quality rule
            "ip": "199.188.201.75",
            "whois_registrar": None,
            "domain_age_days": None,
            "first_seen": "2025-06-21T20:06:28+00:00",
            "last_seen": "2025-09-03T17:51:01+00:00",
            "dofollow": True,
            "links_count": 1,
            "safe_browsing_status": "clean",
            "safe_browsing_threats": [],
            "safe_browsing_checked_at": "2025-11-14T18:35:45+00:00",
            "backlink_spam_score": 75,  # HIGH SPAM - should trigger spam_score_rule
        },
        {
            "source_url": "https://www.useragentstring.com/",
            "domain_from": "www.useragentstring.com",
            "anchor": "dataforseo.com",
            "link_type": "dofollow",
            "domain_rank": 45.0,  # LOW RANK - should trigger domain_quality rule
            "ip": "92.205.111.3",
            "whois_registrar": None,
            "domain_age_days": None,
            "first_seen": "2022-09-10T06:52:29+00:00",
            "last_seen": "2025-10-09T07:38:10+00:00",
            "dofollow": True,
            "links_count": 1,
            "safe_browsing_status": "clean",
            "safe_browsing_threats": [],
            "safe_browsing_checked_at": "2025-11-14T18:35:45+00:00",
            "backlink_spam_score": 50,  # MEDIUM SPAM - should trigger spam_score_rule
        },
    ],
    "summary": {}
}

# Expected results
EXPECTED_RESULTS = {
    0: {"risk": "low", "rules": []},  # spam=0, rank=79
    1: {"risk": "low", "rules": []},  # spam=20, rank=70
    2: {"risk": "low", "rules": []},  # spam=0, rank=65
    3: {"risk": "high", "rules": ["dataforseo_spam_score", "domain_quality"]},  # spam=75, rank=7
    4: {"risk": "medium", "rules": ["dataforseo_spam_score", "domain_quality"]},  # spam=50, rank=45
}


def analyze_response(response_data: Dict[str, Any]) -> None:
    """Analyze the microservice response and compare with expectations."""
    print("=" * 80)
    print("MICROSERVICE RESPONSE ANALYSIS")
    print("=" * 80)
    
    # Response structure: top-level items, not nested under pbn_detection
    if "items" in response_data:
        detection = response_data
        items = detection.get("items", [])
    elif "pbn_detection" in response_data:
        detection = response_data["pbn_detection"]
        items = detection.get("items", [])
    else:
        print("ERROR: No items or pbn_detection in response")
        print(f"Response keys: {list(response_data.keys())}")
        return
    
    print(f"\nTotal backlinks analyzed: {len(items)}")
    summary = detection.get('summary', {})
    if summary:
        print(f"Summary: {summary}")
    print("\n" + "=" * 80)
    print("DETAILED ANALYSIS")
    print("=" * 80)
    
    issues_found = []
    
    for idx, item in enumerate(items):
        source_url = item.get("source_url", "unknown")
        probability = item.get("pbn_probability", 0.0)
        risk_level = item.get("risk_level", "unknown")
        reasons = item.get("reasons", [])
        signals = item.get("signals", {})
        rules = signals.get("rules", [])
        
        # Get original backlink data
        original = TEST_PAYLOAD["backlinks"][idx]
        spam_score = original.get("backlink_spam_score", 0)
        domain_rank = original.get("domain_rank", 0)
        
        expected = EXPECTED_RESULTS.get(idx, {})
        expected_risk = expected.get("risk", "UNKNOWN")
        expected_rules = expected.get("rules", [])
        
        print(f"\nBacklink #{idx}: {source_url}")
        print(f"  Spam Score: {spam_score}, Domain Rank: {domain_rank}")
        print(f"  Probability: {probability:.4f}")
        print(f"  Risk Level: {risk_level} (Expected: {expected_risk})")
        print(f"  Reasons: {reasons}")
        print(f"  Rules Triggered: {rules} (Expected: {expected_rules})")
        
        # Check for issues
        if probability == 0.275:
            issues_found.append(f"Backlink #{idx}: Returns 0.275 (classifier default + no rules)")
        
        if risk_level != expected_risk:
            issues_found.append(
                f"Backlink #{idx}: Risk level mismatch - got {risk_level}, expected {expected_risk}"
            )
        
        if set(rules) != set(expected_rules):
            missing_rules = set(expected_rules) - set(rules)
            if missing_rules:
                issues_found.append(
                    f"Backlink #{idx}: Missing rules - expected {missing_rules}, got {rules}"
                )
        
        # Analyze why rules didn't trigger
        if spam_score >= 40 and "dataforseo_spam_score" not in rules:
            issues_found.append(
                f"Backlink #{idx}: spam_score={spam_score} should trigger spam_score_rule but didn't"
            )
        
        if domain_rank < 50 and "domain_quality" not in rules:
            issues_found.append(
                f"Backlink #{idx}: domain_rank={domain_rank} should trigger domain_quality rule but didn't"
            )
    
    print("\n" + "=" * 80)
    print("ISSUES FOUND")
    print("=" * 80)
    
    if issues_found:
        for issue in issues_found:
            print(f"  ❌ {issue}")
    else:
        print("  ✅ No issues found!")
    
    print("\n" + "=" * 80)
    print("ROOT CAUSE ANALYSIS")
    print("=" * 80)
    
    # Count how many return 0.275
    count_275 = sum(1 for item in items if abs(item.get("pbn_probability", 0) - 0.275) < 0.001)
    if count_275 > 0:
        print(f"\n⚠️  {count_275} backlink(s) return 0.275 probability")
        print("   This indicates:")
        print("   1. Classifier returning 0.5 (default fallback)")
        print("   2. Rules returning 0.0 (not triggering)")
        print("   3. Content similarity = 0.0")
        print("   Calculation: 0.5 * 0.55 + 0.0 * 0.3 + 0.0 * 0.15 = 0.275")
    
    # Count how many have empty rules
    count_empty_rules = sum(1 for item in items if not item.get("signals", {}).get("rules", []))
    if count_empty_rules > 0:
        print(f"\n⚠️  {count_empty_rules} backlink(s) have empty rules array")
        print("   This indicates rules are not triggering when they should")
    
    print()


def main():
    """Main test function."""
    import os
    
    # Get microservice URL from environment or use default
    microservice_url = os.getenv("PBN_DETECTOR_URL", "http://localhost:9000")
    endpoint = f"{microservice_url}/detect"
    
    print("=" * 80)
    print("TESTING PBN MICROSERVICE WITH REAL PAYLOAD")
    print("=" * 80)
    print(f"Endpoint: {endpoint}")
    print(f"Backlinks: {len(TEST_PAYLOAD['backlinks'])}")
    print()
    
    try:
        print("Sending request...")
        response = requests.post(
            endpoint,
            json=TEST_PAYLOAD,
            headers={"Content-Type": "application/json"},
            timeout=30
        )
        
        print(f"Response status: {response.status_code}")
        
        if response.status_code != 200:
            print(f"ERROR: Non-200 status code")
            print(f"Response: {response.text}")
            return
        
        response_data = response.json()
        
        # Save full response for inspection
        with open("/tmp/pbn_test_response.json", "w") as f:
            json.dump(response_data, f, indent=2)
        print("Full response saved to /tmp/pbn_test_response.json")
        
        # Analyze the response
        analyze_response(response_data)
        
    except requests.exceptions.ConnectionError:
        print(f"ERROR: Could not connect to microservice at {endpoint}")
        print("Make sure the microservice is running:")
        print("  cd pbn-detector && poetry run uvicorn app.main:app --host 0.0.0.0 --port 9000")
    except Exception as e:
        print(f"ERROR: {type(e).__name__}: {e}")
        import traceback
        traceback.print_exc()


if __name__ == "__main__":
    main()

