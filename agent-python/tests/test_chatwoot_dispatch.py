import asyncio

import pytest
from fastapi.testclient import TestClient

from oryntra_agent import auth
from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import get_runtime_graph
from oryntra_agent.api import chatwoot_messages
from oryntra_agent.api.schemas import ChatwootRuntimeRequest
from oryntra_agent.main import app


@pytest.fixture(autouse=True)
def clear_runtime_graph_cache() -> None:
    get_runtime_graph.cache_clear()
    settings_module.settings.langgraph_checkpointer = "memory"
    supervisor._postgres_checkpointer_context = None


def valid_payload() -> dict[str, object]:
    return {
        "agent_run_id": 4242,
        "workspace_id": 1,
        "agent_id": 10,
        "agent_mode": "single",
        "thread_id": "workspace:1:account:5:conversation:99",
        "messages": [
            {
                "id": "123",
                "content": "oi",
                "created_at": "2026-05-17T20:00:00Z",
                "message_type": "incoming",
                "content_type": "text",
                "attachments": [],
            }
        ],
        "contact": {},
        "inbox": {},
        "guard_config": {},
        "media_policy": {},
        "runtime_config": {},
    }


def test_dispatch_requires_internal_token(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post("/internal/chatwoot/messages/dispatch", json=valid_payload())

    assert response.status_code == 401


def test_dispatch_accepts_and_returns_202(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    async def _noop(*args, **kwargs) -> None:
        return None

    # Don't actually run the graph / hit Laravel in this contract test.
    monkeypatch.setattr(chatwoot_messages, "_run_and_callback", _noop)

    response = TestClient(app).post(
        "/internal/chatwoot/messages/dispatch",
        headers={"X-Internal-Token": "ci-token"},
        json=valid_payload(),
    )

    assert response.status_code == 202
    body = response.json()
    assert body["accepted"] is True
    assert body["agent_run_id"] == 4242


def test_dispatch_rejects_missing_agent_run_id(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")
    payload = valid_payload()
    del payload["agent_run_id"]

    response = TestClient(app).post(
        "/internal/chatwoot/messages/dispatch",
        headers={"X-Internal-Token": "ci-token"},
        json=payload,
    )

    assert response.status_code == 422


def test_dispatch_returns_503_when_at_capacity(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")
    # An empty semaphore reports locked() — the dispatch endpoint must reject
    # with 503 so Laravel releases the job back onto the queue (backpressure).
    monkeypatch.setattr(chatwoot_messages, "_run_semaphore", asyncio.Semaphore(0))

    response = TestClient(app).post(
        "/internal/chatwoot/messages/dispatch",
        headers={"X-Internal-Token": "ci-token"},
        json=valid_payload(),
    )

    assert response.status_code == 503


class _StubSemaphore:
    """No-op stand-in so _run_and_callback's release() is safe to call directly
    in unit tests (the dispatch endpoint normally acquires the real slot)."""

    def release(self) -> None:
        return None

    def locked(self) -> bool:
        return False


def test_run_and_callback_posts_completed_result(monkeypatch) -> None:
    captured: dict[str, object] = {}

    async def _capture(run_id: int, body: dict[str, object]) -> None:
        captured["run_id"] = run_id
        captured["body"] = body

    monkeypatch.setattr(chatwoot_messages, "post_agent_run_result", _capture)
    monkeypatch.setattr(chatwoot_messages, "_run_semaphore", _StubSemaphore())

    payload = ChatwootRuntimeRequest.model_validate(valid_payload())
    asyncio.run(chatwoot_messages._run_and_callback(4242, payload))

    assert captured["run_id"] == 4242
    assert captured["body"]["status"] == "completed"  # type: ignore[index]


def test_run_and_callback_reports_failure(monkeypatch) -> None:
    captured: dict[str, object] = {}

    async def _capture(run_id: int, body: dict[str, object]) -> None:
        captured["body"] = body

    async def _boom(_payload) -> None:
        raise RuntimeError("graph exploded")

    monkeypatch.setattr(chatwoot_messages, "post_agent_run_result", _capture)
    monkeypatch.setattr(chatwoot_messages, "_execute_runtime", _boom)
    monkeypatch.setattr(chatwoot_messages, "_run_semaphore", _StubSemaphore())

    payload = ChatwootRuntimeRequest.model_validate(valid_payload())
    asyncio.run(chatwoot_messages._run_and_callback(4242, payload))

    assert captured["body"]["status"] == "failed"  # type: ignore[index]
    assert "graph exploded" in captured["body"]["error"]  # type: ignore[index]
