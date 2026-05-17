from datetime import UTC, datetime

from oryntra_agent.api.schemas import (
    ChatwootRuntimeRequest,
    ChatwootRuntimeResponse,
    RuntimeResponsePayload,
    SpecialistConfig,
    TraceStep,
)


def run_chatwoot_runtime(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    if payload.agent_mode == "supervisor":
        return run_supervisor(payload)

    return single_agent_response(payload)


def single_agent_response(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    message_count = len(payload.messages)

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=f"[mock] Recebi {message_count} mensagem(ns).",
            confidence=1.0,
        ),
        trace=[
            runtime_trace_step(payload=payload),
        ],
    )


def run_supervisor(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    selected_specialist, confidence, reason = choose_specialist(payload)

    if selected_specialist is None:
        return ChatwootRuntimeResponse(
            status="waiting_human",
            response=RuntimeResponsePayload(
                type="clarify",
                content="Preciso de mais detalhes para escolher o especialista correto.",
                handoff_reason="no_confident_specialist_route",
                confidence=confidence,
            ),
            trace=[
                runtime_trace_step(payload=payload),
                TraceStep(
                    step=2,
                    type="supervisor_route",
                    input={
                        "specialists": [specialist.name for specialist in payload.specialists],
                    },
                    output={
                        "specialist_id": None,
                        "confidence": confidence,
                        "reason": reason,
                    },
                    ts=datetime.now(UTC),
                ),
            ],
        )

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=f"[mock] {selected_specialist.name} recebeu {len(payload.messages)} mensagem(ns).",
            confidence=confidence,
        ),
        specialist_id=selected_specialist.id,
        trace=[
            runtime_trace_step(payload=payload),
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
                    "confidence": confidence,
                    "reason": reason,
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
        ],
    )


def choose_specialist(payload: ChatwootRuntimeRequest) -> tuple[SpecialistConfig | None, float, str]:
    message_text = " ".join(message.content or "" for message in payload.messages).casefold()
    best_specialist: SpecialistConfig | None = None
    best_matches = 0

    for specialist in payload.specialists:
        matches = sum(1 for keyword in specialist.intent_keywords if keyword.casefold() in message_text)

        if matches > best_matches:
            best_specialist = specialist
            best_matches = matches

    if best_specialist is None or best_matches <= 0:
        return None, 0.0, "no_keyword_match"

    confidence = min(1.0, best_matches / max(len(best_specialist.intent_keywords), 1))

    if confidence < best_specialist.confidence_threshold:
        return None, confidence, "below_confidence_threshold"

    return best_specialist, confidence, "keyword_match"


def runtime_trace_step(payload: ChatwootRuntimeRequest) -> TraceStep:
    return TraceStep(
        step=1,
        type="runtime_mock",
        input={
            "agent_mode": payload.agent_mode,
            "message_count": len(payload.messages),
            "thread_id": payload.thread_id,
        },
        output={"response_type": "text"},
        ts=datetime.now(UTC),
    )
