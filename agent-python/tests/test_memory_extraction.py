from typing import Any

import pytest
from fastapi.testclient import TestClient

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.api import memory_extraction
from oryntra_agent.api.memory_extraction import ExtractedMemory, ExtractedMemoryList
from oryntra_agent.main import app


@pytest.fixture(autouse=True)
def configure_internal_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(settings_module.settings, "internal_api_token", "ci-token")


def base_payload() -> dict[str, Any]:
    return {
        "workspace_id": 1,
        "agent_id": 10,
        "specialist_id": 5,
        "contact_id": 42,
        "transcript": [
            {"role": "user", "content": "Procuro bike eletrica urbana"},
            {"role": "assistant", "content": "Que faixa de preco voce considera?"},
            {"role": "user", "content": "Ate 6 mil reais, uso para 5km diarios"},
        ],
        "existing_memories": [
            {"type": "preference", "content": "Procura bike eletrica urbana"},
        ],
        "allowed_types": ["preference", "fact", "constraint"],
        "credential": {
            "provider": "openai",
            "model": "gpt-4.1-mini",
            "api_key": "sk-test",
        },
    }


def test_extract_requires_internal_token() -> None:
    client = TestClient(app)
    response = client.post("/internal/memory/extract", json=base_payload())
    assert response.status_code in (401, 403)


def test_extract_returns_filtered_new_memories(monkeypatch: pytest.MonkeyPatch) -> None:
    class FakeChatModel:
        def with_structured_output(self, _schema: type) -> "FakeChatModel":
            return self

        def invoke(self, _messages: Any) -> ExtractedMemoryList:
            return ExtractedMemoryList(
                memories=[
                    ExtractedMemory(
                        type="preference", content="Procura bike eletrica urbana", confidence=0.9
                    ),
                    ExtractedMemory(
                        type="constraint", content="Orcamento ate 6000 reais", confidence=0.85
                    ),
                    ExtractedMemory(type="fact", content="Trajeto diario de 5km", confidence=0.9),
                    ExtractedMemory(
                        type="history",
                        content="Should be filtered out (type not allowed)",
                        confidence=0.8,
                    ),
                ]
            )

    monkeypatch.setattr(
        memory_extraction,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    client = TestClient(app)
    response = client.post(
        "/internal/memory/extract",
        json=base_payload(),
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    contents = [memory["content"] for memory in body["memories"]]
    assert "Procura bike eletrica urbana" not in contents
    assert "Orcamento ate 6000 reais" in contents
    assert "Trajeto diario de 5km" in contents
    assert "Should be filtered out (type not allowed)" not in contents


def test_extract_skipped_when_transcript_is_empty() -> None:
    payload = base_payload()
    payload["transcript"] = []
    client = TestClient(app)

    response = client.post(
        "/internal/memory/extract",
        json=payload,
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    assert response.json() == {"status": "skipped", "memories": [], "reason": "empty_transcript"}


def test_extract_skipped_when_no_allowed_types() -> None:
    payload = base_payload()
    payload["allowed_types"] = []
    client = TestClient(app)

    response = client.post(
        "/internal/memory/extract",
        json=payload,
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    assert response.json() == {"status": "skipped", "memories": [], "reason": "no_allowed_types"}


def test_extract_skipped_when_provider_unsupported(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        memory_extraction,
        "chat_model_for_credential",
        lambda credential, temperature: None,
    )
    client = TestClient(app)

    response = client.post(
        "/internal/memory/extract",
        json=base_payload(),
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "skipped"
    assert body["reason"] == "unsupported_provider"


_ = supervisor  # ensure import side effects load
