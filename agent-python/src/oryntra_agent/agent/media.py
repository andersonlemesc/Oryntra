"""Media preprocessing: audio transcription and image description.

Downloads attachments from Chatwoot presigned URLs, dispatches to the
correct provider API based on the media LLM key, and returns text
transcripts/descriptions.

Provider dispatch:
- Audio: OpenAI (Whisper) or Gemini (inline audio)
- Vision: OpenAI (GPT-4o), Anthropic (Claude), or Gemini
  Falls back to media_llm_key if specialist model lacks vision.
"""

from __future__ import annotations

import logging
from typing import TYPE_CHECKING, Any

import httpx

from oryntra_agent.api.schemas import LlmCredential, MediaAttachment, MediaConfig

if TYPE_CHECKING:
    from oryntra_agent.api.schemas import ChatwootMessage

logger = logging.getLogger(__name__)

SUPPORTED_AUDIO_MIME_TYPES: frozenset[str] = frozenset(
    {
        "audio/ogg",
        "audio/mp4",
        "audio/mpeg",
        "audio/webm",
        "audio/amr",
        "audio/wav",
        "audio/x-m4a",
    }
)

SUPPORTED_IMAGE_MIME_TYPES: frozenset[str] = frozenset(
    {
        "image/jpeg",
        "image/png",
        "image/webp",
        "image/gif",
    }
)

AUDIO_FILE_EXTENSIONS: dict[str, str] = {
    "audio/ogg": ".ogg",
    "audio/mp4": ".m4a",
    "audio/mpeg": ".mp3",
    "audio/webm": ".webm",
    "audio/amr": ".amr",
    "audio/wav": ".wav",
    "audio/x-m4a": ".m4a",
}

MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024

VISION_PROVIDERS: frozenset[str] = frozenset({"openai", "anthropic", "gemini"})
AUDIO_PROVIDERS: frozenset[str] = frozenset({"openai", "gemini"})


def _is_audio(mime_type: str, file_type: str | None) -> bool:
    if mime_type in SUPPORTED_AUDIO_MIME_TYPES:
        return True
    if file_type and file_type in {"audio", "voice", "ogg", "mp3", "m4a", "webm_audio"}:
        return True
    return False


def _is_image(mime_type: str, file_type: str | None) -> bool:
    if mime_type in SUPPORTED_IMAGE_MIME_TYPES:
        return True
    if file_type and file_type in {"image", "png", "jpeg", "jpg", "gif", "webp"}:
        return True
    return False


def _resolve_audio_key(media_llm_key: LlmCredential | None) -> LlmCredential | None:
    if media_llm_key is None:
        return None
    if media_llm_key.provider not in AUDIO_PROVIDERS:
        return None
    return media_llm_key


def _resolve_vision_key(
    specialist_llm_key: LlmCredential | None,
    media_llm_key: LlmCredential | None,
) -> LlmCredential | None:
    if specialist_llm_key is not None and specialist_llm_key.provider in VISION_PROVIDERS:
        return specialist_llm_key
    if media_llm_key is not None and media_llm_key.provider in VISION_PROVIDERS:
        return media_llm_key
    return None


async def _download_attachment(
    attachment: MediaAttachment,
    max_bytes: int = MAX_DOWNLOAD_BYTES,
) -> bytes | None:
    url = attachment.data_url
    if not url:
        return None

    try:
        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()

            if len(response.content) > max_bytes:
                logger.warning(
                    "attachment exceeds max size",
                    extra={"url": url[:100] if url else "none", "size": len(response.content)},
                )
                return None

            return response.content
    except httpx.HTTPError:
        logger.exception("failed to download attachment", extra={"url": url[:100] if url else "none"})
        return None


async def _transcribe_with_openai(
    data: bytes,
    attachment: MediaAttachment,
    key: LlmCredential,
    workspace_id: int,
) -> str:
    import openai

    content_type = attachment.content_type or "audio/ogg"
    ext = AUDIO_FILE_EXTENSIONS.get(content_type, ".ogg")
    filename = f"audio{ext}"

    try:
        client = openai.AsyncOpenAI(api_key=key.api_key)
        transcript = await client.audio.transcriptions.create(
            model="whisper-1",
            file=(filename, data, content_type),
            response_format="text",
        )
        text = transcript.strip() if isinstance(transcript, str) else str(transcript).strip()
        if not text:
            return "[Áudio: transcrição vazia]"
        return f"[Transcrição de áudio]: {text}"
    except openai.APIError as exc:
        logger.exception(
            "whisper transcription failed",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return f"[Áudio: erro na transcrição — status {exc.status_code}]"
    except Exception as exc:
        logger.exception(
            "whisper transcription failed unexpectedly",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return "[Áudio: erro inesperado na transcrição]"


async def _transcribe_with_gemini(
    data: bytes,
    attachment: MediaAttachment,
    key: LlmCredential,
    workspace_id: int,
) -> str:
    import base64

    from langchain_core.messages import HumanMessage
    from langchain_google_genai import ChatGoogleGenerativeAI

    content_type = attachment.content_type or "audio/ogg"
    model_name = key.model or "gemini-2.0-flash"

    try:
        llm = ChatGoogleGenerativeAI(
            model=model_name,
            google_api_key=key.api_key,
            temperature=0,
        )
        b64_audio = base64.b64encode(data).decode("utf-8")

        message = HumanMessage(
            content=[
                {
                    "type": "text",
                    "text": "Transcreva este áudio em português. Retorne apenas o texto transcrito.",
                },
                {
                    "type": "media",
                    "data": f"data:{content_type};base64,{b64_audio}",
                    "mimeType": content_type,
                },
            ]
        )
        response = await llm.ainvoke([message])
        text = (response.content or "").strip()
        if not text:
            return "[Áudio: transcrição vazia]"
        return f"[Transcrição de áudio]: {text}"
    except Exception as exc:
        logger.exception(
            "gemini audio transcription failed",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return f"[Áudio: erro na transcrição Gemini — {type(exc).__name__}]"


async def _transcribe_audio(
    attachment: MediaAttachment,
    media_llm_key: LlmCredential | None,
    workspace_id: int = 0,
) -> str:
    key = _resolve_audio_key(media_llm_key)

    if key is None:
        return (
            "[Áudio: nenhuma chave LLM configurada para transcrição. "
            "Configure uma chave OpenAI ou Gemini em Mídia.]"
        )

    data = await _download_attachment(attachment)
    if data is None:
        return "[Áudio: não foi possível baixar o arquivo]"

    if key.provider == "openai":
        return await _transcribe_with_openai(data, attachment, key, workspace_id)
    if key.provider == "gemini":
        return await _transcribe_with_gemini(data, attachment, key, workspace_id)

    return f"[Áudio: provedor '{key.provider}' não suporta transcrição]"


async def _describe_image(
    attachment: MediaAttachment,
    media_llm_key: LlmCredential | None,
    specialist_llm_key: LlmCredential | None = None,
) -> str:
    from oryntra_agent.agent.supervisor import chat_model_for_credential

    key = _resolve_vision_key(specialist_llm_key, media_llm_key)

    if key is None:
        return (
            "[Imagem: nenhuma chave LLM com visão disponível. "
            "Configure uma chave em Mídia.]"
        )

    url = attachment.data_url
    if not url:
        return "[Imagem: URL não disponível]"

    try:
        chat_model = chat_model_for_credential(key, temperature=0.1)

        if chat_model is None:
            return f"[Imagem: provedor '{key.provider}' não suporta visão]"

        from langchain_core.messages import HumanMessage

        message = HumanMessage(
            content=[
                {
                    "type": "text",
                    "text": (
                        "Descreva esta imagem de forma objetiva e detalhada em português. "
                        "Foque no que é relevante para um atendimento ao cliente."
                    ),
                },
                {"type": "image_url", "image_url": {"url": url}},
            ]
        )
        response = await chat_model.ainvoke([message])
        description = (response.content or "").strip()

        if not description:
            return "[Imagem: descrição vazia]"

        return f"[Descrição de imagem]: {description}"
    except Exception as exc:
        logger.exception("vision description failed", extra={"error": str(exc)})
        return f"[Imagem: erro na descrição — {type(exc).__name__}]"


async def preprocess_message_attachments(
    messages: list[ChatwootMessage],
    media_config: MediaConfig,
    media_llm_key: LlmCredential | None,
    specialist_llm_key: LlmCredential | None = None,
    workspace_id: int = 0,
    agent_id: int = 0,
) -> list[ChatwootMessage]:
    if not media_config.vision_enabled and not media_config.audio_enabled:
        return messages

    processed: list[ChatwootMessage] = []

    for message in messages:
        if not message.attachments:
            processed.append(message)
            continue

        extra_parts: list[str] = []

        for attachment in message.attachments:
            content_type = attachment.content_type or ""
            file_type = attachment.file_type

            if _is_audio(content_type, file_type) and media_config.audio_enabled:
                result = await _transcribe_audio(
                    attachment=attachment,
                    media_llm_key=media_llm_key,
                    workspace_id=workspace_id,
                )
                extra_parts.append(result)
                continue

            if _is_image(content_type, file_type) and media_config.vision_enabled:
                result = await _describe_image(
                    attachment=attachment,
                    media_llm_key=media_llm_key,
                    specialist_llm_key=specialist_llm_key,
                )
                extra_parts.append(result)
                continue

        if extra_parts:
            existing_content = message.content or ""
            combined = existing_content
            if combined:
                combined += "\n\n"
            combined += "\n\n".join(extra_parts)
            processed.append(message.model_copy(update={"content": combined}))
        else:
            processed.append(message)

    return processed


def transcribe_audio_sync(
    attachment_url: str,
    content_type: str,
    media_llm_key: LlmCredential | None,
    workspace_id: int = 0,
) -> str:
    import asyncio

    attachment = MediaAttachment(content_type=content_type, data_url=attachment_url)
    try:
        loop = asyncio.get_event_loop()
        if loop.is_running():
            import concurrent.futures

            with concurrent.futures.ThreadPoolExecutor() as pool:
                future = pool.submit(
                    asyncio.run,
                    _transcribe_audio(attachment, media_llm_key, workspace_id),
                )
                return future.result(timeout=60)
    except RuntimeError:
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)

    return loop.run_until_complete(_transcribe_audio(attachment, media_llm_key, workspace_id))


def describe_image_sync(
    attachment_url: str,
    prompt: str | None,
    media_llm_key: LlmCredential | None,
    specialist_llm_key: LlmCredential | None = None,
) -> str:
    import asyncio

    attachment = MediaAttachment(content_type="image/jpeg", data_url=attachment_url)
    try:
        loop = asyncio.get_event_loop()
        if loop.is_running():
            import concurrent.futures

            with concurrent.futures.ThreadPoolExecutor() as pool:
                future = pool.submit(
                    asyncio.run,
                    _describe_image(attachment, media_llm_key, specialist_llm_key),
                )
                return future.result(timeout=60)
    except RuntimeError:
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)

    return loop.run_until_complete(_describe_image(attachment, media_llm_key, specialist_llm_key))