"""Single-agent mode now runs the one specialist through the real tool-calling
path (reusing routed_specialist_response) instead of the legacy mock stub.

Distinguishing signal: the real path sets ``response.specialist_id`` to the
specialist's id, while the legacy single mock (used only when an agent has no
specialist) returns ``specialist_id is None`` with the ``[mock] Recebi`` text.

Unique thread_ids per test avoid LangGraph checkpointer state bleed.
"""

from __future__ import annotations

from oryntra_agent.agent.supervisor import run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


def single_payload(
    content: str = "oi",
    thread_id: str = "workspace:1:account:5:conversation:single-1",
    with_specialist: bool = True,
) -> ChatwootRuntimeRequest:
    specialists = []
    if with_specialist:
        specialists = [
            {
                "id": 5,
                "name": "Atendimento",
                "role_prompt": "Responda o cliente.",
                "llm_temperature": 0.2,
                "tools": [],
                "intent_keywords": [],
                "confidence_threshold": 0.0,
            }
        ]

    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "single",
            "thread_id": thread_id,
            "messages": [{"id": "123", "content": content}],
            "specialists": specialists,
        }
    )


def test_single_mode_routes_to_the_one_specialist() -> None:
    response = run_chatwoot_runtime(
        single_payload(thread_id="workspace:1:account:5:conversation:single-real")
    )

    assert response.status == "completed"
    # Real path sets the specialist id (mock stub would return None).
    assert response.specialist_id == 5


def test_single_mode_without_specialist_falls_back_to_mock() -> None:
    response = run_chatwoot_runtime(
        single_payload(
            thread_id="workspace:1:account:5:conversation:single-empty",
            with_specialist=False,
        )
    )

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.response.content is not None
    assert response.response.content.startswith("[mock] Recebi")
