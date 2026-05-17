from oryntra_agent import manage
from oryntra_agent.api.schemas import ChatwootRuntimeResponse, RuntimeResponsePayload, TraceStep


class FakeCheckpointer:
    setup_called = False

    def setup(self) -> None:
        self.setup_called = True


class FakeContext:
    def __init__(self) -> None:
        self.checkpointer = FakeCheckpointer()

    def __enter__(self) -> FakeCheckpointer:
        return self.checkpointer

    def __exit__(self, exc_type, exc, tb) -> None:
        return None


def test_setup_checkpointer_runs_postgres_saver_setup(monkeypatch) -> None:
    fake_context = FakeContext()
    captured = {}

    def fake_from_conn_string(conn_string: str):
        captured["conn_string"] = conn_string
        return fake_context

    monkeypatch.setattr(manage.PostgresSaver, "from_conn_string", fake_from_conn_string)

    manage.setup_checkpointer("postgresql://test:test@postgres:5432/test")

    assert captured["conn_string"] == "postgresql://test:test@postgres:5432/test"
    assert fake_context.checkpointer.setup_called is True


def test_main_setup_checkpointer_returns_success(monkeypatch, capsys) -> None:
    called = {}

    def fake_setup_checkpointer(postgres_url: str | None = None) -> None:
        called["postgres_url"] = postgres_url

    monkeypatch.setattr(manage, "setup_checkpointer", fake_setup_checkpointer)

    result = manage.main(
        [
            "setup-checkpointer",
            "--postgres-url",
            "postgresql://test:test@postgres:5432/test",
        ]
    )

    assert result == 0
    assert called["postgres_url"] == "postgresql://test:test@postgres:5432/test"
    assert "LangGraph checkpointer setup complete." in capsys.readouterr().out


def test_smoke_supervisor_returns_summary(monkeypatch) -> None:
    captured = {}

    def fake_run_chatwoot_runtime(payload):
        captured["thread_id"] = payload.thread_id
        captured["supervisor"] = payload.supervisor
        return ChatwootRuntimeResponse(
            status="completed",
            response=RuntimeResponsePayload(type="text", content="ok", confidence=1.0),
            specialist_id=5,
            trace=[
                TraceStep(
                    step=1,
                    type="runtime_mock",
                    input={"turn_count": 3},
                    output={"response_type": "text"},
                    ts="2026-05-17T00:00:00Z",
                )
            ],
        )

    monkeypatch.setattr(manage, "run_chatwoot_runtime", fake_run_chatwoot_runtime)

    result = manage.smoke_supervisor("workspace:1:account:1:conversation:custom")

    assert captured["thread_id"] == "workspace:1:account:1:conversation:custom"
    assert captured["supervisor"].llm_api_key is None
    assert result == {
        "status": "completed",
        "specialist_id": 5,
        "turn_count": 3,
        "response_source": None,
        "content_preview": "ok",
    }


def test_smoke_supervisor_can_use_real_llm_config_from_env(monkeypatch) -> None:
    captured = {}

    def fake_run_chatwoot_runtime(payload):
        captured["supervisor"] = payload.supervisor
        captured["specialist"] = payload.specialists[0]
        return ChatwootRuntimeResponse(
            status="completed",
            response=RuntimeResponsePayload(type="text", content="ok", confidence=1.0),
            specialist_id=5,
            trace=[
                TraceStep(
                    step=1,
                    type="runtime_mock",
                    input={"turn_count": 1},
                    output={"response_type": "text"},
                    ts="2026-05-17T00:00:00Z",
                )
            ],
        )

    monkeypatch.setenv("SMOKE_LLM_API_KEY", "sk-test")
    monkeypatch.setattr(manage, "run_chatwoot_runtime", fake_run_chatwoot_runtime)

    result = manage.smoke_supervisor(
        thread_id="workspace:1:account:1:conversation:custom",
        real_llm=True,
        llm_provider="openai",
        llm_model="gpt-4.1-nano",
        llm_api_key_env="SMOKE_LLM_API_KEY",
    )

    supervisor = captured["supervisor"]

    assert supervisor.llm_provider == "openai"
    assert supervisor.llm_model == "gpt-4.1-nano"
    assert supervisor.llm_api_key.get_secret_value() == "sk-test"
    assert captured["specialist"].llm_api_key is None
    assert result == {
        "status": "completed",
        "specialist_id": 5,
        "turn_count": 1,
        "response_source": None,
        "content_preview": "ok",
    }


def test_smoke_supervisor_can_use_real_specialist_llm_config_from_env(monkeypatch) -> None:
    captured = {}

    def fake_run_chatwoot_runtime(payload):
        captured["specialist"] = payload.specialists[0]
        return ChatwootRuntimeResponse(
            status="completed",
            response=RuntimeResponsePayload(type="text", content="ok", confidence=1.0),
            specialist_id=5,
            trace=[
                TraceStep(
                    step=1,
                    type="runtime_mock",
                    input={"turn_count": 1},
                    output={"response_type": "text"},
                    ts="2026-05-17T00:00:00Z",
                )
            ],
        )

    monkeypatch.setenv("SMOKE_SPECIALIST_LLM_API_KEY", "sk-specialist-test")
    monkeypatch.setattr(manage, "run_chatwoot_runtime", fake_run_chatwoot_runtime)

    result = manage.smoke_supervisor(
        thread_id="workspace:1:account:1:conversation:custom",
        real_specialist_llm=True,
        specialist_llm_provider="openai",
        specialist_llm_model="gpt-4.1-nano",
        specialist_llm_api_key_env="SMOKE_SPECIALIST_LLM_API_KEY",
    )

    specialist = captured["specialist"]

    assert specialist.llm_provider == "openai"
    assert specialist.llm_model == "gpt-4.1-nano"
    assert specialist.llm_api_key.get_secret_value() == "sk-specialist-test"
    assert result == {
        "status": "completed",
        "specialist_id": 5,
        "turn_count": 1,
        "response_source": None,
        "content_preview": "ok",
    }


def test_main_smoke_supervisor_prints_json(monkeypatch, capsys) -> None:
    captured = {}

    def fake_smoke_supervisor(
        thread_id,
        real_llm=False,
        llm_provider=None,
        llm_model=None,
        llm_api_key_env="OPENAI_API_KEY",
        real_specialist_llm=False,
        specialist_llm_provider=None,
        specialist_llm_model=None,
        specialist_llm_api_key_env=None,
    ):
        captured["thread_id"] = thread_id
        captured["real_llm"] = real_llm
        captured["llm_provider"] = llm_provider
        captured["llm_model"] = llm_model
        captured["llm_api_key_env"] = llm_api_key_env
        captured["real_specialist_llm"] = real_specialist_llm
        captured["specialist_llm_provider"] = specialist_llm_provider
        captured["specialist_llm_model"] = specialist_llm_model
        captured["specialist_llm_api_key_env"] = specialist_llm_api_key_env
        return {
            "status": "completed",
            "specialist_id": 5,
            "turn_count": 1,
            "response_source": "llm",
            "content_preview": "ok",
        }

    monkeypatch.setattr(
        manage,
        "smoke_supervisor",
        fake_smoke_supervisor,
    )

    result = manage.main(
        [
            "smoke-supervisor",
            "--thread-id",
            "workspace:1:account:1:conversation:custom",
            "--real-llm",
            "--llm-provider",
            "openai",
            "--llm-model",
            "gpt-4.1-nano",
            "--llm-api-key-env",
            "SMOKE_LLM_API_KEY",
            "--real-specialist-llm",
            "--specialist-llm-provider",
            "openai",
            "--specialist-llm-model",
            "gpt-4.1-mini",
            "--specialist-llm-api-key-env",
            "SMOKE_SPECIALIST_LLM_API_KEY",
        ]
    )

    assert result == 0
    assert captured == {
        "thread_id": "workspace:1:account:1:conversation:custom",
        "real_llm": True,
        "llm_provider": "openai",
        "llm_model": "gpt-4.1-nano",
        "llm_api_key_env": "SMOKE_LLM_API_KEY",
        "real_specialist_llm": True,
        "specialist_llm_provider": "openai",
        "specialist_llm_model": "gpt-4.1-mini",
        "specialist_llm_api_key_env": "SMOKE_SPECIALIST_LLM_API_KEY",
    }
    assert (
        capsys.readouterr().out
        == '{"content_preview": "ok", "response_source": "llm", "specialist_id": 5, "status": "completed", "turn_count": 1}\n'
    )
