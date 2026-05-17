from oryntra_agent.agent.supervisor import run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


def supervisor_payload(content: str = "preciso de ajuda no suporte") -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:99",
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


def test_supervisor_waits_for_human_when_no_keyword_matches() -> None:
    response = run_chatwoot_runtime(supervisor_payload("boa tarde"))

    assert response.status == "waiting_human"
    assert response.specialist_id is None
    assert response.response.type == "clarify"
    assert response.response.handoff_reason == "no_confident_specialist_route"
    assert response.trace[1].output["reason"] == "no_keyword_match"


def test_supervisor_waits_for_human_when_confidence_is_below_threshold() -> None:
    payload = supervisor_payload("preciso de ajuda")
    payload.specialists[0].confidence_threshold = 0.8

    response = run_chatwoot_runtime(payload)

    assert response.status == "waiting_human"
    assert response.specialist_id is None
    assert response.response.confidence == 0.5
    assert response.trace[1].output["reason"] == "below_confidence_threshold"


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
