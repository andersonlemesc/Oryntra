from __future__ import annotations

from typing import Any
from unittest.mock import MagicMock

from langchain_core.messages import AIMessage

from oryntra_agent.agent.tool_runtime import (
    ToolRuntimeContext,
    build_specialist_tools,
    run_specialist_tool_loop,
)


def make_ctx(contact_id: int | None = 7) -> ToolRuntimeContext:
    return ToolRuntimeContext(
        workspace_id=1,
        agent_id=10,
        agent_run_id=55,
        specialist_id=5,
        contact_id=contact_id,
        conversation_id=99,
    )


def test_build_specialist_tools_filters_by_allowlist() -> None:
    ctx = make_ctx()

    tools = build_specialist_tools(
        ["chatwoot_update_contact", "update_contact_memory", "request_human_handoff"],
        ctx,
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["chatwoot_update_contact", "update_contact_memory"]


def test_build_specialist_tools_returns_empty_without_contact() -> None:
    tools = build_specialist_tools(
        ["chatwoot_update_contact", "update_contact_memory"],
        make_ctx(contact_id=None),
    )

    assert tools == []


def test_tool_loop_dispatches_tool_then_returns_text(monkeypatch) -> None:
    captured_payloads: list[Any] = []

    def fake_update_contact(payload):
        captured_payloads.append(payload)

        class FakeResponse:
            status = "ok"

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.chatwoot_update_contact",
        fake_update_contact,
    )

    tools = build_specialist_tools(["chatwoot_update_contact"], make_ctx())

    # Fake chat model: first call returns a tool_call, second call returns final text.
    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "call_1",
                "name": "chatwoot_update_contact",
                "args": {"email": "anderson@example.com"},
            }
        ],
    )
    second = AIMessage(content="Anotei seu email, Anderson!")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="meu email é anderson@example.com",
    )

    assert result.text == "Anotei seu email, Anderson!"
    assert len(result.tool_calls) == 1
    assert result.tool_calls[0]["tool"] == "chatwoot_update_contact"
    assert result.tool_calls[0]["input"] == {"email": "anderson@example.com"}
    assert "ok" in result.tool_calls[0]["output"]
    assert captured_payloads[0].email == "anderson@example.com"
    assert captured_payloads[0].contact_id == 7


def test_tool_loop_returns_text_when_model_does_not_call_tools() -> None:
    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.return_value = AIMessage(content="Sem necessidade de tool.")
    chat_model.bind_tools.return_value = bound

    tools = build_specialist_tools(["chatwoot_update_contact"], make_ctx())

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="obrigado",
    )

    assert result.text == "Sem necessidade de tool."
    assert result.tool_calls == []


def test_tool_loop_handles_tool_exception(monkeypatch) -> None:
    def raise_exc(_payload):
        raise RuntimeError("boom")

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.update_contact_memory",
        raise_exc,
    )

    tools = build_specialist_tools(["update_contact_memory"], make_ctx())

    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "x",
                "name": "update_contact_memory",
                "args": {"type": "fact", "content": "x"},
            }
        ],
    )
    second = AIMessage(content="Tive um problema mas continuo aqui.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="grave isso",
    )

    assert result.text == "Tive um problema mas continuo aqui."
    assert result.tool_calls[0]["output"].startswith("error: ")
