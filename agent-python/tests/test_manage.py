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
    assert result == {"status": "completed", "specialist_id": 5, "turn_count": 3}


def test_main_smoke_supervisor_prints_json(monkeypatch, capsys) -> None:
    monkeypatch.setattr(
        manage,
        "smoke_supervisor",
        lambda thread_id: {"status": "completed", "specialist_id": 5, "turn_count": 1},
    )

    result = manage.main(
        ["smoke-supervisor", "--thread-id", "workspace:1:account:1:conversation:custom"]
    )

    assert result == 0
    assert (
        capsys.readouterr().out == '{"specialist_id": 5, "status": "completed", "turn_count": 1}\n'
    )
