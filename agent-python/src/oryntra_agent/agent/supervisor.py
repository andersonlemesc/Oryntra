from datetime import UTC, datetime
from typing import Any, Literal

from langgraph.graph import END, START, StateGraph
from typing_extensions import TypedDict

from oryntra_agent.api.schemas import (
    ChatwootRuntimeRequest,
    ChatwootRuntimeResponse,
    RuntimeResponsePayload,
    SpecialistConfig,
    TraceStep,
)


class SupervisorState(TypedDict, total=False):
    payload: ChatwootRuntimeRequest
    selected_specialist: SpecialistConfig | None
    confidence: float
    reason: str
    response: ChatwootRuntimeResponse


def run_chatwoot_runtime(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    graph = build_runtime_graph()
    result = graph.invoke(
        {"payload": payload},
        {"configurable": {"thread_id": payload.thread_id}},
    )
    response = result.get("response")

    if not isinstance(response, ChatwootRuntimeResponse):
        raise RuntimeError("Supervisor graph did not produce a runtime response.")

    return response


def build_runtime_graph() -> Any:
    builder = StateGraph(SupervisorState)

    builder.add_node("route", route_node)
    builder.add_node("respond", respond_node)

    builder.add_edge(START, "route")
    builder.add_conditional_edges(
        "route",
        route_after_decision,
        path_map={"respond": "respond", "__end__": END},
    )
    builder.add_edge("respond", END)

    return builder.compile()


def route_node(state: SupervisorState) -> SupervisorState:
    payload = state["payload"]

    if payload.agent_mode != "supervisor":
        return {
            "selected_specialist": None,
            "confidence": 1.0,
            "reason": "single_agent",
        }

    selected_specialist, confidence, reason = choose_specialist(payload)

    return {
        "selected_specialist": selected_specialist,
        "confidence": confidence,
        "reason": reason,
    }


def route_after_decision(_state: SupervisorState) -> Literal["respond", "__end__"]:
    return "respond"


def respond_node(state: SupervisorState) -> SupervisorState:
    payload = state["payload"]
    selected_specialist = state.get("selected_specialist")
    confidence = state.get("confidence", 0.0)
    reason = state.get("reason", "unknown")

    if payload.agent_mode != "supervisor":
        return {"response": single_agent_response(payload)}

    if selected_specialist is None:
        return {"response": no_route_response(payload=payload, confidence=confidence, reason=reason)}

    return {"response": routed_specialist_response(
        payload=payload,
        selected_specialist=selected_specialist,
        confidence=confidence,
        reason=reason,
    )}


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


def no_route_response(payload: ChatwootRuntimeRequest, confidence: float, reason: str) -> ChatwootRuntimeResponse:
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


def routed_specialist_response(
    payload: ChatwootRuntimeRequest,
    selected_specialist: SpecialistConfig,
    confidence: float,
    reason: str,
) -> ChatwootRuntimeResponse:
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
