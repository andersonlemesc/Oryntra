from __future__ import annotations

from typing import Any
from unittest.mock import patch

import pytest
from pydantic import ValidationError

from oryntra_agent.agent.tool_runtime import (
    ToolRuntimeContext,
    build_mcp_tool,
    build_specialist_tools,
)
from oryntra_agent.agent.tools import CallMcpToolResponse
from oryntra_agent.api.schemas import (
    McpServerRuntimeConfig,
    McpToolConfig,
    SpecialistConfig,
)


def make_ctx() -> ToolRuntimeContext:
    return ToolRuntimeContext(
        workspace_id=1,
        agent_id=10,
        agent_run_id=55,
        specialist_id=5,
        contact_id=None,
        conversation_id=99,
        thread_id="workspace:1:account:5:conversation:99",
    )


def make_mcp_tool_cfg(
    server_slug: str = "crm_n8n",
    tool_name: str = "get_order",
    session_id: str | None = "sess-1",
    param_schema: dict[str, Any] | None = None,
) -> McpToolConfig:
    return McpToolConfig(
        server_slug=server_slug,
        tool_name=tool_name,
        session_id=session_id,
        description="Busca pedido por ID.",
        param_schema=param_schema
        or {
            "properties": {
                "order_id": {"type": "string", "location": "body", "required": True},
            }
        },
    )


def make_mcp_server_cfg(tools: list[McpToolConfig] | None = None) -> McpServerRuntimeConfig:
    return McpServerRuntimeConfig(
        server_slug="crm_n8n",
        session_id="sess-1",
        tools=tools or [make_mcp_tool_cfg()],
    )


# ── schema models ────────────────────────────────────────────────────────────


def test_specialist_config_parses_mcp_servers() -> None:
    specialist = SpecialistConfig.model_validate(
        {
            "id": 1,
            "name": "Vendas",
            "role_prompt": "...",
            "llm_temperature": 0.2,
            "confidence_threshold": 0.6,
            "mcp_servers": [
                {
                    "server_slug": "crm_n8n",
                    "session_id": "sess-1",
                    "tools": [
                        {
                            "server_slug": "crm_n8n",
                            "tool_name": "get_order",
                            "description": "...",
                            "param_schema": {"properties": {}},
                        }
                    ],
                }
            ],
        }
    )

    assert len(specialist.mcp_servers) == 1
    assert specialist.mcp_servers[0].server_slug == "crm_n8n"
    assert specialist.mcp_servers[0].tools[0].tool_name == "get_order"


def test_specialist_config_mcp_servers_defaults_to_empty() -> None:
    specialist = SpecialistConfig.model_validate(
        {"id": 1, "name": "S", "role_prompt": "...", "llm_temperature": 0.5, "confidence_threshold": 0.6}
    )
    assert specialist.mcp_servers == []


def test_mcp_tool_config_session_id_nullable() -> None:
    cfg = McpToolConfig(server_slug="s", tool_name="t", session_id=None)
    assert cfg.session_id is None


# ── build_mcp_tool ────────────────────────────────────────────────────────────


def test_build_mcp_tool_name_is_prefixed_with_server_slug() -> None:
    cfg = make_mcp_tool_cfg()
    tool = build_mcp_tool(cfg, make_ctx())

    assert tool.name == "crm_n8n__get_order"


def test_build_mcp_tool_uses_description() -> None:
    cfg = make_mcp_tool_cfg()
    tool = build_mcp_tool(cfg, make_ctx())

    assert "Busca pedido" in tool.description


def test_build_mcp_tool_required_param_raises_on_missing() -> None:
    cfg = make_mcp_tool_cfg()
    tool = build_mcp_tool(cfg, make_ctx())

    with pytest.raises(Exception):
        tool.args_schema()  # missing required order_id


def test_build_mcp_tool_calls_laravel_and_returns_result() -> None:
    cfg = make_mcp_tool_cfg()
    ctx = make_ctx()

    with patch("oryntra_agent.agent.tool_runtime.call_mcp_tool") as mock_call:
        mock_call.return_value = CallMcpToolResponse(result="Order 123: shipped", success=True)
        tool = build_mcp_tool(cfg, ctx)
        result = tool.invoke({"order_id": "123"})

    assert result == "Order 123: shipped"
    called_payload = mock_call.call_args[0][0]
    assert called_payload.server_slug == "crm_n8n"
    assert called_payload.tool_name == "get_order"
    assert called_payload.session_id == "sess-1"
    assert called_payload.args == {"order_id": "123"}


def test_build_mcp_tool_returns_error_string_on_exception() -> None:
    cfg = make_mcp_tool_cfg()
    ctx = make_ctx()

    with patch("oryntra_agent.agent.tool_runtime.call_mcp_tool", side_effect=RuntimeError("timeout")):
        tool = build_mcp_tool(cfg, ctx)
        result = tool.invoke({"order_id": "1"})

    assert "error:" in result
    assert "get_order" in result


def test_build_mcp_tool_empty_param_schema_creates_no_args_model_fields() -> None:
    cfg = make_mcp_tool_cfg(param_schema={"properties": {}})
    tool = build_mcp_tool(cfg, make_ctx())

    # tool should be callable with no args
    with patch("oryntra_agent.agent.tool_runtime.call_mcp_tool") as mock_call:
        mock_call.return_value = CallMcpToolResponse(result="ok", success=True)
        result = tool.invoke({})

    assert result == "ok"


# ── build_specialist_tools with mcp_servers ───────────────────────────────────


def test_build_specialist_tools_includes_mcp_tools() -> None:
    server = make_mcp_server_cfg()
    tools = build_specialist_tools([], make_ctx(), mcp_servers=[server])

    assert len(tools) == 1
    assert tools[0].name == "crm_n8n__get_order"


def test_build_specialist_tools_multiple_servers_and_tools() -> None:
    server1 = make_mcp_server_cfg(tools=[make_mcp_tool_cfg("s1", "tool_a"), make_mcp_tool_cfg("s1", "tool_b")])
    server2 = make_mcp_server_cfg(tools=[make_mcp_tool_cfg("s2", "tool_c")])
    server2 = McpServerRuntimeConfig(server_slug="s2", session_id=None, tools=[make_mcp_tool_cfg("s2", "tool_c")])

    tools = build_specialist_tools([], make_ctx(), mcp_servers=[server1, server2])

    names = [t.name for t in tools]
    assert "s1__tool_a" in names
    assert "s1__tool_b" in names
    assert "s2__tool_c" in names


def test_build_specialist_tools_no_mcp_servers_returns_empty_when_no_native() -> None:
    tools = build_specialist_tools([], make_ctx(), mcp_servers=None)
    assert tools == []


def test_build_specialist_tools_mcp_and_http_connectors_together() -> None:
    from oryntra_agent.api.schemas import ExternalToolConfig

    http_cfg = ExternalToolConfig(
        slug="query_orders",
        description="HTTP API",
        param_schema={"properties": {"order_id": {"type": "string", "location": "query", "required": False}}},
    )
    mcp_server = make_mcp_server_cfg()

    with patch("oryntra_agent.agent.tool_runtime.call_external_tool"):
        tools = build_specialist_tools([], make_ctx(), external_tools=[http_cfg], mcp_servers=[mcp_server])

    names = [t.name for t in tools]
    assert "query_orders" in names
    assert "crm_n8n__get_order" in names
