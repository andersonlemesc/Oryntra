from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import (
    chat_model_for_credential,
    specialist_llm_credentials_from_payload,
    supervisor_llm_credential_from_payload,
)
from oryntra_agent.api.schemas import ChatwootRuntimeRequest, LlmCredential


def _capture(monkeypatch, attr: str) -> dict:
    captured: dict = {}

    def fake(**kwargs):
        captured.update(kwargs)
        return "chat-model"

    monkeypatch.setattr(supervisor, attr, fake)
    return captured


def test_openai_forwards_base_url(monkeypatch):
    captured = _capture(monkeypatch, "ChatOpenAI")
    cred = LlmCredential(
        provider="openai",
        base_url="https://api.groq.com/openai/v1",
        model="llama-3.3-70b",
        api_key="sk-test",
    )

    assert chat_model_for_credential(cred, 0.2) == "chat-model"
    assert captured["base_url"] == "https://api.groq.com/openai/v1"
    assert captured["model"] == "llama-3.3-70b"


def test_openai_omits_base_url_when_unset(monkeypatch):
    captured = _capture(monkeypatch, "ChatOpenAI")
    cred = LlmCredential(provider="openai", model="gpt-4.1-nano", api_key="sk-test")

    chat_model_for_credential(cred, 0.2)
    assert "base_url" not in captured


def test_local_provider_uses_openai_client(monkeypatch):
    captured = _capture(monkeypatch, "ChatOpenAI")
    cred = LlmCredential(
        provider="local",
        base_url="http://localhost:11434/v1",
        model="qwen2.5",
        api_key="ollama",
    )

    assert chat_model_for_credential(cred, 0.2) == "chat-model"
    assert captured["base_url"] == "http://localhost:11434/v1"


def test_anthropic_forwards_base_url(monkeypatch):
    captured = _capture(monkeypatch, "ChatAnthropic")
    cred = LlmCredential(
        provider="anthropic",
        base_url="https://proxy.example/anthropic",
        model="claude-sonnet-4-20250514",
        api_key="sk-ant",
    )

    chat_model_for_credential(cred, 0.2)
    assert captured["base_url"] == "https://proxy.example/anthropic"


def test_gemini_forwards_base_url_via_client_options(monkeypatch):
    captured = _capture(monkeypatch, "ChatGoogleGenerativeAI")
    cred = LlmCredential(
        provider="gemini",
        base_url="https://gemini-proxy.example",
        model="gemini-2.0-flash",
        api_key="g-key",
    )

    chat_model_for_credential(cred, 0.2)
    assert captured["client_options"] == {"api_endpoint": "https://gemini-proxy.example"}


def test_payload_builders_carry_base_url():
    payload = ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:99",
            "messages": [{"id": "1", "content": "oi"}],
            "supervisor": {
                "prompt": "route",
                "llm_provider": "openai",
                "llm_base_url": "https://api.groq.com/openai/v1",
                "llm_model": "llama-3.3-70b",
                "llm_api_key": "sk-sup",
            },
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Answer support questions.",
                    "llm_provider": "openai",
                    "llm_base_url": "https://api.groq.com/openai/v1",
                    "llm_model": "llama-3.3-70b",
                    "llm_api_key": "sk-spec",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["ajuda"],
                    "confidence_threshold": 0.5,
                }
            ],
        }
    )

    sup = supervisor_llm_credential_from_payload(payload)
    assert sup is not None
    assert sup.base_url == "https://api.groq.com/openai/v1"

    specs = specialist_llm_credentials_from_payload(payload)
    assert specs[5].base_url == "https://api.groq.com/openai/v1"
