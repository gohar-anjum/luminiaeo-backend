# PBN Detector Microservice

FastAPI-based service that scores backlinks for potential Private Blog Network (PBN) risk using rule-based checks plus a lightweight logistic regression classifier.

## Running Locally

```bash
cd pbn-detector
poetry install
poetry run uvicorn app.main:app --reload
```

## Configuration

Environment variables (see `.env.example`):

| Variable | Description |
| --- | --- |
| `REDIS_URL` | Optional Redis cache URL |
| `WHOIS_BASE_URL`, `WHOIS_API_KEY` | Optional fallback WHOIS lookup |
| `IPINFO_TOKEN` | Optional token for ASN enrichment |
| `CLASSIFIER_MODEL_PATH` | Path to the trained logistic regression model |

## Testing

```bash
poetry run pytest
```

