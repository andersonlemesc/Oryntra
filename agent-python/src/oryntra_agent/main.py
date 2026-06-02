import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from oryntra_agent.api.chatwoot_messages import router as chatwoot_messages_router
from oryntra_agent.api.health import router as health_router
from oryntra_agent.api.memory_extraction import router as memory_extraction_router
from oryntra_agent.api.playground import router as playground_router
from oryntra_agent.api.rag import router as rag_router
from oryntra_agent.manage import setup_checkpointer

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncIterator[None]:
    """Ensure the LangGraph Postgres checkpoint tables exist before serving.

    ``checkpointer.setup()`` is idempotent — safe to run on every boot.
    """
    try:
        setup_checkpointer()
        logger.info("LangGraph checkpointer tables verified/created.")
    except Exception:
        logger.exception("Failed to set up LangGraph checkpointer tables on startup.")

    yield


app = FastAPI(
    title="Oryntra Agent Service",
    description="Private LangGraph runtime — invoked by Laravel via internal HTTP.",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
    lifespan=lifespan,
)

app.include_router(health_router)
app.include_router(chatwoot_messages_router)
app.include_router(memory_extraction_router)
app.include_router(playground_router)
app.include_router(rag_router)
