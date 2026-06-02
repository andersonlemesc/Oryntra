from typing import Any

import pytest

from oryntra_agent.agent import tool_runtime, tools
from oryntra_agent.agent.tool_runtime import ToolRuntimeContext, build_specialist_tools
from oryntra_agent.agent.tools import (
    SearchKnowledgeBaseRequest,
    search_knowledge_base,
)


def _ctx() -> ToolRuntimeContext:
    return ToolRuntimeContext(
        workspace_id=1,
        agent_id=2,
        agent_run_id=3,
        specialist_id=4,
        contact_id=None,
        conversation_id=9,
    )


def test_search_knowledge_base_calls_only_laravel(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[tuple[str, dict[str, Any]]] = []

    def fake_post(path: str, payload: Any) -> dict[str, Any]:
        calls.append((path, payload.model_dump(mode="json")))
        return {
            "hits": [{"agent_document_id": 7, "content": "pricing info", "score": 0.91}],
            "embedding_model": "m",
        }

    monkeypatch.setattr(tools, "_post", fake_post)

    response = search_knowledge_base(
        SearchKnowledgeBaseRequest(
            workspace_id=1,
            agent_id=2,
            agent_run_id=3,
            specialist_id=4,
            query="how much does it cost",
        )
    )

    assert response.hits[0]["agent_document_id"] == 7
    assert len(calls) == 1
    assert calls[0][0] == "/api/internal/agent-tools/search-knowledge-base"
    assert calls[0][1]["query"] == "how much does it cost"


def test_tool_registered_when_allowlisted() -> None:
    tools_list = build_specialist_tools(["search_knowledge_base"], _ctx())
    names = {tool.name for tool in tools_list}
    assert "search_knowledge_base" in names


def test_tool_absent_when_not_allowlisted() -> None:
    tools_list = build_specialist_tools(["query_products"], _ctx())
    names = {tool.name for tool in tools_list}
    assert "search_knowledge_base" not in names


def test_search_tool_formats_hits(monkeypatch: pytest.MonkeyPatch) -> None:
    def fake_search(payload: SearchKnowledgeBaseRequest) -> Any:
        return tools.SearchKnowledgeBaseResponse(
            hits=[{"agent_document_id": 5, "content": "the answer", "score": 0.8}],
            embedding_model="m",
        )

    monkeypatch.setattr(tool_runtime, "search_knowledge_base", fake_search)

    tool = tool_runtime._make_search_knowledge_base_tool(_ctx())
    result = tool.func(query="q")

    assert "the answer" in result
    assert "doc 5" in result
