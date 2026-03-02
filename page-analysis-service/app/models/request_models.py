from pydantic import BaseModel, HttpUrl
from typing import List


class AnalyzeRequest(BaseModel):
    url: HttpUrl
    analysis: List[str]
