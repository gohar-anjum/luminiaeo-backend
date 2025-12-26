from __future__ import annotations

import hashlib
from typing import Iterable, List, Optional
from functools import lru_cache

from datasketch import MinHash, MinHashLSH
from loguru import logger

from app.config import get_settings
from app.utils.cache import cache_client

class ContentSimilarityService:
    def __init__(self) -> None:
        settings = get_settings()
        self.threshold = settings.minhash_threshold
        self._minhash_cache: dict[str, MinHash] = {}
        self._use_lsh = True

    @staticmethod
    def _get_shingles(text: str, size: int = 4) -> Iterable[str]:
        text = text or ""
        tokens = text.split()
        for i in range(len(tokens) - size + 1):
            yield " ".join(tokens[i : i + size])

    async def _build_minhash_async(self, text: str, use_cache: bool = True) -> MinHash:
        if use_cache:

            cache_key = cache_client._hash_key(text, "minhash")
            cached = await cache_client.get_pickle(cache_key)
            if cached:
                return cached

            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            if text_hash in self._minhash_cache:
                return self._minhash_cache[text_hash]

        mh = MinHash(num_perm=128)
        for shingle in self._get_shingles(text):
            mh.update(shingle.encode("utf8"))

        if use_cache:

            cache_key = cache_client._hash_key(text, "minhash")
            await cache_client.set_pickle(cache_key, mh, ttl=7200)

            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            self._minhash_cache[text_hash] = mh

        return mh

    def _build_minhash(self, text: str, use_cache: bool = True) -> MinHash:
        if use_cache:

            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            if text_hash in self._minhash_cache:
                return self._minhash_cache[text_hash]

        mh = MinHash(num_perm=128)
        for shingle in self._get_shingles(text):
            mh.update(shingle.encode("utf8"))

        if use_cache:
            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            self._minhash_cache[text_hash] = mh

        return mh

    def similarity(self, text_a: str, text_b: str) -> float:
        if not text_a or not text_b:
            return 0.0
        mh1 = self._build_minhash(text_a)
        mh2 = self._build_minhash(text_b)
        return mh1.jaccard(mh2)

    def detect_duplicates(self, snippets: List[str], use_lsh: Optional[bool] = None) -> float:
        if not snippets or len(snippets) < 2:
            return 0.0
        
        use_lsh = use_lsh if use_lsh is not None else self._use_lsh
        
        if use_lsh:
            lsh = MinHashLSH(threshold=self.threshold, num_perm=128)
            minhashes = []
            for snippet in snippets:
                mh = self._build_minhash(snippet)
                minhashes.append(mh)
                lsh.insert(f"doc_{len(minhashes)-1}", mh)
            
            duplicate_pairs = 0
            total_pairs = 0
            for i, mh in enumerate(minhashes):
                candidates = lsh.query(mh)
                for j in candidates:
                    if j != f"doc_{i}":
                        total_pairs += 1
                        idx = int(j.split("_")[1])
                        similarity = mh.jaccard(minhashes[idx])
                        if similarity >= self.threshold:
                            duplicate_pairs += 1
            
            return duplicate_pairs / max(total_pairs, 1)
        else:
            similarities = []
            for i in range(len(snippets)):
                for j in range(i + 1, len(snippets)):
                    sim = self.similarity(snippets[i], snippets[j])
                    similarities.append(sim)
            
            if not similarities:
                return 0.0
            
            avg_similarity = sum(similarities) / len(similarities)
            return avg_similarity if avg_similarity >= self.threshold else 0.0


# Create a singleton instance
content_similarity_service = ContentSimilarityService()