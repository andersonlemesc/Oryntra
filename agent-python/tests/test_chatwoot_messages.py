from fastapi.testclient import TestClient

from oryntra_agent import auth
from oryntra_agent.main import app


def valid_payload() -> dict[str, object]:
    return {
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
        "media_config": {},
        "runtime_config": {},
    }


def test_chatwoot_messages_requires_internal_token(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post("/internal/chatwoot/messages", json=valid_payload())

    assert response.status_code == 401


def test_chatwoot_messages_rejects_invalid_internal_token(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post(
        "/internal/chatwoot/messages",
        headers={"X-Internal-Token": "wrong"},
        json=valid_payload(),
    )

    assert response.status_code == 401


def test_chatwoot_messages_returns_typed_mock_response(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post(
        "/internal/chatwoot/messages",
        headers={"X-Internal-Token": "ci-token"},
        json=valid_payload(),
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "completed"
    assert body["response"]["type"] == "text"
    assert body["response"]["content"] == "[mock] Recebi 1 mensagem(ns)."
    assert body["trace"][0]["type"] == "runtime_mock"
    assert body["trace"][0]["input"]["thread_id"] == "workspace:1:account:5:conversation:99"
    assert body["usage"]["total_cost_cents"] == 0


def test_chatwoot_messages_accepts_supervisor_specialists(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")
    payload = valid_payload()
    payload["agent_mode"] = "supervisor"
    payload["supervisor"] = {
        "prompt": "Route to the best specialist.",
        "llm_key_id": 11,
        "llm_model": "gpt-4.1-nano",
    }
    payload["specialists"] = [
        {
            "id": 5,
            "name": "Suporte",
            "description": "Support specialist",
            "role_prompt": "Answer support questions.",
            "llm_key_id": 12,
            "llm_model": "gpt-4.1-mini",
            "llm_temperature": 0.2,
            "tools": [],
            "intent_keywords": ["ajuda", "suporte"],
            "confidence_threshold": 0.6,
            "fallback_specialist_id": None,
        }
    ]

    response = TestClient(app).post(
        "/internal/chatwoot/messages",
        headers={"X-Internal-Token": "ci-token"},
        json=payload,
    )

    assert response.status_code == 200
    body = response.json()
    assert body["specialist_id"] == 5
    assert body["response"]["content"] == "[mock] Suporte recebeu 1 mensagem(ns)."
    assert body["trace"][1]["type"] == "supervisor_route"
    assert body["trace"][2]["type"] == "specialist_response"
