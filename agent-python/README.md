# Oryntra Agent Service

Serviço Python privado: FastAPI + LangGraph runtime. Invocado pelo Laravel via HTTP interno na rede Docker.

## Stack

- Python 3.12 + uv
- FastAPI + uvicorn
- LangGraph + langgraph-checkpoint-postgres
- LangChain (OpenAI/Anthropic/Gemini — BYOK)
- pgvector + psycopg3
- pytest + ruff + mypy

## Endpoints (planejado)

| Endpoint | Fase |
|---|---|
| `GET /health` | 0 ✅ |
| `POST /agent/run` | 2 |
| `POST /documents/process` | 4 |
| `POST /media/transcribe` | 5 |
| `POST /media/describe` | 5 |

Todos exceto `/health` exigem header `X-Internal-Token`.

## Comandos

```bash
uv sync                         # install deps
uv run uvicorn oryntra_agent.main:app --reload --port 8000
uv run pytest
uv run ruff check .
uv run ruff format .
uv run mypy src/
```

## Estrutura

```
src/oryntra_agent/
├── main.py             # FastAPI app + router includes
├── auth.py             # X-Internal-Token verifier
├── settings.py         # pydantic-settings (.env)
├── agent/              # LangGraph (Fase 2+)
├── rag/                # ingestion + busca (Fase 4)
├── media/              # transcrição + vision (Fase 5)
└── api/                # FastAPI routers
    └── health.py
```
