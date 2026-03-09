from pydantic import BaseModel, HttpUrl
from typing import List, Optional


class AnalyzeRequest(BaseModel):
    url: HttpUrl
    analysis: List[str]
    keyword: Optional[str] = None
