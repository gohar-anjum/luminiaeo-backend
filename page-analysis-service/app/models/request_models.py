from pydantic import BaseModel, HttpUrl
from typing import List, Optional

class AnalyzeRequest(BaseModel):
    url: HttpUrl
    analysis: List[str]
    compare_to: Optional[str] = None
