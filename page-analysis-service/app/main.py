import time
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from prometheus_fastapi_instrumentator import Instrumentator

from app.api.routes import router
from app.core.logging import setup_logging
from app.core.http_client import get_http_client, close_http_client

setup_logging()

logger = logging.getLogger("request")


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup: ensure client exists. Shutdown: close connection pool."""
    yield
    await close_http_client()


app = FastAPI(title="Page Analysis Service", lifespan=lifespan)

app.include_router(router)

Instrumentator().instrument(app).expose(app)


@app.get("/health")
def health():
    return {"status": "ok"}


@app.middleware("http")
async def log_requests(request: Request, call_next):
    start_time = time.time()
    response = await call_next(request)
    duration = time.time() - start_time
    logger.info(
        f"{request.method} {request.url.path} "
        f"status={response.status_code} "
        f"duration={duration:.3f}s"
    )
    return response
