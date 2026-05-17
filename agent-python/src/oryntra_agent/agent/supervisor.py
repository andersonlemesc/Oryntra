from datetime import UTC, datetime
from functools import lru_cache
from typing import Any, Literal

from langgraph.checkpoint.memory import InMemorySaver
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
    turn_count: int


def run_chatwoot_runtime(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    graph = get_runtime_graph()
    result = graph.invoke(
        {"payload": payload},
        runtime_config(payload),
    )
    response = result.get("response")

    if not isinstance(response, ChatwootRuntimeResponse):
        raise RuntimeError("Supervisor graph did not produce a runtime response.")

    return response


@lru_cache(maxsize=1)
def get_runtime_graph() -> Any:
    return build_runtime_graph()


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

    return builder.compile(checkpointer=InMemorySaver())


def runtime_config(payload: ChatwootRuntimeRequest) -> dict[str, dict[str, str]]:
    return {"configurable": {"thread_id": payload.thread_id}}


def route_node(state: SupervisorState) -> SupervisorState:
    payload = state["payload"]
    turn_count = state.get("turn_count", 0) + 1

    if payload.agent_mode != "supervisor":
        return {
            "selected_specialist": None,
            "confidence": 1.0,
            "reason": "single_agent",
            "turn_count": turn_count,
        }

    selected_specialist, confidence, reason = choose_specialist(payload)

    return {
        "selected_specialist": selected_specialist,
        "confidence": confidence,
        "reason": reason,
        "turn_count": turn_count,
    }


def route_after_decision(_state: SupervisorState) -> Literal["respond", "__end__"]:
    return "respond"


def respond_node(state: SupervisorState) -> SupervisorState:
    payload = state["payload"]
    selected_specialist = state.get("selected_specialist")
    confidence = state.get("confidence", 0.0)
    reason = state.get("reason", "unknown")
    turn_count = state.get("turn_count", 1)

    if payload.agent_mode != "supervisor":
        return {"response": single_agent_response(payload, turn_count=turn_count)}

    if selected_specialist is None:
        return {
            "response": no_route_response(
                payload=payload,
                confidence=confidence,
                reason=reason,
                turn_count=turn_count,
            )
        }

    return {
        "response": routed_specialist_response(
            payload=payload,
            selected_specialist=selected_specialist,
            confidence=confidence,
            reason=reason,
            turn_count=turn_count,
        )
    }


def single_agent_response(payload: ChatwootRuntimeRequest, turn_count: int) -> ChatwootRuntimeResponse:
    message_count = len(payload.messages)

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=f"[mock] Recebi {message_count} mensagem(ns).",
            confidence=1.0,
        ),
        trace=[
            runtime_trace_step(payload=payload, turn_count=turn_count),
        ],
    )


def no_route_response(
    payload: ChatwootRuntimeRequest,
    confidence: float,
    reason: str,
    turn_count: int,
) -> ChatwootRuntimeResponse:
    return ChatwootRuntimeResponse(
        status="waiting_human",
        response=RuntimeResponsePayload(
            type="clarify",
            content="Preciso de mais detalhes para escolher o especialista correto.",
            handoff_reason="no_confident_specialist_route",
            confidence=confidence,
        ),
        trace=[
            runtime_trace_step(payload=payload, turn_count=turn_count),
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
    turn_count: int,
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
            runtime_trace_step(payload=payload, turn_count=turn_count),
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


def runtime_trace_step(payload: ChatwootRuntimeRequest, turn_count: int) -> TraceStep:
    return TraceStep(
        step=1,
        type="runtime_mock",
        input={
            "agent_mode": payload.agent_mode,
            "message_count": len(payload.messages),
            "thread_id": payload.thread_id,
            "turn_count": turn_count,
        },
        output={"response_type": "text"},
        ts=datetime.now(UTC),
    )
