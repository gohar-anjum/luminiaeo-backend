"""
Grep-friendly step logging for POST /analyze.
Logger name: page_analysis.pipeline
Example: grep page_analysis.pipeline container.log
"""
from __future__ import annotations

import logging
import time
from collections.abc import Iterator
from contextlib import contextmanager

logger = logging.getLogger("page_analysis.pipeline")


def _safe_str(value: object, max_len: int = 200) -> str:
    s = str(value).replace("\n", " ").replace("\r", " ")
    if len(s) > max_len:
        return s[: max_len - 1] + "…"
    return s


def log_step(step: str, **fields: object) -> None:
    """Log one pipeline step with optional key=value fields."""
    if not fields:
        logger.info("%s", step)
        return
    tail = " ".join(f"{k}={_safe_str(v)}" for k, v in fields.items() if v is not None)
    logger.info("%s | %s", step, tail)


@contextmanager
def log_duration(step: str, **fields: object) -> Iterator[None]:
    """Log step_start / step_done with elapsed_ms."""
    log_step(f"{step}_start", **fields)
    t0 = time.perf_counter()
    try:
        yield
    finally:
        ms = round((time.perf_counter() - t0) * 1000, 1)
        log_step(f"{step}_done", elapsed_ms=ms, **fields)
