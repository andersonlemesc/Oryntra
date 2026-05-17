from oryntra_agent import manage


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
