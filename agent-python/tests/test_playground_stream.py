"""Playground streaming runtime: token deltas + debug events + final payload.

Uses a fake streaming chat model so no real LLM is required. The production
synchronous path is unaffected — sinks are only installed by
``stream_chatwoot_runtime``.
"""

from __future__ import annotations

import asyncio
from typing import Any

from langchain_core.language_models.fake_chat_models import GenericFakeChatModel
from langchain_core.messages import AIMessage

import oryntra_agent.agent.supervisor as supervisor
from oryntra_agent.agent.supervisor import stream_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


def _payload(thread_id: str) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "single",
            "thread_id": thread_id,
            "messages": [{"id": "1", "content": "oi"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Atendimento",
                    "role_prompt": "Responda o cliente.",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": [],
                    "confidence_threshold": 0.0,
                    "llm_provider": "openai",
                    "llm_model": "gpt-test",
                    "llm_api_key": "sk-test",
                }
            ],
        }
    )


async def _collect(payload: ChatwootRuntimeRequest) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    async for item in stream_chatwoot_runtime(payload):
        items.append(item)
    return items


def test_streaming_emits_tokens_routing_and_final(monkeypatch) -> None:
    fake = GenericFakeChatModel(messages=iter([AIMessage(content="Ola, como posso ajudar?")]))
    monkeypatch.setattr(supervisor, "chat_model_for_credential", lambda *a, **k: fake)

    items = asyncio.run(_collect(_payload("workspace:1:playground:stream-1")))
    types = [item["type"] for item in items]

    assert "routing" in types
    assert types.count("token") >= 1
    assert types[-1] == "final"

    streamed_text = "".join(item["delta"] for item in items if item["type"] == "token")
    assert "ajudar" in streamed_text

    final = items[-1]["data"]
    assert final["status"] == "completed"
    assert final["specialist_id"] == 5
    # The aggregated final content matches the streamed tokens.
    assert "ajudar" in (final["response"]["content"] or "")


def test_streaming_routing_event_carries_specialist(monkeypatch) -> None:
    fake = GenericFakeChatModel(messages=iter([AIMessage(content="Pronto.")]))
    monkeypatch.setattr(supervisor, "chat_model_for_credential", lambda *a, **k: fake)

    items = asyncio.run(_collect(_payload("workspace:1:playground:stream-2")))
    routing = next(item for item in items if item["type"] == "routing")

    assert routing["specialist_id"] == 5
    assert "confidence" in routing
