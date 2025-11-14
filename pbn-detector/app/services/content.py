from __future__ import annotations

import hashlib
from typing import Iterable, List

from datasketch import MinHash

from app.config import get_settings


class ContentSimilarityService:
    def __init__(self) -> None:
        settings = get_settings()
        self.threshold = settings.minhash_threshold

    @staticmethod
    def _get_shingles(text: str, size: int = 4) -> Iterable[str]:
        text = text or ""
        tokens = text.split()
        for i in range(len(tokens) - size + 1):
            yield " ".join(tokens[i : i + size])

    def _build_minhash(self, text: str) -> MinHash:
        mh = MinHash(num_perm=128)
        for shingle in self._get_shingles(text):
            mh.update(shingle.encode("utf8"))
        return mh

    def similarity(self, text_a: str, text_b: str) -> float:
        if not text_a or not text_b:
            return 0.0
        mh1 = self._build_minhash(text_a)
        mh2 = self._build_minhash(text_b)
        return mh1.jaccard(mh2)

    def detect_duplicates(self, snippets: List[str]) -> float:
        if len(snippets) < 2:
            return 0.0

        mh_list = [self._build_minhash(text) for text in snippets]
        matches = 0
        comparisons = 0

        for i in range(len(mh_list)):
            for j in range(i + 1, len(mh_list)):
                comparisons += 1
                if mh_list[i].jaccard(mh_list[j]) >= self.threshold:
                    matches += 1

        if comparisons == 0:
            return 0.0

        return matches / comparisons


content_similarity_service = ContentSimilarityService()

