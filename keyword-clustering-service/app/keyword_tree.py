"""
Keyword expansion (Google Suggest), MiniLM deduplication, intent, and hierarchical tree.
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
from typing import Any, Dict, List, Optional, Tuple

import httpx
import numpy as np
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from sentence_transformers import SentenceTransformer

logger = logging.getLogger(__name__)

router = APIRouter()

tree_embedding_model: Optional[SentenceTransformer] = None

SUGGEST_URL = "https://suggestqueries.google.com/complete/search"


def load_tree_embedding_model() -> None:
    global tree_embedding_model
    name = os.getenv("TREE_MODEL_NAME", "sentence-transformers/all-MiniLM-L6-v2")
    tree_embedding_model = SentenceTransformer(name)
    logger.info("Tree embedding model loaded: %s", name)


class KeywordClusterRequest(BaseModel):
    seed: str = Field(..., min_length=1, max_length=255)
    language_code: str = Field("en", min_length=2, max_length=8)
    location_code: int = Field(2840, ge=1)
    gl: str = Field("us", min_length=2, max_length=8)
    schema_version: int = Field(1, ge=1, le=99)


def _normalize_phrase(s: str) -> str:
    return re.sub(r"\s+", " ", s.strip())


def _unique_preserve_order(items: List[str]) -> List[str]:
    seen = set()
    out = []
    for x in items:
        k = x.lower().strip()
        if not k or k in seen:
            continue
        seen.add(k)
        out.append(_normalize_phrase(x))
    return out


async def fetch_suggestions(
    client: httpx.AsyncClient, q: str, hl: str, gl: str
) -> Tuple[List[str], Optional[str]]:
    if not q.strip():
        return [], None
    params = {"client": "firefox", "q": q, "hl": hl, "gl": gl}
    try:
        r = await client.get(SUGGEST_URL, params=params)
        r.raise_for_status()
        data = r.json()
        if isinstance(data, list) and len(data) > 1 and isinstance(data[1], list):
            return [str(x) for x in data[1] if x], None
        return [], None
    except Exception as e:
        logger.warning("Suggest failed for %r: %s", q, e)
        return [], str(e)


def classify_intent(text: str) -> str:
    t = text.lower()
    if re.search(
        r"\.(com|org|net|io|co\.uk)\b|/login|sign in|sign-in|official site|www\.",
        t,
    ):
        return "navigational"
    if re.search(
        r"\bbuy\b|\bprice\b|\border\b|\bcoupon\b|\bdiscount\b|\bcheap\b|"
        r"subscribe|free trial|download now|book now|for sale",
        t,
    ):
        return "transactional"
    if re.search(
        r"\bbest\b|\btop \d+\b|\breview\b|\bvs\b|versus|alternative|pricing|compare",
        t,
    ):
        return "commercial"
    if re.search(
        r"^how\b|^what\b|^why\b|^when\b|^where\b|^who\b|guide|tutorial|meaning|learn|ideas",
        t,
    ):
        return "informational"
    return "informational"


def _embedding_fallback_intent(
    text: str, emb: np.ndarray, proto_embs: np.ndarray, labels: List[str]
) -> str:
    sims = np.dot(proto_embs, emb)
    idx = int(np.argmax(sims))
    return labels[idx]


def encode_normalized(model: SentenceTransformer, texts: List[str]) -> np.ndarray:
    raw = model.encode(texts, convert_to_numpy=True, show_progress_bar=False)
    arr = np.asarray(raw, dtype=np.float32)
    norms = np.linalg.norm(arr, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    return arr / norms


def dedupe_cosine_union_find(
    texts: List[str], embeddings: np.ndarray, threshold: float
) -> Dict[str, str]:
    """Map each phrase to a canonical representative (shortest in cluster)."""
    n = len(texts)
    parent = list(range(n))

    def find(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    def union(i: int, j: int) -> None:
        ri, rj = find(i), find(j)
        if ri != rj:
            parent[rj] = ri

    for i in range(n):
        for j in range(i + 1, n):
            sim = float(np.dot(embeddings[i], embeddings[j]))
            if sim >= threshold:
                union(i, j)

    groups: Dict[int, List[int]] = {}
    for i in range(n):
        r = find(i)
        groups.setdefault(r, []).append(i)

    mapping: Dict[str, str] = {}
    for idxs in groups.values():
        canon = min((texts[i] for i in idxs), key=len)
        for i in idxs:
            mapping[texts[i]] = canon
    return mapping


def pick_diverse_indices(embeddings: np.ndarray, k: int) -> List[int]:
    """Greedy max-min diversity on unit vectors."""
    n = embeddings.shape[0]
    if n == 0:
        return []
    k = max(1, min(k, n))
    selected: List[int] = [0]
    while len(selected) < k:
        best_i = None
        best_min_sim = -1.0
        for i in range(n):
            if i in selected:
                continue
            mins = min(float(np.dot(embeddings[i], embeddings[j])) for j in selected)
            if best_i is None or mins < best_min_sim:
                best_min_sim = mins
                best_i = i
        if best_i is not None:
            selected.append(best_i)
        else:
            break
    return selected


def _build_seed_suggest_queries(seed: str, max_queries: int) -> List[str]:
    """
    Multiple Google Suggest seeds so Layer-2 gets question-shaped completions
    (what/how/why/best/...) instead of only suffix-style completions of the raw keyword.
    """
    s = _normalize_phrase(seed)
    if not s:
        return []
    queries = [s]
    lowered = s.lower()
    prefixes = [
        "what is ",
        "what are ",
        "what does ",
        "how to ",
        "how ",
        "why ",
        "when ",
        "where ",
        "who ",
        "best ",
        "top ",
        "types of ",
        "is ",
        "can you ",
    ]
    for p in prefixes:
        q = (p + s).strip()
        if len(q) <= 255 and q.lower() not in {x.lower() for x in queries}:
            queries.append(q)
    suffixes = [" vs", " examples", " ideas", " tips", " guide"]
    for suf in suffixes:
        q = (s + suf).strip()
        if len(q) <= 255 and q.lower() not in {x.lower() for x in queries}:
            queries.append(q)
    return queries[:max(1, max_queries)]


def _starts_with_question_word(text: str) -> bool:
    return bool(
        re.match(
            r"^(what|how|why|when|where|who|which|can|does|do|is|are)\b",
            text.lower().strip(),
        )
    )


def _build_l3_suggest_queries(term: str, max_sub: int) -> List[str]:
    """A few suggest seeds per L2 term to pull longer question-style L3 lines."""
    t = _normalize_phrase(term)
    if not t:
        return []
    out = [t]
    if len(t) < 200 and not _starts_with_question_word(t):
        out.append(f"what {t}")
        out.append(f"how to {t}")
    if len(t) < 200 and not t.lower().startswith("why "):
        out.append(f"why {t}")
    return _unique_preserve_order(out)[:max_sub]


async def _collect_l2_pool(
    bounded_suggest,
    seed: str,
    max_seed_queries: int,
    max_l2_candidates: int,
    suggest_errors: List[str],
) -> List[str]:
    queries = _build_seed_suggest_queries(seed, max_seed_queries)
    results = await asyncio.gather(*[bounded_suggest(q) for q in queries])
    pool: List[str] = []
    for sug, err in results:
        if err:
            suggest_errors.append(err)
        pool.extend(sug)
    merged = _unique_preserve_order(pool)
    return merged[:max_l2_candidates]


async def _collect_l3_map(
    bounded_suggest,
    l2_raw: List[str],
    max_l3_each: int,
    max_l3_subqueries: int,
    suggest_errors: List[str],
) -> Dict[str, List[str]]:
    """For each L2 term, merge suggestions from term + question-prefixed variants."""
    l3_map: Dict[str, List[str]] = {}

    async def one_term(term: str) -> Tuple[str, List[str]]:
        subs = _build_l3_suggest_queries(term, max_l3_subqueries)
        merged: List[str] = []
        for sq in subs:
            sug, err = await bounded_suggest(sq)
            if err:
                suggest_errors.append(err)
            merged.extend(sug)
        uniq = _unique_preserve_order(merged)[: max_l3_each * 2]
        return term, uniq

    pairs = await asyncio.gather(*[one_term(t) for t in l2_raw])
    for term, lst in pairs:
        l3_map[term] = lst[:max_l3_each]
    return l3_map


@router.post("/keyword-cluster")
async def keyword_cluster(req: KeywordClusterRequest) -> Dict[str, Any]:
    if tree_embedding_model is None:
        raise HTTPException(status_code=503, detail="Tree embedding model not loaded")

    hl = req.language_code.lower()
    gl = req.gl.lower()
    seed = _normalize_phrase(req.seed)
    dedupe_threshold = float(os.getenv("CLUSTER_TREE_DEDUPE_THRESHOLD", "0.92"))
    max_l3_each = int(os.getenv("CLUSTER_TREE_MAX_L3_EACH", "10"))
    max_concurrent = int(os.getenv("CLUSTER_TREE_MAX_CONCURRENT", "5"))
    l2_branches = int(os.getenv("CLUSTER_TREE_L2_BRANCHES", "6"))
    l3_branches = int(os.getenv("CLUSTER_TREE_L3_BRANCHES", "6"))
    max_seed_queries = int(os.getenv("CLUSTER_TREE_MAX_L2_SUGGEST_QUERIES", "18"))
    max_l2_candidates = int(os.getenv("CLUSTER_TREE_MAX_L2_CANDIDATES", "30"))
    max_l3_subqueries = int(os.getenv("CLUSTER_TREE_MAX_L3_SUBQUERIES", "3"))

    suggest_errors: List[str] = []
    timeout = float(os.getenv("CLUSTER_TREE_SUGGEST_TIMEOUT", "10"))

    async with httpx.AsyncClient(timeout=timeout, headers={"User-Agent": "Mozilla/5.0"}) as client:
        sem = asyncio.Semaphore(max_concurrent)

        async def bounded_suggest(q: str) -> Tuple[List[str], Optional[str]]:
            async with sem:
                return await fetch_suggestions(client, q, hl, gl)

        l2_raw = await _collect_l2_pool(
            bounded_suggest,
            seed,
            max_seed_queries,
            max_l2_candidates,
            suggest_errors,
        )

        l3_map = await _collect_l3_map(
            bounded_suggest,
            l2_raw,
            max_l3_each,
            max_l3_subqueries,
            suggest_errors,
        )

    # Flatten for embedding (unique strings)
    all_texts = _unique_preserve_order(
        [seed] + list(l2_raw) + [s for v in l3_map.values() for s in v]
    )
    if seed not in all_texts:
        all_texts.insert(0, seed)

    if len(all_texts) == 1:
        tree = {
            "id": "root",
            "label": seed,
            "intent": classify_intent(seed),
            "children": [],
        }
        return {
            "schema_version": req.schema_version,
            "seed": seed,
            "tree": tree,
            "meta": {
                "partial": bool(suggest_errors),
                "suggest_errors": suggest_errors[:20],
                "deduped_count": 1,
                "raw_count": 1,
            },
        }

    emb = encode_normalized(tree_embedding_model, all_texts)
    mapping = dedupe_cosine_union_find(all_texts, emb, dedupe_threshold)

    deduped_list = _unique_preserve_order(list(dict.fromkeys(mapping[t] for t in all_texts)))
    emb_d = encode_normalized(tree_embedding_model, deduped_list)
    d_idx = {t: i for i, t in enumerate(deduped_list)}

    seed_canon = mapping.get(seed, seed)
    if seed_canon not in d_idx:
        deduped_list.insert(0, seed_canon)
        emb_d = encode_normalized(tree_embedding_model, deduped_list)
        d_idx = {t: i for i, t in enumerate(deduped_list)}

    seed_i = d_idx[seed_canon]
    seed_vec = emb_d[seed_i]

    # L2 candidates: direct children from suggest, canonicalized
    l2_candidates = _unique_preserve_order(
        [mapping[t] for t in l2_raw if mapping.get(t, t) != seed_canon]
    )
    l2_candidates = [t for t in l2_candidates if t in d_idx]

    proto_labels = ["informational", "commercial", "transactional", "navigational"]
    proto_texts = [
        "how to learn what is guide tutorial",
        "best top reviews compare vs pricing",
        "buy price order cheap discount sale",
        "official website login homepage brand com",
    ]
    proto_emb = encode_normalized(tree_embedding_model, proto_texts)

    def intent_for(t: str, vec: np.ndarray) -> str:
        base = classify_intent(t)
        if base == "informational" and len(t.split()) <= 2:
            fb = _embedding_fallback_intent(t, vec, proto_emb, proto_labels)
            return fb
        return base

    if not l2_candidates:
        tree = {
            "id": "root",
            "label": seed_canon,
            "intent": intent_for(seed_canon, seed_vec),
            "children": [],
        }
        return {
            "schema_version": req.schema_version,
            "seed": seed,
            "tree": tree,
            "meta": {
                "partial": True,
                "suggest_errors": suggest_errors[:20],
                "deduped_count": len(deduped_list),
                "raw_count": len(all_texts),
            },
        }

    l2_emb_idx = [d_idx[t] for t in l2_candidates]
    l2_emb = emb_d[l2_emb_idx]
    sim_to_seed = np.dot(l2_emb, seed_vec)
    order = np.argsort(-sim_to_seed)
    ranked_l2 = [l2_candidates[int(i)] for i in order]

    diverse_k = max(4, min(l2_branches, len(ranked_l2)))
    div_idx_in_ranked = pick_diverse_indices(
        emb_d[[d_idx[t] for t in ranked_l2]], diverse_k
    )
    selected_l2 = [ranked_l2[i] for i in div_idx_in_ranked][:diverse_k]

    children_nodes: List[Dict[str, Any]] = []
    nid = 0

    for l2 in selected_l2:
        nid += 1
        parent_id = f"n{nid}"
        parent_vec = emb_d[d_idx[l2]]
        raw_l3: List[str] = []
        for term in l2_raw:
            if mapping.get(term, term) == l2:
                raw_l3.extend(l3_map.get(term, []))
        raw_l3 = _unique_preserve_order([mapping.get(x, x) for x in raw_l3])
        # Per-branch only: exclude seed and this L2 parent; allow same L3 under multiple L2 (AlsoAsked-style).
        raw_l3 = [
            x
            for x in raw_l3
            if x in d_idx and x != l2 and x != seed_canon
        ]

        if not raw_l3:
            children_nodes.append(
                {
                    "id": parent_id,
                    "label": l2,
                    "intent": intent_for(l2, parent_vec),
                    "children": [],
                }
            )
            continue

        l3_emb = emb_d[[d_idx[t] for t in raw_l3]]
        sims = np.dot(l3_emb, parent_vec)
        order3 = np.argsort(-sims)
        ranked_l3 = [raw_l3[int(i)] for i in order3]

        take_k = max(4, min(l3_branches, len(ranked_l3)))
        div_l3 = pick_diverse_indices(
            emb_d[[d_idx[t] for t in ranked_l3]], take_k
        )
        chosen_l3 = [ranked_l3[i] for i in div_l3][:take_k]

        leaf_nodes = []
        for c in chosen_l3:
            nid += 1
            cv = emb_d[d_idx[c]]
            leaf_nodes.append(
                {
                    "id": f"n{nid}",
                    "label": c,
                    "intent": intent_for(c, cv),
                    "children": [],
                }
            )

        children_nodes.append(
            {
                "id": parent_id,
                "label": l2,
                "intent": intent_for(l2, parent_vec),
                "children": leaf_nodes,
            }
        )

    tree = {
        "id": "root",
        "label": seed_canon,
        "intent": intent_for(seed_canon, seed_vec),
        "children": children_nodes,
    }

    return {
        "schema_version": req.schema_version,
        "seed": seed,
        "tree": tree,
        "meta": {
            "partial": bool(suggest_errors),
            "suggest_errors": suggest_errors[:20],
            "deduped_count": len(deduped_list),
            "raw_count": len(all_texts),
        },
    }
