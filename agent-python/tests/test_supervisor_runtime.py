from types import SimpleNamespace

from langgraph.checkpoint.memory import InMemorySaver
from pydantic import SecretStr

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import (
    SpecialistChoice,
    SpecialistDecision,
    get_runtime_graph,
    run_chatwoot_runtime,
    runtime_checkpointer,
    runtime_config,
)
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


def supervisor_payload(
    content: str = "preciso de ajuda no suporte",
    thread_id: str = "workspace:1:account:5:conversation:99",
) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": thread_id,
            "messages": [{"id": "123", "content": content}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Answer support questions.",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["ajuda", "suporte"],
                    "confidence_threshold": 0.5,
                },
                {
                    "id": 6,
                    "name": "Vendas",
                    "role_prompt": "Answer sales questions.",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["comprar", "preco"],
                    "confidence_threshold": 0.5,
                },
            ],
        }
    )


def test_supervisor_routes_to_keyword_matching_specialist() -> None:
    response = run_chatwoot_runtime(supervisor_payload())

    assert response.status == "completed"
    assert response.specialist_id == 5
    assert response.response.content == "[mock] Suporte recebeu 1 mensagem(ns)."
    assert response.trace[1].type == "supervisor_route"
    assert response.trace[1].output["reason"] == "keyword_match"


def test_supervisor_asks_for_context_when_no_keyword_matches() -> None:
    response = run_chatwoot_runtime(supervisor_payload("boa tarde"))

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.response.type == "clarify"
    assert response.response.handoff_reason is None
    assert response.trace[1].output["reason"] == "no_keyword_match"


def test_supervisor_can_generate_opening_response_with_llm(monkeypatch) -> None:
    payload = supervisor_payload("Oi")
    payload.supervisor.llm_provider = "openai"
    payload.supervisor.llm_model = "gpt-4.1-mini"
    payload.supervisor.llm_api_key = SecretStr("sk-test")

    monkeypatch.setattr(supervisor, "choose_specialist_with_llm", lambda payload: None)

    class FakeChatModel:
        def invoke(self, messages):
            assert "recepcao inicial" in messages[0][1]
            assert "Cliente: Oi" in messages[1][1]

            return SimpleNamespace(
                content="Olá! Bem-vindo à BikePulse. Qual é o seu nome e como posso te ajudar hoje?"
            )

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.response.content == (
        "Olá! Bem-vindo à BikePulse. Qual é o seu nome e como posso te ajudar hoje?"
    )


def test_supervisor_waits_for_human_when_confidence_is_below_threshold() -> None:
    payload = supervisor_payload("preciso de ajuda")
    payload.specialists[0].confidence_threshold = 0.8

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.response.confidence == 0.5
    assert response.trace[1].output["reason"] == "below_confidence_threshold"


def test_supervisor_uses_llm_choice_interface_when_available(monkeypatch) -> None:
    payload = supervisor_payload("sem palavra chave")

    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(specialist_id=6, confidence=0.9, reason="llm_choice"),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.specialist_id == 6
    assert response.trace[1].output["reason"] == "llm_choice"


def test_supervisor_does_not_checkpoint_llm_api_key() -> None:
    payload = supervisor_payload()
    payload.supervisor.llm_provider = "local"
    payload.supervisor.llm_model = "local-router"
    payload.supervisor.llm_api_key = SecretStr("sk-secret")

    run_chatwoot_runtime(payload)
    state = get_runtime_graph().get_state(runtime_config(payload)).values

    assert "llm_api_key" not in state["payload"]["supervisor"]


def test_specialist_does_not_checkpoint_llm_api_key() -> None:
    payload = supervisor_payload()
    payload.specialists[0].llm_provider = "local"
    payload.specialists[0].llm_model = "local-specialist"
    payload.specialists[0].llm_api_key = SecretStr("sk-specialist-secret")

    run_chatwoot_runtime(payload)
    state = get_runtime_graph().get_state(runtime_config(payload)).values

    assert "llm_api_key" not in state["payload"]["specialists"][0]


def test_specialist_can_generate_response_with_llm(monkeypatch) -> None:
    payload = supervisor_payload()
    payload.specialists[0].llm_provider = "openai"
    payload.specialists[0].llm_model = "gpt-4.1-nano"
    payload.specialists[0].llm_api_key = SecretStr("sk-specialist-test")

    class FakeChatModel:
        def invoke(self, messages):
            assert messages[0][0] == "system"
            assert "Answer support questions." in messages[0][1]

            return SimpleNamespace(content="Resposta real simulada.")

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.content == "Resposta real simulada."
    assert response.trace[2].output["source"] == "llm"


def test_specialist_prompt_receives_recent_conversation_history(monkeypatch) -> None:
    thread_id = "workspace:1:account:5:conversation:specialist-history"
    captured = {}

    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=6,
            confidence=0.9,
            reason="initial_sales",
        ),
    )

    class FakeChatModel:
        def with_structured_output(self, _schema):
            return self

        def invoke(self, messages):
            captured["human"] = messages[1][1]

            return SpecialistDecision(
                action="respond_text",
                content="Com 1,72 e trajeto de 5km, recomendo modelo urbano.",
                confidence=0.9,
            )

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    first = supervisor_payload("Preciso comprar uma bike", thread_id=thread_id)
    first.specialists[1].llm_provider = "openai"
    first.specialists[1].llm_model = "gpt-4.1-mini"
    first.specialists[1].llm_api_key = SecretStr("sk-test")
    run_chatwoot_runtime(first)

    second = supervisor_payload("Minha altura é 1,72", thread_id=thread_id)
    second.specialists[1].llm_provider = "openai"
    second.specialists[1].llm_model = "gpt-4.1-mini"
    second.specialists[1].llm_api_key = SecretStr("sk-test")
    run_chatwoot_runtime(second)

    assert "Cliente: Preciso comprar uma bike" in captured["human"]
    assert "IA: Com 1,72 e trajeto de 5km, recomendo modelo urbano." in captured["human"]
    assert "Cliente: Minha altura é 1,72" in captured["human"]


def test_supervisor_waits_when_llm_choice_is_below_threshold(monkeypatch) -> None:
    payload = supervisor_payload("sem palavra chave")

    monkeypatch.setattr(
        supervisor,
        "choose_specialist_with_llm",
        lambda payload: SpecialistChoice(
            specialist_id=6, confidence=0.4, reason="llm_low_confidence"
        ),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.trace[1].output["reason"] == "llm_low_confidence"


def test_runtime_checkpointer_reuses_state_for_same_thread_id() -> None:
    payload = supervisor_payload(thread_id="workspace:1:account:5:conversation:checkpoint-a")

    first = run_chatwoot_runtime(payload)
    second = run_chatwoot_runtime(payload)
    state = get_runtime_graph().get_state(runtime_config(payload)).values

    assert first.trace[0].input["turn_count"] == 1
    assert second.trace[0].input["turn_count"] == 2
    assert state["turn_count"] == 2


def test_runtime_accumulates_conversation_history_for_same_thread() -> None:
    thread_id = "workspace:1:account:5:conversation:memory-history"

    run_chatwoot_runtime(supervisor_payload("Preciso comprar uma bike", thread_id=thread_id))
    run_chatwoot_runtime(
        supervisor_payload("Uso para ir ao trabalho cerca de 5km", thread_id=thread_id)
    )

    state = (
        get_runtime_graph()
        .get_state(runtime_config(supervisor_payload(thread_id=thread_id)))
        .values
    )

    assert [message["content"] for message in state["conversation_messages"]] == [
        "Preciso comprar uma bike",
        "[mock] Vendas recebeu 1 mensagem(ns).",
        "Uso para ir ao trabalho cerca de 5km",
        "[mock] Vendas recebeu 2 mensagem(ns).",
    ]


def test_runtime_reuses_active_specialist_for_followup_without_rerouting(monkeypatch) -> None:
    thread_id = "workspace:1:account:5:conversation:active-specialist"
    calls = []

    def fake_choice(payload):
        calls.append(payload.messages[-1].content)

        return SpecialistChoice(specialist_id=6, confidence=0.9, reason="initial_sales")

    monkeypatch.setattr(supervisor, "choose_specialist_with_llm", fake_choice)

    first = run_chatwoot_runtime(
        supervisor_payload("Preciso comprar uma bike", thread_id=thread_id)
    )
    second = run_chatwoot_runtime(supervisor_payload("Tenho 1,72 e peso 79kg", thread_id=thread_id))

    assert first.specialist_id == 6
    assert second.specialist_id == 6
    assert calls == ["Preciso comprar uma bike"]
    assert second.trace[1].output["reason"] == "active_specialist_continuation"


def test_runtime_checkpointer_isolates_different_thread_ids() -> None:
    first_thread = supervisor_payload(thread_id="workspace:1:account:5:conversation:checkpoint-a")
    second_thread = supervisor_payload(thread_id="workspace:1:account:5:conversation:checkpoint-b")

    run_chatwoot_runtime(first_thread)
    run_chatwoot_runtime(first_thread)
    isolated = run_chatwoot_runtime(second_thread)

    assert isolated.trace[0].input["turn_count"] == 1


def test_runtime_checkpointer_defaults_to_memory() -> None:
    assert isinstance(runtime_checkpointer(), InMemorySaver)


def test_runtime_checkpointer_uses_postgres_when_configured(monkeypatch) -> None:
    class FakeContext:
        entered = False

        def __enter__(self) -> str:
            self.entered = True
            return "postgres-checkpointer"

        def __exit__(self, exc_type, exc, tb) -> None:
            return None

    fake_context = FakeContext()
    settings_module.settings.langgraph_checkpointer = "postgres"
    settings_module.settings.postgres_url = "postgresql://test:test@postgres:5432/test"
    monkeypatch.setattr(supervisor.PostgresSaver, "from_conn_string", lambda url: fake_context)
    supervisor._postgres_checkpointer_context = None

    assert runtime_checkpointer() == "postgres-checkpointer"
    assert fake_context.entered is True
    assert supervisor._postgres_checkpointer_context is fake_context


def test_single_agent_path_stays_compatible() -> None:
    response = run_chatwoot_runtime(
        ChatwootRuntimeRequest.model_validate(
            {
                "workspace_id": 1,
                "agent_id": 10,
                "agent_mode": "single",
                "thread_id": "workspace:1:account:5:conversation:99",
                "messages": [{"id": "123", "content": "oi"}],
            }
        )
    )

    assert response.status == "completed"
    assert response.specialist_id is None
    assert response.response.content == "[mock] Recebi 1 mensagem(ns)."
