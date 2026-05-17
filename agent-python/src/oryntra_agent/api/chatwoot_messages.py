from datetime import UTC, datetime

from fastapi import APIRouter, Depends

from oryntra_agent.api.schemas import (
    ChatwootRuntimeRequest,
    ChatwootRuntimeResponse,
    RuntimeResponsePayload,
    TraceStep,
)
from oryntra_agent.auth import verify_internal_token

router = APIRouter(
    prefix="/internal/chatwoot",
    tags=["chatwoot-runtime"],
    dependencies=[Depends(verify_internal_token)],
)


@router.post("/messages", response_model=ChatwootRuntimeResponse)
async def handle_chatwoot_messages(
    payload: ChatwootRuntimeRequest,
) -> ChatwootRuntimeResponse:
    message_count = len(payload.messages)

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=f"[mock] Recebi {message_count} mensagem(ns).",
            confidence=1.0,
        ),
        trace=[
            TraceStep(
                step=1,
                type="runtime_mock",
                input={
                    "message_count": message_count,
                    "thread_id": payload.thread_id,
                },
                output={"response_type": "text"},
                ts=datetime.now(UTC),
            )
        ],
    )
