from fastapi import FastAPI

from oryntra_agent.api.health import router as health_router

app = FastAPI(
    title="Oryntra Agent Service",
    description="Private LangGraph runtime — invoked by Laravel via internal HTTP.",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)

app.include_router(health_router)
