from unittest.mock import patch

import pytest

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import (
    SpecialistChoice,
    get_runtime_graph,
    run_chatwoot_runtime,
)
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


@pytest.fixture(autouse=True)
def clear_runtime_graph_cache() -> None:
    get_runtime_graph.cache_clear()
    settings_module.settings.langgraph_checkpointer = "memory"


def make_payload(*, fallback_specialist_id: int | None) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "fallback_specialist_id": fallback_specialist_id,
            "thread_id": "workspace:1:account:5:conversation:fallback",
            "messages": [{"id": "1", "content": "salva meu email"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Suporte",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["suporte"],
                    "confidence_threshold": 0.5,
                },
                {
                    "id": 6,
                    "name": "Vendas",
                    "role_prompt": "Vendas",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["comprar"],
                    "confidence_threshold": 0.5,
                },
            ],
        }
    )


def test_supervisor_uses_fallback_when_llm_returns_null_specialist(monkeypatch) -> None:
    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=None, confidence=0.3, reason="ambiguous"
        ),
    )

    payload = make_payload(fallback_specialist_id=6)

    with patch.object(supervisor, "generate_specialist_decision_with_llm", return_value=None), \
        patch.object(supervisor, "generate_specialist_response_with_llm", return_value=None):
        response = run_chatwoot_runtime(payload)

    assert response.specialist_id == 6
    assert response.trace[1].output["specialist_id"] == 6
    assert "fallback_specialist" in response.trace[1].output["reason"]


def test_supervisor_does_not_fallback_when_disabled(monkeypatch) -> None:
    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=None, confidence=0.3, reason="ambiguous"
        ),
    )

    payload = make_payload(fallback_specialist_id=None)

    response = run_chatwoot_runtime(payload)

    assert response.specialist_id is None
    assert response.response.type == "clarify"


def test_supervisor_does_not_fallback_when_fallback_id_unknown(monkeypatch) -> None:
    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=None, confidence=0.3, reason="ambiguous"
        ),
    )

    payload = make_payload(fallback_specialist_id=999)

    response = run_chatwoot_runtime(payload)

    assert response.specialist_id is None
