from __future__ import annotations

from typing import Any

import pytest
from pydantic import ValidationError

from oryntra_agent.agent.tool_runtime import (
    ToolRuntimeContext,
    build_external_tool,
    build_specialist_tools,
)
from oryntra_agent.agent.tools import CallExternalToolResponse
from oryntra_agent.api.schemas import ExternalToolConfig, SpecialistConfig


def make_ctx(contact_id: int | None = None) -> ToolRuntimeContext:
    return ToolRuntimeContext(
        workspace_id=1,
        agent_id=10,
        agent_run_id=55,
        specialist_id=5,
        contact_id=contact_id,
        conversation_id=99,
        thread_id="workspace:1:account:5:conversation:99",
    )


def make_cfg() -> ExternalToolConfig:
    return ExternalToolConfig(
        slug="query_orders",
        description="Consulta status do pedido.",
        param_schema={
            "properties": {
                "order_id": {"type": "string", "location": "query", "required": True},
                "page": {"type": "integer", "location": "query", "required": False},
            }
        },
    )


def test_specialist_config_parses_external_tools() -> None:
    specialist = SpecialistConfig.model_validate(
        {
            "id": 1,
            "name": "Vendas",
            "role_prompt": "...",
            "llm_temperature": 0.2,
            "confidence_threshold": 0.6,
            "tools": ["query_orders"],
            "external_tools": [
                {"slug": "query_orders", "description": "x", "param_schema": {"properties": {}}}
            ],
        }
    )

    assert specialist.external_tools[0].slug == "query_orders"


def test_build_external_tool_creates_dynamic_args_model() -> None:
    tool = build_external_tool(make_cfg(), make_ctx())

    assert tool.name == "query_orders"
    assert tool.description == "Consulta status do pedido."

    fields = tool.args_schema.model_fields
    assert set(fields) == {"order_id", "page"}
    assert fields["order_id"].is_required() is True
    assert fields["page"].is_required() is False


def test_external_tool_args_model_forbids_extra_keys() -> None:
    tool = build_external_tool(make_cfg(), make_ctx())

    with pytest.raises(ValidationError):
        tool.args_schema.model_validate({"order_id": "A1", "evil": 1})


def test_external_tool_run_posts_payload_and_returns_result(monkeypatch) -> None:
    captured: dict[str, Any] = {}

    def fake_call(payload):
        captured["payload"] = payload
        return CallExternalToolResponse(result="shipped", success=True, http_status=200)

    monkeypatch.setattr("oryntra_agent.agent.tool_runtime.call_external_tool", fake_call)

    tool = build_external_tool(make_cfg(), make_ctx())

    output = tool.invoke({"order_id": "A1"})

    assert output == "shipped"
    payload = captured["payload"]
    assert payload.external_tool_slug == "query_orders"
    assert payload.args == {"order_id": "A1"}
    assert payload.workspace_id == 1
    assert payload.agent_run_id == 55
    assert payload.conversation_id == 99


def test_external_tool_run_drops_none_args(monkeypatch) -> None:
    captured: dict[str, Any] = {}

    def fake_call(payload):
        captured["payload"] = payload
        return CallExternalToolResponse(result="ok", success=True)

    monkeypatch.setattr("oryntra_agent.agent.tool_runtime.call_external_tool", fake_call)

    tool = build_external_tool(make_cfg(), make_ctx())
    tool.invoke({"order_id": "A1", "page": None})

    assert captured["payload"].args == {"order_id": "A1"}


def test_external_tool_run_returns_error_on_exception(monkeypatch) -> None:
    def boom(payload):
        raise RuntimeError("network down")

    monkeypatch.setattr("oryntra_agent.agent.tool_runtime.call_external_tool", boom)

    tool = build_external_tool(make_cfg(), make_ctx())
    output = tool.invoke({"order_id": "A1"})

    assert output.startswith("error:")


def test_build_specialist_tools_includes_connectors_without_native_tools() -> None:
    tools = build_specialist_tools([], make_ctx(), external_tools=[make_cfg()])

    assert [tool.name for tool in tools] == ["query_orders"]
