from pydantic import BaseModel
from typing import List, Dict, Optional

class Keyword(BaseModel):
    phrase: str
    score: float

class AnalyzeResponse(BaseModel):
    url: str
    meta: Dict
    content: Dict
    analysis: Dict
