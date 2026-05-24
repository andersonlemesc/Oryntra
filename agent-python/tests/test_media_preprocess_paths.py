from typing import Any

import pytest

from oryntra_agent.agent.media import preprocess_media
from oryntra_agent.api.schemas import (
    ChatwootMessage,
    ChatwootRuntimeRequest,
    LlmCredentialPayload,
    MediaAttachment,
    MediaPolicy,
    MediaTypePolicy,
)


def _make_request(**overrides: Any) -> ChatwootRuntimeRequest:
    base: dict[str, Any] = {
        "workspace_id": 1,
        "agent_id": 10,
        "agent_mode": "single",
        "thread_id": "t",
        "messages": [],
        "media_policy": MediaPolicy(),
    }
    base.update(overrides)
    return ChatwootRuntimeRequest(**base)


@pytest.mark.asyncio
async def test_short_circuits_when_only_audio_attachment_and_disabled() -> None:
    msg = ChatwootMessage(
        content=None,
        attachments=[
            MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://x")
        ],
    )
    policy = MediaPolicy(
        audio=MediaTypePolicy(enabled=False, fallback_message="manda em texto por favor"),
    )
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is not None
    assert result.short_circuit_response.response.content == "manda em texto por favor"


@pytest.mark.asyncio
async def test_prepends_fallback_when_message_has_text_and_disabled_attachment() -> None:
    msg = ChatwootMessage(
        content="oi tenho uma duvida",
        attachments=[
            MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://x")
        ],
    )
    policy = MediaPolicy(audio=MediaTypePolicy(enabled=False, fallback_message="sem audio aqui"))
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is None
    assert result.fallback_prefix == "sem audio aqui"
    assert result.payload.messages[0].content == "oi tenho uma duvida"


@pytest.mark.asyncio
async def test_uses_transcribed_text_cache() -> None:
    msg = ChatwootMessage(
        content=None,
        attachments=[
            MediaAttachment(
                file_type="audio",
                content_type="audio/opus",
                data_url="http://x",
                transcribed_text="ola tudo bem",
            )
        ],
    )
    policy = MediaPolicy(audio=MediaTypePolicy(enabled=True, fallback_message="x"))
    payload = _make_request(
        messages=[msg],
        media_policy=policy,
        audio_llm_key=LlmCredentialPayload(provider="openai", model="whisper-1", api_key="sk-test"),
    )

    result = await preprocess_media(payload)

    assert result.short_circuit_response is None
    assert result.payload.messages[0].content == "[Transcrição de áudio]: ola tudo bem"


@pytest.mark.asyncio
async def test_document_always_falls_back_even_if_enabled_in_storage() -> None:
    msg = ChatwootMessage(
        content=None,
        attachments=[
            MediaAttachment(file_type="file", content_type="application/pdf", data_url="http://x")
        ],
    )
    policy = MediaPolicy(document=MediaTypePolicy(enabled=True, fallback_message="docs em breve"))
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is not None
    assert "docs em breve" in (result.short_circuit_response.response.content or "")


@pytest.mark.asyncio
async def test_dedupes_fallback_messages_per_type() -> None:
    msg = ChatwootMessage(
        content=None,
        attachments=[
            MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://a"),
            MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://b"),
        ],
    )
    policy = MediaPolicy(audio=MediaTypePolicy(enabled=False, fallback_message="sem audio"))
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is not None
    assert result.short_circuit_response.response.content == "sem audio"


@pytest.mark.asyncio
async def test_no_attachments_returns_payload_unchanged() -> None:
    msg = ChatwootMessage(content="oi")
    payload = _make_request(messages=[msg])

    result = await preprocess_media(payload)

    assert result.short_circuit_response is None
    assert result.fallback_prefix is None
    assert result.payload.messages[0].content == "oi"
