from __future__ import annotations

from oryntra_agent.agent.supervisor import (
    contact_basics_section,
    current_datetime_section,
    system_prompt_with_memories,
)
from oryntra_agent.api.schemas import ChatwootRuntimeRequest, SpecialistConfig


def make_payload(
    *,
    contact: dict | None = None,
    runtime_config: dict | None = None,
) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "thread_id": "workspace:1:account:5:conversation:42",
            "messages": [{"id": "1", "content": "oi"}],
            "contact": contact if contact is not None else {},
            "runtime_config": runtime_config if runtime_config is not None else {},
        }
    )


def make_specialist() -> SpecialistConfig:
    return SpecialistConfig.model_validate(
        {
            "id": 6,
            "name": "Vendas",
            "role_prompt": "Voce e Vendas.",
            "llm_temperature": 0.2,
            "tools": [],
            "intent_keywords": ["bike"],
            "confidence_threshold": 0.5,
            "memory_config": {"injection_enabled": False},
        }
    )


def test_contact_basics_section_lists_all_filled_fields() -> None:
    payload = make_payload(
        contact={
            "name": "Anderson",
            "email": "anderson@example.com",
            "phone_number": "+5511990000000",
            "lead_status": "qualificado",
        }
    )

    section = contact_basics_section(payload)

    assert section is not None
    assert "Nome: Anderson" in section
    assert "Email: anderson@example.com" in section
    assert "Telefone: +5511990000000" in section
    assert "Etapa do funil: qualificado" in section


def test_contact_basics_section_skips_empty_strings() -> None:
    payload = make_payload(
        contact={
            "name": "Anderson",
            "email": "",
            "phone_number": None,
            "lead_status": "",
        }
    )

    section = contact_basics_section(payload)

    assert section is not None
    assert "Nome: Anderson" in section
    assert "Email" not in section
    assert "Telefone" not in section
    assert "Etapa do funil" not in section


def test_contact_basics_section_returns_none_when_blank() -> None:
    payload = make_payload(contact={})

    assert contact_basics_section(payload) is None


def test_current_datetime_section_uses_workspace_timezone() -> None:
    payload = make_payload(runtime_config={"workspace_timezone": "America/Sao_Paulo"})

    section = current_datetime_section(payload)

    assert section is not None
    assert "fuso America/Sao_Paulo" in section


def test_current_datetime_section_falls_back_to_utc_for_invalid_tz() -> None:
    payload = make_payload(runtime_config={"workspace_timezone": "Mars/Olympus"})

    section = current_datetime_section(payload)

    assert section is not None
    assert "fuso UTC" in section


def test_current_datetime_section_defaults_to_utc_when_missing() -> None:
    payload = make_payload(runtime_config={})

    section = current_datetime_section(payload)

    assert section is not None
    assert "fuso UTC" in section


def test_system_prompt_with_memories_appends_context_sections() -> None:
    payload = make_payload(
        contact={"name": "Anderson"},
        runtime_config={"workspace_timezone": "UTC"},
    )

    prompt = system_prompt_with_memories(
        payload,
        make_specialist(),
        ["Voce e Vendas.", "Responda bem."],
    )

    assert "Voce e Vendas." in prompt
    assert "Dados do cliente em atendimento:" in prompt
    assert "Data e hora atuais:" in prompt
    assert prompt.index("Voce e Vendas.") < prompt.index("Dados do cliente em atendimento:")
