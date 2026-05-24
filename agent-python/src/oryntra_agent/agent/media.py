"""Media preprocessing: classify attachments, transcribe audio, describe images,
build fallback prefix, and decide short-circuit responses.

Pipeline:
- classify each attachment as audio | image | document | video | other
- if its type is enabled and a key is configured: download + transcribe/describe
- otherwise: queue the type's fallback_message (deduped per type)
- if all messages end up with no text content AND we have any fallback: short-circuit
  the LLM by returning a ChatwootRuntimeResponse directly
"""

from __future__ import annotations

import base64
import logging
import re
from dataclasses import dataclass
from typing import Any, Literal
from urllib.parse import urlparse

import httpx

from oryntra_agent.api.schemas import (
    ChatwootMessage,
    ChatwootRuntimeRequest,
    ChatwootRuntimeResponse,
    LlmCredentialPayload,
    MediaAttachment,
    MediaPolicy,
    RuntimeResponsePayload,
    RuntimeUsage,
)

logger = logging.getLogger(__name__)

MediaKind = Literal["audio", "image", "document", "video", "other"]

AUDIO_FILE_EXTENSIONS: dict[str, str] = {
    "audio/ogg": ".ogg",
    "audio/opus": ".ogg",
    "audio/mp4": ".m4a",
    "audio/mpeg": ".mp3",
    "audio/webm": ".webm",
    "audio/wav": ".wav",
    "audio/x-m4a": ".m4a",
}

VISION_PROVIDERS: frozenset[str] = frozenset({"openai", "anthropic", "gemini"})
AUDIO_PROVIDERS: frozenset[str] = frozenset({"openai", "gemini"})

MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024
DOWNLOAD_TIMEOUT_SECONDS = 30

LOCALHOST_URL_RE = re.compile(r"^(https?)://localhost(:\d+)?", re.IGNORECASE)


def rewrite_localhost_url(url: str, internal_base_url: str | None) -> str:
    """Rewrite `http(s)://localhost(:port)/...` to `internal_base_url/...`.

    Used in dev: Chatwoot stores URLs with its public host (localhost:3000) but
    Oryntra containers cannot reach the host's localhost. In production with a
    real domain this is a no-op.
    """
    if not internal_base_url:
        return url
    if not LOCALHOST_URL_RE.match(url):
        return url
    base = internal_base_url.rstrip("/")
    parsed = urlparse(url)
    suffix = parsed.path + (("?" + parsed.query) if parsed.query else "")
    return base + suffix


def classify_attachment(att: MediaAttachment) -> MediaKind:
    mime = (att.content_type or "").lower()
    ftype = (att.file_type or "").lower()

    if mime.startswith("audio/") or ftype in {"audio", "voice"}:
        return "audio"
    if mime.startswith("image/") or ftype in {"image", "photo"}:
        return "image"
    if mime.startswith("video/") or ftype == "video":
        return "video"
    if mime.startswith("application/") or mime.startswith("text/") or ftype in {"file", "document"}:
        return "document"
    return "other"


@dataclass(frozen=True)
class PreprocessResult:
    payload: ChatwootRuntimeRequest
    fallback_prefix: str | None
    short_circuit_response: ChatwootRuntimeResponse | None


def _build_short_circuit_response(fallback_prefix: str) -> ChatwootRuntimeResponse:
    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=fallback_prefix,
            confidence=1.0,
        ),
        specialist_id=None,
        trace=[],
        usage=RuntimeUsage(),
    )


def _internal_base_url(payload: ChatwootRuntimeRequest) -> str | None:
    cfg = payload.runtime_config or {}
    val = cfg.get("chatwoot_internal_base_url")
    return val if isinstance(val, str) and val.strip() else None


def _first_vision_capable_specialist(
    payload: ChatwootRuntimeRequest,
) -> LlmCredentialPayload | None:
    for spec in payload.specialists:
        if (
            spec.llm_provider in VISION_PROVIDERS
            and spec.llm_model
            and spec.llm_api_key is not None
        ):
            return LlmCredentialPayload(
                provider=spec.llm_provider,
                model=spec.llm_model,
                api_key=spec.llm_api_key,
            )
    return None


def _maybe_add_fallback(
    snippets: list[str],
    seen: set[MediaKind],
    kind: MediaKind,
    policy: MediaPolicy,
) -> None:
    if kind in seen:
        return
    type_policy = getattr(policy, kind, None)
    if type_policy is None:
        return
    msg = (type_policy.fallback_message or "").strip()
    if msg:
        snippets.append(msg)
        seen.add(kind)


async def preprocess_media(payload: ChatwootRuntimeRequest) -> PreprocessResult:
    policy: MediaPolicy = payload.media_policy
    internal_base = _internal_base_url(payload)
    specialist_vision = _first_vision_capable_specialist(payload)

    fallback_snippets: list[str] = []
    seen_fallback_types: set[MediaKind] = set()

    new_messages: list[ChatwootMessage] = []

    for message in payload.messages:
        if not message.attachments:
            new_messages.append(message)
            continue

        extracted_for_message: list[str] = []

        for att in message.attachments:
            kind = classify_attachment(att)

            if kind == "audio":
                if policy.audio.enabled and payload.audio_llm_key is not None:
                    text = await _process_audio(
                        att, payload.audio_llm_key, internal_base, payload.workspace_id
                    )
                    if text:
                        extracted_for_message.append(text)
                else:
                    _maybe_add_fallback(fallback_snippets, seen_fallback_types, "audio", policy)

            elif kind == "image":
                if policy.image.enabled:
                    text = await _process_image(
                        att,
                        payload.vision_llm_key,
                        specialist_vision,
                        internal_base,
                        payload.workspace_id,
                    )
                    if text:
                        extracted_for_message.append(text)
                else:
                    _maybe_add_fallback(fallback_snippets, seen_fallback_types, "image", policy)

            elif kind == "document":
                # Not implemented this phase: always fallback regardless of stored enabled flag.
                _maybe_add_fallback(fallback_snippets, seen_fallback_types, "document", policy)

            elif kind == "video":
                _maybe_add_fallback(fallback_snippets, seen_fallback_types, "video", policy)

        if extracted_for_message:
            base = (message.content or "").strip()
            joined = "\n\n".join(extracted_for_message)
            new_content = f"{base}\n\n{joined}".strip() if base else joined
            new_messages.append(message.model_copy(update={"content": new_content}))
        else:
            new_messages.append(message)

    new_payload = payload.model_copy(update={"messages": new_messages})
    fallback_prefix = "\n\n".join(fallback_snippets).strip() or None

    has_any_content = any((m.content or "").strip() for m in new_messages)
    if fallback_prefix and not has_any_content:
        logger.info(
            "media.short_circuit_fallback",
            extra={"workspace_id": payload.workspace_id, "agent_id": payload.agent_id},
        )
        return PreprocessResult(
            payload=new_payload,
            fallback_prefix=fallback_prefix,
            short_circuit_response=_build_short_circuit_response(fallback_prefix),
        )

    return PreprocessResult(
        payload=new_payload,
        fallback_prefix=fallback_prefix,
        short_circuit_response=None,
    )


# ---- audio ----------------------------------------------------------------


async def _process_audio(
    att: MediaAttachment,
    key: LlmCredentialPayload,
    internal_base: str | None,
    workspace_id: int,
) -> str:
    if att.transcribed_text and att.transcribed_text.strip():
        return f"[Transcrição de áudio]: {att.transcribed_text.strip()}"

    if key.provider not in AUDIO_PROVIDERS:
        return f"[Áudio: provedor '{key.provider}' não suporta transcrição]"

    data = await _download(att, internal_base)
    if data is None:
        return "[Áudio: não foi possível baixar o arquivo]"

    if key.provider == "openai":
        return await _whisper(data, att, key, workspace_id)
    if key.provider == "gemini":
        return await _gemini_audio(data, att, key, workspace_id)
    return ""


async def _whisper(
    data: bytes,
    att: MediaAttachment,
    key: LlmCredentialPayload,
    workspace_id: int,
) -> str:
    import openai

    content_type = att.content_type or "audio/ogg"
    ext = AUDIO_FILE_EXTENSIONS.get(content_type, ".ogg")
    filename = f"audio{ext}"

    try:
        client = openai.AsyncOpenAI(api_key=key.api_key.get_secret_value())
        transcript = await client.audio.transcriptions.create(
            model=key.model or "whisper-1",
            file=(filename, data, content_type),
            response_format="text",
        )
        text = transcript.strip() if isinstance(transcript, str) else str(transcript).strip()
        return f"[Transcrição de áudio]: {text}" if text else "[Áudio: transcrição vazia]"
    except Exception as exc:
        logger.exception(
            "media.whisper_failed",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return "[Áudio: erro inesperado na transcrição]"


async def _gemini_audio(
    data: bytes,
    att: MediaAttachment,
    key: LlmCredentialPayload,
    workspace_id: int,
) -> str:
    from langchain_core.messages import HumanMessage
    from langchain_google_genai import ChatGoogleGenerativeAI

    content_type = att.content_type or "audio/ogg"

    try:
        llm = ChatGoogleGenerativeAI(
            model=key.model or "gemini-2.0-flash",
            google_api_key=key.api_key.get_secret_value(),
            temperature=0,
        )
        b64 = base64.b64encode(data).decode("utf-8")
        message = HumanMessage(
            content=[
                {
                    "type": "text",
                    "text": "Transcreva este áudio em português. Retorne apenas o texto transcrito.",
                },
                {
                    "type": "media",
                    "data": f"data:{content_type};base64,{b64}",
                    "mimeType": content_type,
                },
            ]
        )
        response = await llm.ainvoke([message])
        text = response.content if isinstance(response.content, str) else ""
        text = (text or "").strip()
        return f"[Transcrição de áudio]: {text}" if text else "[Áudio: transcrição vazia]"
    except Exception as exc:
        logger.exception(
            "media.gemini_audio_failed",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return f"[Áudio: erro Gemini — {type(exc).__name__}]"


# ---- image ----------------------------------------------------------------


async def _process_image(
    att: MediaAttachment,
    vision_key: LlmCredentialPayload | None,
    specialist_vision_key: LlmCredentialPayload | None,
    internal_base: str | None,
    workspace_id: int,
) -> str:
    key = (
        vision_key
        if (vision_key is not None and vision_key.provider in VISION_PROVIDERS)
        else specialist_vision_key
    )
    if key is None or key.provider not in VISION_PROVIDERS:
        return "[Imagem: nenhuma chave LLM com visão disponível]"

    if not att.data_url:
        return "[Imagem: URL não disponível]"

    url = rewrite_localhost_url(att.data_url, internal_base)

    try:
        from langchain_core.messages import HumanMessage

        chat_model = _build_vision_chat_model(key)
        if chat_model is None:
            return f"[Imagem: provedor '{key.provider}' não suporta visão]"

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
        description = response.content if isinstance(response.content, str) else ""
        description = (description or "").strip()
        return f"[Descrição de imagem]: {description}" if description else "[Imagem: descrição vazia]"
    except Exception as exc:
        logger.exception(
            "media.vision_failed",
            extra={"workspace_id": workspace_id, "error": str(exc)},
        )
        return f"[Imagem: erro na descrição — {type(exc).__name__}]"


def _build_vision_chat_model(key: LlmCredentialPayload) -> Any | None:
    if key.provider == "openai":
        from langchain_openai import ChatOpenAI

        return ChatOpenAI(
            model=key.model,
            api_key=key.api_key,
            temperature=0,
        )
    if key.provider == "anthropic":
        from langchain_anthropic import ChatAnthropic

        return ChatAnthropic(
            model_name=key.model,
            api_key=key.api_key,
            temperature=0,
            timeout=None,
            stop=None,
        )
    if key.provider == "gemini":
        from langchain_google_genai import ChatGoogleGenerativeAI

        return ChatGoogleGenerativeAI(
            model=key.model,
            google_api_key=key.api_key.get_secret_value(),
            temperature=0,
        )
    return None


# ---- download -------------------------------------------------------------


async def _download(att: MediaAttachment, internal_base: str | None) -> bytes | None:
    if not att.data_url:
        return None
    url = rewrite_localhost_url(att.data_url, internal_base)
    try:
        async with httpx.AsyncClient(timeout=DOWNLOAD_TIMEOUT_SECONDS) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()
            if len(response.content) > MAX_DOWNLOAD_BYTES:
                logger.warning(
                    "media.too_large",
                    extra={"size": len(response.content), "max": MAX_DOWNLOAD_BYTES},
                )
                return None
            return response.content
    except httpx.HTTPError:
        logger.exception("media.download_failed", extra={"url_head": url[:120]})
        return None


__all__ = [
    "AUDIO_PROVIDERS",
    "VISION_PROVIDERS",
    "PreprocessResult",
    "classify_attachment",
    "preprocess_media",
    "rewrite_localhost_url",
]
