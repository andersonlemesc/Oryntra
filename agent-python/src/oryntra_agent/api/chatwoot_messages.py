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
    selected_specialist = payload.specialists[0] if payload.agent_mode == "supervisor" and payload.specialists else None
    trace = [
        TraceStep(
            step=1,
            type="runtime_mock",
            input={
                "agent_mode": payload.agent_mode,
                "message_count": message_count,
                "thread_id": payload.thread_id,
            },
            output={"response_type": "text"},
            ts=datetime.now(UTC),
        )
    ]

    if selected_specialist is not None:
        trace.extend(
            [
                TraceStep(
                    step=2,
                    type="supervisor_route",
                    specialist_id=selected_specialist.id,
                    input={
                        "specialists": [specialist.name for specialist in payload.specialists],
                    },
                    output={
                        "specialist_id": selected_specialist.id,
                        "specialist_name": selected_specialist.name,
                        "confidence": 1.0,
                    },
                    ts=datetime.now(UTC),
                ),
                TraceStep(
                    step=3,
                    type="specialist_response",
                    specialist_id=selected_specialist.id,
                    input={"role_prompt": selected_specialist.role_prompt},
                    output={"response_type": "text"},
                    ts=datetime.now(UTC),
                ),
            ]
        )

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=(
                f"[mock] {selected_specialist.name} recebeu {message_count} mensagem(ns)."
                if selected_specialist is not None
                else f"[mock] Recebi {message_count} mensagem(ns)."
            ),
            confidence=1.0,
        ),
        specialist_id=selected_specialist.id if selected_specialist is not None else None,
        trace=trace,
    )
