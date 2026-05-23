from oryntra_agent.agent.tools import (
    UpdateContactMemoryRequest,
    UpdateContactMemoryResponse,
    update_contact_memory,
)


def test_update_contact_memory_posts_to_laravel(monkeypatch, httpx_mock) -> None:
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.laravel_internal_base_url",
        "http://laravel-app",
    )
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.agent_runtime_internal_token",
        "ci-token",
    )
    httpx_mock.add_response(
        method="POST",
        url="http://laravel-app/api/internal/agent-tools/update-contact-memory",
        json={"status": "ok", "memory_id": 42},
    )

    response = update_contact_memory(
        UpdateContactMemoryRequest(
            workspace_id=1,
            agent_id=10,
            agent_run_id=55,
            specialist_id=5,
            contact_id=7,
            type="preference",
            content="Prefere bike eletrica urbana",
            confidence=0.9,
        )
    )

    request = httpx_mock.get_request()

    assert isinstance(response, UpdateContactMemoryResponse)
    assert response.status == "ok"
    assert response.memory_id == 42
    assert request is not None
    assert request.headers["X-Internal-Token"] == "ci-token"
    assert request.url.path == "/api/internal/agent-tools/update-contact-memory"


def test_update_contact_memory_rejects_invalid_type() -> None:
    import pytest
    from pydantic import ValidationError

    with pytest.raises(ValidationError):
        UpdateContactMemoryRequest(
            workspace_id=1,
            agent_id=10,
            agent_run_id=55,
            contact_id=7,
            type="bogus",  # type: ignore[arg-type]
            content="x",
        )
