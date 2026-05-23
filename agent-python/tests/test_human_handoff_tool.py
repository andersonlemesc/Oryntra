import pytest

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import (
    SpecialistDecision,
    get_runtime_graph,
    run_chatwoot_runtime,
)
from oryntra_agent.agent.tools import (
    HumanHandoffRequest,
    HumanHandoffResponse,
    request_human_handoff,
)
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


@pytest.fixture(autouse=True)
def clear_runtime_graph_cache() -> None:
    get_runtime_graph.cache_clear()
    settings_module.settings.langgraph_checkpointer = "memory"


def test_request_human_handoff_posts_to_laravel_gateway(monkeypatch, httpx_mock) -> None:
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.laravel_internal_base_url",
        "http://laravel-app",
    )
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.agent_runtime_internal_token",
        "ci-token",
    )
    httpx_mock.add_response(
        method="POST",
        url="http://laravel-app/api/internal/agent-tools/request-human-handoff",
        json={
            "status": "handoff_dispatched",
            "handoff_id": 55,
            "message": "Human handoff requested.",
        },
    )

    response = request_human_handoff(
        HumanHandoffRequest(
            workspace_id=1,
            agent_id=10,
            agent_run_id=55,
            thread_id="workspace:1:account:5:conversation:99",
            conversation_id=99,
            specialist_id=5,
            reason="Cliente pediu humano.",
            priority="normal",
            suggested_team="suporte",
            customer_message="Vou transferir voce para um atendente.",
        )
    )

    request = httpx_mock.get_request()

    assert response.status == "handoff_dispatched"
    assert response.handoff_id == 55
    assert request is not None
    assert request.headers["Accept"] == "application/json"
    assert request.headers["X-Internal-Token"] == "ci-token"
    assert request.url.path == "/api/internal/agent-tools/request-human-handoff"


def handoff_payload(tools: list[str]) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:99",
            "runtime_config": {
                "agent_run_id": 55,
                "conversation_id": 99,
            },
            "messages": [{"id": "123", "content": "quero falar com humano"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Ajude em suporte. Transfira para humano quando solicitado.",
                    "llm_temperature": 0.2,
                    "tools": tools,
                    "intent_keywords": ["humano", "suporte"],
                    "confidence_threshold": 0.5,
                }
            ],
        }
    )


def test_specialist_can_request_human_handoff_when_tool_is_allowed(monkeypatch) -> None:
    payload = handoff_payload(["request_human_handoff"])

    monkeypatch.setattr(
        supervisor,
        "generate_specialist_response_with_llm",
        lambda payload, selected_specialist: "__REQUEST_HUMAN_HANDOFF__",
    )
    monkeypatch.setattr(
        supervisor,
        "request_human_handoff",
        lambda request: HumanHandoffResponse(
            status="handoff_dispatched",
            handoff_id=55,
            message="Human handoff requested.",
        ),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.type == "escalate"
    assert response.response.handoff_reason == "Human handoff requested by specialist."
    assert response.trace[2].type == "tool_call"
    assert response.trace[2].tool == "request_human_handoff"


def test_configured_handoff_rule_requests_human_handoff(monkeypatch) -> None:
    payload = ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:99",
            "runtime_config": {
                "agent_run_id": 55,
                "conversation_id": 99,
            },
            "messages": [{"id": "123", "content": "quero cancelar meu contrato"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Ajude em suporte.",
                    "llm_temperature": 0.2,
                    "tools": ["request_human_handoff"],
                    "handoff_config": {
                        "enabled": True,
                        "default_priority": "normal",
                        "customer_message": "Vou transferir voce para um atendente.",
                        "private_note": "Motivo: {reason}",
                        "assign_strategy": "team_then_agent",
                        "team_id": 12,
                        "agent_id": 34,
                        "rules": [
                            {
                                "name": "Cancelamento",
                                "enabled": True,
                                "keywords": ["cancelar"],
                                "priority": "high",
                                "reason": "Cliente pediu cancelamento.",
                            }
                        ],
                    },
                    "intent_keywords": ["cancelar", "suporte"],
                    "confidence_threshold": 0.5,
                }
            ],
        }
    )

    monkeypatch.setattr(
        supervisor,
        "request_human_handoff",
        lambda request: HumanHandoffResponse(
            status="handoff_dispatched",
            handoff_id=55,
            message="Human handoff requested.",
        ),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.handoff_reason == "Cliente pediu cancelamento."
    assert response.trace[2].input["source"] == "handoff_rule"
    assert response.trace[2].input["rule"] == "Cancelamento"
    assert response.trace[2].input["priority"] == "high"


def test_structured_specialist_decision_requests_human_handoff(monkeypatch) -> None:
    payload = handoff_payload(["request_human_handoff"])

    monkeypatch.setattr(
        supervisor,
        "generate_specialist_decision_with_llm",
        lambda payload, selected_specialist: SpecialistDecision(
            action="request_human_handoff",
            content="Vou transferir voce para um atendente.",
            handoff_reason="Cliente pediu atendimento humano.",
            handoff_priority="urgent",
            confidence=0.9,
        ),
    )
    monkeypatch.setattr(
        supervisor,
        "request_human_handoff",
        lambda request: HumanHandoffResponse(
            status="handoff_dispatched",
            handoff_id=55,
            message="Human handoff requested.",
        ),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.content == "Vou transferir voce para um atendente."
    assert response.response.handoff_reason == "Cliente pediu atendimento humano."
    assert response.trace[2].input["source"] == "structured_decision"
    assert response.trace[2].input["priority"] == "urgent"


def test_specialist_cannot_request_human_handoff_without_allowlist(monkeypatch) -> None:
    payload = handoff_payload([])

    monkeypatch.setattr(
        supervisor,
        "generate_specialist_response_with_llm",
        lambda payload, selected_specialist: "__REQUEST_HUMAN_HANDOFF__",
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.content != "__REQUEST_HUMAN_HANDOFF__"
    assert response.trace[2].output["source"] == "blocked_tool"
