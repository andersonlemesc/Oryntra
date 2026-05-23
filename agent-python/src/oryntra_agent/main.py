from fastapi import FastAPI

from oryntra_agent.api.chatwoot_messages import router as chatwoot_messages_router
from oryntra_agent.api.health import router as health_router
from oryntra_agent.api.memory_extraction import router as memory_extraction_router

app = FastAPI(
    title="Oryntra Agent Service",
    description="Private LangGraph runtime — invoked by Laravel via internal HTTP.",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)

app.include_router(health_router)
app.include_router(chatwoot_messages_router)
app.include_router(memory_extraction_router)
