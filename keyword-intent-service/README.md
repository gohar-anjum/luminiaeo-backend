# Keyword Intent Service

A standalone microservice that ranks keywords by **informational intent** using spaCy. It accepts up to **1000 keywords** from an external source and returns the **top 100** by informational intent score.

## Overview

- **Input:** Up to 1000 keywords (e.g. from an API, file, or another service).
- **Output:** Top 100 keywords with the highest informational intent score (0–100).
- **Stack:** Python 3.11, FastAPI, spaCy (`en_core_web_sm`).

Scoring uses:

- Question words (what, how, why, when, where, who, which, etc.)
- Informational markers (guide, tutorial, learn, definition, meaning, tips, etc.)
- Question structure (e.g. ends with `?`, phrase length)
- spaCy POS/dependency features (WH-words, root verb, etc.)

## Quick start

### Local (no Docker)

```bash
cd keyword-intent-service
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
python -m spacy download en_core_web_sm
uvicorn app.main:app --host 0.0.0.0 --port 8002
```

### Docker

```bash
cd keyword-intent-service
docker build -t keyword-intent-service .
docker run -p 8002:8002 keyword-intent-service
```

## API

### Health

```http
GET /health
```

Response:

```json
{
  "status": "ok",
  "service": "keyword-intent",
  "spacy_loaded": true
}
```

### Rank keywords (informational intent)

```http
POST /rank
Content-Type: application/json

{
  "keywords": [
    "what is seo",
    "best running shoes 2024",
    "how to learn python",
    "buy iphone 15",
    "why is the sky blue"
  ]
}
```

- **Max body:** 1000 keywords per request.
- **Response:** Top 100 (or fewer if input is smaller) by informational score.

Example response:

```json
{
  "top_keywords": [
    { "keyword": "what is seo", "informational_score": 72.5 },
    { "keyword": "how to learn python", "informational_score": 68.2 },
    { "keyword": "why is the sky blue", "informational_score": 65.0 }
  ],
  "total_input": 5,
  "top_n": 5
}
```

## Configuration

| Variable        | Default           | Description                    |
|----------------|-------------------|--------------------------------|
| `SPACY_MODEL`  | `en_core_web_sm`  | spaCy model name               |
| `PORT`         | `8002`            | Server port                    |
| `HOST`         | `0.0.0.0`         | Bind address                   |

## Project layout

```
keyword-intent-service/
├── app/
│   ├── __init__.py
│   ├── config.py        # Settings (max keywords, top_n, model)
│   ├── intent_scorer.py # spaCy + rule-based scoring
│   └── main.py          # FastAPI app, /health, /rank
├── Dockerfile
├── requirements.txt
└── README.md
```

This service is separate from the main Luminiaeo backend and can be deployed and scaled independently.
