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
        self._use_lsh = True  # Enable LSH for better performance

    @staticmethod
    def _get_shingles(text: str, size: int = 4) -> Iterable[str]:
        text = text or ""
        tokens = text.split()
        for i in range(len(tokens) - size + 1):
            yield " ".join(tokens[i : i + size])

    async def _build_minhash_async(self, text: str, use_cache: bool = True) -> MinHash:
        """Build MinHash with Redis caching (async)"""
        if use_cache:
            # Try Redis cache first
            cache_key = cache_client._hash_key(text, "minhash")
            cached = await cache_client.get_pickle(cache_key)
            if cached:
                return cached
            
            # Try in-memory cache
            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            if text_hash in self._minhash_cache:
                return self._minhash_cache[text_hash]
        
        mh = MinHash(num_perm=128)
        for shingle in self._get_shingles(text):
            mh.update(shingle.encode("utf8"))
        
        if use_cache:
            # Store in Redis cache
            cache_key = cache_client._hash_key(text, "minhash")
            await cache_client.set_pickle(cache_key, mh, ttl=7200)  # 2 hours
            
            # Also store in-memory cache
            text_hash = hashlib.md5(text.encode('utf-8')).hexdigest()
            self._minhash_cache[text_hash] = mh
        
        return mh

    def _build_minhash(self, text: str, use_cache: bool = True) -> MinHash:
        """Build MinHash with optional caching (sync fallback)"""
        if use_cache:
            # Use in-memory cache
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
        """
        Detect duplicate content using MinHash.
        
        Uses LSH (Locality-Sensitive Hashing) for O(n log n) complexity
        instead of O(n²) all-pairs comparison.
        """
        if len(snippets) < 2:
            return 0.0
        
        use_lsh = use_lsh if use_lsh is not None else self._use_lsh
        
        if use_lsh and len(snippets) > 10:
            # Use LSH for better performance on large datasets
            return self._detect_duplicates_lsh(snippets)
        else:
            # Use all-pairs for small datasets (more accurate)
            return self._detect_duplicates_all_pairs(snippets)

    def _detect_duplicates_lsh(self, snippets: List[str]) -> float:
        """O(n log n) using LSH instead of O(n²)"""
        try:
            # Build LSH index
            lsh = MinHashLSH(threshold=self.threshold, num_perm=128)
            mh_list = []
            
            # Build MinHash for each snippet
            for i, text in enumerate(snippets):
                mh = self._build_minhash(text)
                mh_list.append(mh)
                lsh.insert(f"doc_{i}", mh)
            
            # Query for duplicates - O(n log n) average case
            matches = 0
            comparisons = 0
            seen_pairs = set()
            
            for i, mh in enumerate(mh_list):
                # LSH query returns candidates in O(log n) average
                candidates = lsh.query(mh)
                
                # Only compare with candidates, not all pairs
                for candidate_id in candidates:
                    j = int(candidate_id.split('_')[1])
                    if j > i:  # Avoid duplicate comparisons
                        pair = (i, j)
                        if pair not in seen_pairs:
                            seen_pairs.add(pair)
                            comparisons += 1
                            similarity = mh_list[j].jaccard(mh)
                            if similarity >= self.threshold:
                                matches += 1
            
            if comparisons == 0:
                return 0.0
            
            return matches / comparisons
            
        except Exception as e:
            logger.warning("LSH duplicate detection failed, falling back to all-pairs", error=str(e))
            return self._detect_duplicates_all_pairs(snippets)

    def _detect_duplicates_all_pairs(self, snippets: List[str]) -> float:
        """O(n²) all-pairs comparison (legacy method)"""
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
    
    def clear_cache(self) -> None:
        """Clear MinHash cache"""
        self._minhash_cache.clear()


content_similarity_service = ContentSimilarityService()

