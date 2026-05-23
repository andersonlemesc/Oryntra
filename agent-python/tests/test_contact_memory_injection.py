import pytest
from pydantic import SecretStr

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import (
    SpecialistChoice,
    SpecialistDecision,
    contact_memory_section,
    get_runtime_graph,
    run_chatwoot_runtime,
)
from oryntra_agent.api.schemas import (
    ChatwootRuntimeRequest,
    ContactMemorySnapshot,
    MemoryConfig,
    SpecialistConfig,
)


@pytest.fixture(autouse=True)
def clear_runtime_graph_cache() -> None:
    get_runtime_graph.cache_clear()
    settings_module.settings.langgraph_checkpointer = "memory"


def make_payload(*, injection_enabled: bool, limit: int | None, memories: list[dict]) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:42",
            "messages": [{"id": "1", "content": "oi"}],
            "contact": {"id": 7, "memories": memories},
            "specialists": [
                {
                    "id": 6,
                    "name": "Vendas",
                    "role_prompt": "Vendas BikePulse",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["bike"],
                    "confidence_threshold": 0.5,
                    "memory_config": {
                        "injection_enabled": injection_enabled,
                        "injection_limit": limit,
                    },
                },
            ],
        }
    )


def test_contact_memory_section_returns_none_when_injection_disabled() -> None:
    payload = make_payload(
        injection_enabled=False,
        limit=10,
        memories=[{"type": "fact", "content": "Altura 1,72m", "source": "agent_extracted"}],
    )

    assert contact_memory_section(payload, payload.specialists[0]) is None


def test_contact_memory_section_returns_none_when_memories_empty() -> None:
    payload = make_payload(injection_enabled=True, limit=10, memories=[])

    assert contact_memory_section(payload, payload.specialists[0]) is None


def test_contact_memory_section_lists_memories_with_types() -> None:
    payload = make_payload(
        injection_enabled=True,
        limit=None,
        memories=[
            {"type": "preference", "content": "Bike eletrica urbana", "source": "agent_extracted"},
            {"type": "fact", "content": "Altura 1,72m, peso 80kg", "source": "tool"},
        ],
    )

    section = contact_memory_section(payload, payload.specialists[0])

    assert section is not None
    assert "Memorias do contato" in section
    assert "[preference] Bike eletrica urbana" in section
    assert "[fact] Altura 1,72m, peso 80kg" in section


def test_contact_memory_section_respects_injection_limit() -> None:
    memories = [
        {"type": "fact", "content": f"fato {i}", "source": "agent_extracted"}
        for i in range(5)
    ]
    payload = make_payload(injection_enabled=True, limit=2, memories=memories)

    section = contact_memory_section(payload, payload.specialists[0])

    assert section is not None
    assert "fato 0" in section
    assert "fato 1" in section
    assert "fato 2" not in section


def test_specialist_prompt_includes_memories_when_injection_enabled(monkeypatch) -> None:
    captured: dict[str, str] = {}

    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=6, confidence=0.9, reason="match"
        ),
    )

    class FakeChatModel:
        def with_structured_output(self, _schema):
            return self

        def invoke(self, messages):
            captured["system"] = messages[0][1]
            return SpecialistDecision(
                action="respond_text",
                content="Resposta usando memoria",
                confidence=0.9,
            )

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    payload = make_payload(
        injection_enabled=True,
        limit=10,
        memories=[
            {"type": "preference", "content": "Quer bike eletrica urbana", "source": "agent_extracted"},
            {"type": "fact", "content": "Altura 1,72m, peso 80kg", "source": "tool"},
        ],
    )
    payload.specialists[0].llm_provider = "openai"
    payload.specialists[0].llm_model = "gpt-4.1-mini"
    payload.specialists[0].llm_api_key = SecretStr("sk-test")

    run_chatwoot_runtime(payload)

    assert "Memorias do contato" in captured["system"]
    assert "Quer bike eletrica urbana" in captured["system"]
    assert "Altura 1,72m, peso 80kg" in captured["system"]


def test_specialist_prompt_does_not_include_memories_when_injection_disabled(monkeypatch) -> None:
    captured: dict[str, str] = {}

    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=6, confidence=0.9, reason="match"
        ),
    )

    class FakeChatModel:
        def with_structured_output(self, _schema):
            return self

        def invoke(self, messages):
            captured["system"] = messages[0][1]
            return SpecialistDecision(
                action="respond_text",
                content="ok",
                confidence=0.9,
            )

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    payload = make_payload(
        injection_enabled=False,
        limit=10,
        memories=[
            {"type": "fact", "content": "secret memory", "source": "agent_extracted"},
        ],
    )
    payload.specialists[0].llm_provider = "openai"
    payload.specialists[0].llm_model = "gpt-4.1-mini"
    payload.specialists[0].llm_api_key = SecretStr("sk-test")

    run_chatwoot_runtime(payload)

    assert "Memorias do contato" not in captured["system"]
    assert "secret memory" not in captured["system"]


def test_contact_memory_snapshot_schema_strict() -> None:
    snapshot = ContactMemorySnapshot.model_validate(
        {
            "type": "fact",
            "content": "Altura 1,72m",
            "source": "agent_extracted",
        }
    )

    assert snapshot.type == "fact"
    assert snapshot.confidence is None


def test_memory_config_defaults() -> None:
    config = MemoryConfig()
    assert config.extraction_enabled is False
    assert config.injection_enabled is False
    assert config.injection_limit is None


def test_specialist_config_accepts_memory_config() -> None:
    specialist = SpecialistConfig.model_validate(
        {
            "id": 1,
            "name": "Vendas",
            "role_prompt": "p",
            "llm_temperature": 0.2,
            "tools": [],
            "intent_keywords": [],
            "confidence_threshold": 0.5,
            "memory_config": {"injection_enabled": True, "injection_limit": 5},
        }
    )

    assert specialist.memory_config.injection_enabled is True
    assert specialist.memory_config.injection_limit == 5
