"""Playground streaming endpoint.

Mirrors the Chatwoot runtime contract but streams the run over SSE so the
Laravel panel can render tokens and debug events (routing, tool calls/results)
live. Unlike ``/internal/chatwoot/messages`` it does not deliver anything to
Chatwoot — delivery (and persistence) is handled by Laravel.
"""

import json
from collections.abc import AsyncIterator
from typing import Any

from fastapi import APIRouter, Depends
from fastapi.responses import StreamingResponse

from oryntra_agent.agent.supervisor import stream_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest
from oryntra_agent.auth import verify_internal_token

router = APIRouter(
    prefix="/internal/playground",
    tags=["playground"],
    dependencies=[Depends(verify_internal_token)],
)


def _sse(event: str, data: dict[str, Any]) -> str:
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"


@router.post("/stream")
async def stream_playground(payload: ChatwootRuntimeRequest) -> StreamingResponse:
    async def event_stream() -> AsyncIterator[str]:
        async for item in stream_chatwoot_runtime(payload):
            item_type = item.get("type")

            if item_type == "token":
                yield _sse("token", {"delta": item.get("delta", "")})
            elif item_type == "routing":
                yield _sse(
                    "routing",
                    {
                        "specialist_id": item.get("specialist_id"),
                        "confidence": item.get("confidence"),
                        "reason": item.get("reason"),
                    },
                )
            elif item_type == "tool_call":
                yield _sse("tool_call", {"tool": item.get("tool"), "input": item.get("input")})
            elif item_type == "tool_result":
                yield _sse("tool_result", {"tool": item.get("tool"), "output": item.get("output")})
            elif item_type == "final":
                yield _sse("final", item.get("data", {}))
            elif item_type == "error":
                yield _sse("error", {"message": item.get("message", "unknown error")})

    return StreamingResponse(
        event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
        },
    )
