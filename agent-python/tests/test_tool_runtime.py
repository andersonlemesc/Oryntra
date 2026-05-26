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
        thread_id="workspace:1:account:5:conversation:99",
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


def test_build_specialist_tools_builds_resolve_without_contact() -> None:
    tools = build_specialist_tools(
        ["resolve_conversation"],
        make_ctx(contact_id=None),
        terminal_state={},
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["resolve_conversation"]


def test_build_specialist_tools_builds_query_products_without_contact() -> None:
    tools = build_specialist_tools(
        ["query_products"],
        make_ctx(contact_id=None),
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["query_products"]


def test_build_specialist_tools_builds_send_document_without_contact() -> None:
    tools = build_specialist_tools(
        ["send_document"],
        make_ctx(contact_id=None),
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["send_document"]


def test_build_specialist_tools_skips_resolve_without_terminal_state() -> None:
    tools = build_specialist_tools(
        ["resolve_conversation"],
        make_ctx(),
    )

    assert tools == []


def test_tool_loop_short_circuits_when_resolve_tool_runs(monkeypatch) -> None:
    captured: dict[str, Any] = {}

    def fake_resolve(payload):
        captured["payload"] = payload

        class FakeResponse:
            status = "resolution_dispatched"
            resolution_id = 42

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.resolve_conversation",
        fake_resolve,
    )

    terminal_state: dict[str, Any] = {}
    tools = build_specialist_tools(
        ["resolve_conversation"],
        make_ctx(contact_id=None),
        terminal_state=terminal_state,
    )

    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "call_resolve",
                "name": "resolve_conversation",
                "args": {
                    "reason": "Cliente confirmou.",
                    "resolution_summary": "Cliente entendeu o processo.",
                    "customer_message": "Otimo, ate breve!",
                    "label_name": "resolved-by-ia",
                },
            }
        ],
    )
    follow_up = AIMessage(content="texto que nao deve ser usado")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, follow_up]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="obrigado",
        terminal_state=terminal_state,
    )

    assert result.resolved is True
    assert result.text is None
    assert result.resolution is not None
    assert result.resolution["customer_message"] == "Otimo, ate breve!"
    assert result.resolution["label_name"] == "resolved-by-ia"
    assert result.resolution["resolution_id"] == 42
    assert terminal_state["resolved"] is True
    assert captured["payload"].agent_run_id == 55
    assert captured["payload"].thread_id == "workspace:1:account:5:conversation:99"
    assert bound.invoke.call_count == 1


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


def test_tool_loop_dispatches_contact_address_update(monkeypatch) -> None:
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

    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "call_address",
                "name": "chatwoot_update_contact",
                "args": {
                    "address_street": "Praca da Se",
                    "address_number": "100",
                    "address_city": "Sao Paulo",
                    "address_state": "SP",
                    "address_postal_code": "01001-000",
                },
            }
        ],
    )
    second = AIMessage(content="Anotei seu endereco de entrega.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="entrega na Praca da Se 100",
    )

    assert result.text == "Anotei seu endereco de entrega."
    assert captured_payloads[0].address_street == "Praca da Se"
    assert captured_payloads[0].address_number == "100"
    assert captured_payloads[0].address_city == "Sao Paulo"
    assert captured_payloads[0].address_state == "SP"


def test_tool_loop_dispatches_query_products(monkeypatch) -> None:
    captured_payloads: list[Any] = []

    def fake_query_products(payload):
        captured_payloads.append(payload)

        class FakeResponse:
            def __init__(self) -> None:
                self.products = [
                    {
                        "name": "Bike Eletrica Urbana",
                        "price": 3499.9,
                        "category": "Bikes",
                        "description": "Autonomia de 50km.",
                    }
                ]
                self.total = 1

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.query_products",
        fake_query_products,
    )

    tools = build_specialist_tools(["query_products"], make_ctx(contact_id=None))

    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "call_products",
                "name": "query_products",
                "args": {"query": "bike", "category": "Bikes", "limit": 5},
            }
        ],
    )
    second = AIMessage(content="Temos a Bike Eletrica Urbana por R$ 3499,90.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="tem bike eletrica?",
    )

    assert result.text == "Temos a Bike Eletrica Urbana por R$ 3499,90."
    assert result.tool_calls[0]["tool"] == "query_products"
    assert "Bike Eletrica Urbana" in result.tool_calls[0]["output"]
    assert captured_payloads[0].workspace_id == 1
    assert captured_payloads[0].agent_run_id == 55
    assert captured_payloads[0].query == "bike"
    assert captured_payloads[0].category == "Bikes"


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


def test_build_specialist_tools_builds_query_documents_without_contact() -> None:
    tools = build_specialist_tools(
        ["query_documents"],
        make_ctx(contact_id=None),
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["query_documents"]


def test_query_products_output_includes_attached_documents(monkeypatch) -> None:
    def fake_query_products(payload):
        class FakeResponse:
            def __init__(self) -> None:
                self.products = [
                    {
                        "name": "Apartamento 101",
                        "price": 250000.0,
                        "category": "Imoveis",
                        "description": "2 quartos.",
                        "documents": [
                            {"id": 7, "original_filename": "planta-101.pdf"},
                        ],
                    }
                ]
                self.total = 1

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.query_products",
        fake_query_products,
    )

    tools = build_specialist_tools(["query_products"], make_ctx(contact_id=None))

    first = AIMessage(
        content="",
        tool_calls=[{"id": "c1", "name": "query_products", "args": {"query": "apartamento"}}],
    )
    second = AIMessage(content="Segue a planta.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="tem planta do apartamento?",
    )

    output = result.tool_calls[0]["output"]
    assert "document_id=7" in output
    assert "document_type='product'" in output
    assert "planta-101.pdf" in output


def test_tool_loop_dispatches_query_documents(monkeypatch) -> None:
    captured_payloads: list[Any] = []

    def fake_query_documents(payload):
        captured_payloads.append(payload)

        class FakeResponse:
            def __init__(self) -> None:
                self.documents = [
                    {"id": 12, "title": "Catalogo 2026", "category": "catalog"},
                ]
                self.total = 1

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.query_documents",
        fake_query_documents,
    )

    tools = build_specialist_tools(["query_documents"], make_ctx(contact_id=None))

    first = AIMessage(
        content="",
        tool_calls=[{"id": "d1", "name": "query_documents", "args": {"category": "catalog"}}],
    )
    second = AIMessage(content="Achei o catalogo.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="tem catalogo?",
    )

    output = result.tool_calls[0]["output"]
    assert "document_id=12" in output
    assert "document_type='standalone'" in output
    assert captured_payloads[0].category == "catalog"
    assert captured_payloads[0].specialist_id == 5


def test_send_document_passes_document_type(monkeypatch) -> None:
    captured_payloads: list[Any] = []

    def fake_send_document(payload):
        captured_payloads.append(payload)

        class FakeResponse:
            sent = True
            count = 2
            error = None

            def __init__(self) -> None:
                self.filenames = ["foto-1.jpg", "foto-2.jpg"]

        return FakeResponse()

    monkeypatch.setattr(
        "oryntra_agent.agent.tool_runtime.send_document",
        fake_send_document,
    )

    tools = build_specialist_tools(["send_document"], make_ctx(contact_id=None))

    first = AIMessage(
        content="",
        tool_calls=[
            {
                "id": "s1",
                "name": "send_document",
                "args": {
                    "document_ids": [7, 8],
                    "document_type": "product",
                    "caption": "Fotos da bike",
                },
            }
        ],
    )
    second = AIMessage(content="Enviei as fotos.")

    chat_model = MagicMock()
    bound = MagicMock()
    bound.invoke.side_effect = [first, second]
    chat_model.bind_tools.return_value = bound

    result = run_specialist_tool_loop(
        chat_model=chat_model,
        tools=tools,
        system_prompt="system",
        user_prompt="manda as fotos da bike",
    )

    assert "foto-1.jpg" in result.tool_calls[0]["output"]
    assert "foto-2.jpg" in result.tool_calls[0]["output"]
    assert captured_payloads[0].document_ids == [7, 8]
    assert captured_payloads[0].document_type == "product"
