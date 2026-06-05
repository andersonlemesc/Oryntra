# 2026-05-24 — Vision / Audio / Media Fallbacks (Phase 14.1)

> **For agentic workers:** Use checkbox (`- [ ]`) syntax for tracking. Implement task-by-task.

**Goal:** Allow Oryntra specialists to handle messages with media attachments (audio, image, document, video). Two paths per type:

1. **Processed**: audio → transcribed (Whisper/Gemini); image → described (GPT-4o/Claude/Gemini); text injected into `message.content` so supervisor routes as usual.
2. **Fallback**: when a type is disabled (or unsupported in this phase — `document`/`video`), the bot sends a customer-facing fallback message ("não consigo processar áudios, pode enviar em texto?") without invoking the LLM, saving tokens.

**Architecture:** Laravel passes per-type policy + per-capability LLM keys (audio + vision) to Python runtime → Python preprocesses attachments async → either injects extracted text or emits fallback text → calls sync supervisor via `asyncio.to_thread()`.

**Tech Stack:** Laravel 13, PHP 8.4, Python 3.12, FastAPI, openai (Whisper + vision), langchain-anthropic (vision), langchain-google-genai (vision + audio), httpx, Pest, pytest.

---

## Current State (verified)

- `ChatwootMessage` in Python has `attachments: list[dict[str, Any]]` (becomes `list[MediaAttachment]`).
- Laravel webhook pipeline already extracts `id`, `file_type`, `content_type`, `data_url`, `thumb_url` from Chatwoot attachments and persists them in `agent_runs.input.messages[].attachments`.
- `AgentRuntimeClient::payload()` (`laravel/app/Services/AgentRuntime/AgentRuntimeClient.php:181`) currently sends `media_config` via passthrough `objectInput($input, 'media_config')`. Will become agent-derived.
- `Agent` model already has `media_policy` (jsonb, cast to array). **Reuse** for per-type configuration.
- `AgentLlmKey` has no `model` column — model name lives on the consumer. We add `audio_llm_model` and `vision_llm_model` to `agents`.
- `run_chatwoot_runtime` is fully synchronous (~1600 lines, sync `chat_model.invoke()`). Endpoint is `async def` but doesn't `await` it. Strategy: keep supervisor sync; wrap in `asyncio.to_thread()`; run preprocessing async before the call.
- `LlmCredential` lives in `supervisor.py` and is referenced by a `ContextVar` and ~10 sites. **Don't move it.** Add a transport-only `LlmCredentialPayload` in `schemas.py`.
- `RuntimeResponsePayload.type` supports `"text"|"send_document"|"escalate"|"clarify"|"multi"` — `multi` exists but downstream handling for it is uncertain. For simplicity this phase, fallback prefix is **prepended** to the LLM response content rather than emitted as a separate `multi` message.

## Real webhook format (verified 2026-05-24, `chatwoot_webhook_events` row 588)

WhatsApp voice note:

```json
{
  "id": 122,
  "file_type": "audio",
  "content_type": "audio/opus",
  "extension": "oga",
  "file_size": 6648,
  "data_url": "http://localhost:3000/rails/active_storage/blobs/redirect/<token>/esux49.oga",
  "thumb_url": null,
  "transcribed_text": null
}
```

Key facts:
- `audio/opus` in `.oga` — Whisper accepts ogg directly.
- `data_url` is Chatwoot Active Storage redirect (HTTP 302) — httpx needs `follow_redirects=True`.
- URL host is `localhost:3000` — containers cannot reach the host's localhost. Must rewrite to `host.docker.internal:3000` (from `CHATWOOT_PLATFORM_BASE_URL` env) before downloading in dev. In production with a real domain, no rewrite needed.
- Chatwoot has an opt-in `transcribed_text` field — if present, use it as cache.

Today: a webhook with only audio → `agent_runs.input.messages[0] = { content: null, attachments: [...] }` → supervisor route returns `confidence: 0, "Mensagem sem conteúdo"` → bot replies generic greeting. Phase 14.1 fixes this by either populating `content` with the transcript or short-circuiting with a fallback message.

## Out Of Scope

- Document and video **processing** (only their fallback messages are handled this phase).
- RAG on images/PDFs (Phase 10).
- Persistent media storage in MinIO.
- Specialist-callable media tools (`transcribe_audio`, `vision_describe`) — preprocessing is automatic.
- AMR/codec transcoding. WhatsApp typically sends `audio/opus`; if AMR ever arrives we log and fallback.
- Sending fallback as a separate Chatwoot message via `response.type = "multi"`. Prefixed to the LLM response instead.

## Design Decisions

### 1. `media_policy` shape (reuse existing jsonb on agents)

```json
{
  "audio":    { "enabled": false, "fallback_message": "Desculpe, não consigo processar áudios no momento. Pode enviar em texto?" },
  "image":    { "enabled": false, "fallback_message": "Desculpe, não consigo processar imagens no momento. Pode descrever em texto?" },
  "document": { "enabled": false, "fallback_message": "Desculpe, ainda não processo documentos. Pode resumir o conteúdo em texto?" },
  "video":    { "enabled": false, "fallback_message": "Desculpe, ainda não processo vídeos. Pode descrever em texto?" }
}
```

Semantics:
- `audio.enabled` / `image.enabled`: when true AND the corresponding LLM key is set, run preprocessing. Otherwise → fallback path.
- `document.enabled` / `video.enabled`: ignored this phase; always fallback. Toggle is rendered **disabled** in Filament with an "em breve" badge so users know it's coming. The fallback message remains editable so customers always get a response.

### 2. New columns on `agents`

```
agents.audio_llm_key_id    →  FK agent_llm_keys (nullable, nullOnDelete)
agents.audio_llm_model     →  text nullable   (e.g. "whisper-1", "gemini-2.0-flash")
agents.vision_llm_key_id   →  FK agent_llm_keys (nullable, nullOnDelete)
agents.vision_llm_model    →  text nullable   (e.g. "gpt-4o", "claude-sonnet-4-20250514", "gemini-2.0-flash")
```

Per-capability keys allow Whisper for audio + Claude for vision in the same agent.

### 3. Filament — aba "Mídia"

```
Tab "Mídia"
├── Section "Áudio"
│    ├── Toggle: Transcrever áudio                    → media_policy.audio.enabled
│    ├── Select: Chave LLM (áudio)  [visible if on]   → audio_llm_key_id (filter provider ∈ {openai, gemini})
│    ├── TextInput: Modelo            [visible if on] → audio_llm_model
│    └── Textarea: Mensagem fallback                  → media_policy.audio.fallback_message
│
├── Section "Imagem"
│    ├── Toggle: Descrever imagem                     → media_policy.image.enabled
│    ├── Select: Chave LLM (visão)  [visible if on]   → vision_llm_key_id (filter provider ∈ {openai, anthropic, gemini})
│    ├── TextInput: Modelo            [visible if on] → vision_llm_model
│    └── Textarea: Mensagem fallback                  → media_policy.image.fallback_message
│
├── Section "Documento"
│    ├── Toggle: Processar (em breve)  [disabled]     → forced false
│    └── Textarea: Mensagem fallback                  → media_policy.document.fallback_message
│
└── Section "Vídeo"
     ├── Toggle: Processar (em breve)  [disabled]     → forced false
     └── Textarea: Mensagem fallback                  → media_policy.video.fallback_message
```

### 4. Preprocessing logic (per message)

For each incoming message:

1. Classify each attachment → `audio | image | document | video | other`.
2. Bucket each into:
   - **processable**: type's `enabled` is true and required key is configured.
   - **fallback**: type's `enabled` is false (or `document`/`video` always; or audio enabled but no key).
3. Run processable attachments through the right provider → produce extracted text snippets.
4. Build new `message.content`:
   - existing text (if any)
   - `\n\n` + extracted snippets

5. Build a **fallback prefix** by joining `fallback_message` of distinct fallback types (deduped — one audio fallback even if 3 audio files in same message).

6. Decide response strategy at the endpoint level:
   - If **all** messages have empty resulting content AND any fallback prefix exists → **bypass LLM**, return `RuntimeResponsePayload(type="text", content=joined_fallback_prefixes)`. Trace: `media_fallback_short_circuit`.
   - Otherwise → call supervisor as usual. After supervisor returns, **prepend** the fallback prefix to `response.content` if present.

### 5. URL rewrite for dev

Laravel passes `chatwoot_internal_base_url` in `runtime_config` (from `CHATWOOT_PLATFORM_BASE_URL` env). Python `_download_attachment` rewrites `http(s)://localhost(:port)/...` to that base. No-op in production with a real domain.

### 6. Async without rewriting supervisor

```python
@router.post("/messages")
async def handle_chatwoot_messages(payload):
    result = await preprocess_media(payload)
    if result.short_circuit_response is not None:
        return result.short_circuit_response
    response = await asyncio.to_thread(run_chatwoot_runtime, result.payload)
    if result.fallback_prefix:
        response = _prepend_to_text(response, result.fallback_prefix)
    return response
```

### 7. Provider dispatch

| Capability | Provider | API / Model |
|---|---|---|
| Audio | OpenAI | `whisper-1` via `openai.AsyncOpenAI().audio.transcriptions.create()` |
| Audio | Gemini | inline audio via `langchain-google-genai` (**validate format via `search-docs` before Task 3**) |
| Vision | OpenAI | GPT-4o family via `ChatOpenAI` multimodal |
| Vision | Anthropic | Claude Sonnet 4 via `ChatAnthropic` multimodal |
| Vision | Gemini | `gemini-2.0-flash` via `ChatGoogleGenerativeAI` multimodal |

Vision fallback: if `vision_llm_key_id` is null, use the first active specialist whose own LLM provider is vision-capable. Audio has no fallback chain — requires `audio_llm_key_id`; if missing, audio is treated as fallback type.

### 8. Cache via Chatwoot `transcribed_text`

If `attachment.transcribed_text` is non-empty, skip Whisper/Gemini and inject it directly.

## File Structure

### Laravel (all under `laravel/`)

| File | Action | Responsibility |
|------|--------|---------------|
| `laravel/database/migrations/2026_05_24_200000_add_media_llm_keys_to_agents_table.php` | CREATE | `audio_llm_key_id`, `audio_llm_model`, `vision_llm_key_id`, `vision_llm_model` |
| `laravel/app/Models/Agent.php` | MODIFY | Append fillable + `audioLlmKey` / `visionLlmKey` relations |
| `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php` | MODIFY | Replace passthrough `media_config` with agent-derived structured policy + 2 LLM key payloads + internal base URL in `runtime_config` |
| `laravel/app/Filament/Resources/Agents/Schemas/AgentForm.php` | MODIFY | Add "Mídia" tab with 4 sections |
| `laravel/config/services.php` | MODIFY | Add `chatwoot.internal_base_url` from env |
| `laravel/tests/Feature/Agent/MediaPolicyPayloadTest.php` | CREATE | Test payload shape |
| `laravel/tests/Feature/Agent/MediaPipelineTest.php` | CREATE | Test attachments + internal base URL pass-through |

### Python (agent-python)

| File | Action | Responsibility |
|------|--------|---------------|
| `agent-python/src/oryntra_agent/api/schemas.py` | MODIFY | Add `MediaAttachment`, `MediaTypePolicy`, `MediaPolicy`, `LlmCredentialPayload`; type `ChatwootMessage.attachments`; add `audio_llm_key`/`vision_llm_key` |
| `agent-python/src/oryntra_agent/agent/media.py` | CREATE | URL rewrite, classify, download, transcribe, describe, build prefix, decide short-circuit |
| `agent-python/src/oryntra_agent/api/chatwoot_messages.py` | MODIFY | Async pipeline: preprocess → short-circuit OR `to_thread(supervisor)` + prepend prefix |
| `agent-python/tests/test_media_url_rewrite.py` | CREATE | URL rewrite |
| `agent-python/tests/test_media_classification.py` | CREATE | classify + policy bucketing + provider picker |
| `agent-python/tests/test_media_preprocess_paths.py` | CREATE | short-circuit, prefix prepend, cache via `transcribed_text` |

---

### Task 1: Migration

**Files:**
- Create: `laravel/database/migrations/2026_05_24_200000_add_media_llm_keys_to_agents_table.php`
- Modify: `laravel/app/Models/Agent.php`

- [ ] **Step 1: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('audio_llm_key_id')->nullable()->constrained('agent_llm_keys')->nullOnDelete();
            $table->text('audio_llm_model')->nullable();
            $table->foreignId('vision_llm_key_id')->nullable()->constrained('agent_llm_keys')->nullOnDelete();
            $table->text('vision_llm_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['audio_llm_key_id']);
            $table->dropForeign(['vision_llm_key_id']);
            $table->dropColumn(['audio_llm_key_id', 'audio_llm_model', 'vision_llm_key_id', 'vision_llm_model']);
        });
    }
};
```

- [ ] **Step 2: Update Agent model**

Append to `#[Fillable([...])]`:

```php
'audio_llm_key_id',
'audio_llm_model',
'vision_llm_key_id',
'vision_llm_model',
```

Add relations:

```php
/** @return BelongsTo<AgentLlmKey, $this> */
public function audioLlmKey(): BelongsTo
{
    return $this->belongsTo(AgentLlmKey::class, 'audio_llm_key_id');
}

/** @return BelongsTo<AgentLlmKey, $this> */
public function visionLlmKey(): BelongsTo
{
    return $this->belongsTo(AgentLlmKey::class, 'vision_llm_key_id');
}
```

- [ ] **Step 3: Run migration**

```bash
docker compose exec laravel-app php artisan migrate --force
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: add audio/vision llm key fks and models to agents"
```

---

### Task 2: Python schemas

**Files:**
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`

- [ ] **Step 1: Add models, update `ChatwootMessage` + `ChatwootRuntimeRequest`**

```python
class MediaAttachment(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: int | None = None
    file_type: str | None = None
    content_type: str | None = None
    data_url: str | None = None
    thumb_url: str | None = None
    extension: str | None = None
    file_size: int | None = None
    transcribed_text: str | None = None


class MediaTypePolicy(BaseModel):
    model_config = ConfigDict(extra="forbid")

    enabled: bool = False
    fallback_message: str = ""


class MediaPolicy(BaseModel):
    model_config = ConfigDict(extra="forbid")

    audio: MediaTypePolicy = Field(default_factory=MediaTypePolicy)
    image: MediaTypePolicy = Field(default_factory=MediaTypePolicy)
    document: MediaTypePolicy = Field(default_factory=MediaTypePolicy)
    video: MediaTypePolicy = Field(default_factory=MediaTypePolicy)


class LlmCredentialPayload(BaseModel):
    """Transport-only credential; supervisor converts to its internal LlmCredential."""

    model_config = ConfigDict(extra="forbid")

    provider: Literal["openai", "anthropic", "gemini", "local"]
    model: str
    api_key: SecretStr = Field(exclude=True)
```

Change `ChatwootMessage.attachments`:

```python
# Before:
attachments: list[dict[str, Any]] = Field(default_factory=list)
# After:
attachments: list[MediaAttachment] = Field(default_factory=list)
```

Replace `media_config` in `ChatwootRuntimeRequest` and add the two keys:

```python
# Before:
media_config: dict[str, Any] = Field(default_factory=dict)
# After:
media_policy: MediaPolicy = Field(default_factory=MediaPolicy)
audio_llm_key: LlmCredentialPayload | None = Field(default=None, exclude=True)
vision_llm_key: LlmCredentialPayload | None = Field(default=None, exclude=True)
```

(Note: field renamed `media_config` → `media_policy` to match the Laravel column. If keeping `media_config` is preferred for less churn, do so consistently with the Laravel payload key in Task 5.)

- [ ] **Step 2: Linters**

```bash
docker compose exec agent-python ruff check src/oryntra_agent/api/schemas.py
docker compose exec agent-python mypy src/oryntra_agent/api/schemas.py
```

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "feat: add MediaAttachment, MediaPolicy, LlmCredentialPayload to schemas"
```

---

### Task 3: `media.py` — classification, download, dispatch, prefix/short-circuit

**Files:**
- Create: `agent-python/src/oryntra_agent/agent/media.py`

- [ ] **Step 1: Validate Gemini inline audio payload via `search-docs`**

Before implementing the Gemini audio branch, run docs search for `langchain-google-genai` inline audio. If the payload shape `{"type": "media", "data": "data:<mime>;base64,...", "mimeType": "..."}` is unsupported, either: (a) implement only OpenAI audio in this phase and have Gemini audio fall through to placeholder, or (b) use Google's native SDK directly.

- [ ] **Step 2: Write the module**

Module exports:
- `rewrite_localhost_url(url, internal_base_url) -> str`
- `classify_attachment(att) -> Literal["audio","image","document","video","other"]`
- `preprocess_media(payload) -> PreprocessResult` with:
  - `payload: ChatwootRuntimeRequest` (updated messages)
  - `fallback_prefix: str | None`
  - `short_circuit_response: ChatwootRuntimeResponse | None`

Skeleton:

```python
"""Media preprocessing: classify, download, transcribe/describe, build fallbacks."""

from __future__ import annotations

import asyncio
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
    # everything else (pdf, doc, xls, generic) -> document
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


async def preprocess_media(payload: ChatwootRuntimeRequest) -> PreprocessResult:
    policy: MediaPolicy = payload.media_policy
    internal_base = _internal_base_url(payload)
    specialist_vision = _first_vision_capable_specialist(payload)

    fallback_snippets: list[str] = []          # deduped per type across all messages
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
                    text = await _process_audio(att, payload.audio_llm_key, internal_base, payload.workspace_id)
                    if text:
                        extracted_for_message.append(text)
                else:
                    _maybe_add_fallback(fallback_snippets, seen_fallback_types, "audio", policy)

            elif kind == "image":
                if policy.image.enabled:
                    text = await _process_image(att, payload.vision_llm_key, specialist_vision, internal_base, payload.workspace_id)
                    if text:
                        extracted_for_message.append(text)
                else:
                    _maybe_add_fallback(fallback_snippets, seen_fallback_types, "image", policy)

            elif kind == "document":
                # Not implemented this phase — always fallback
                _maybe_add_fallback(fallback_snippets, seen_fallback_types, "document", policy)

            elif kind == "video":
                _maybe_add_fallback(fallback_snippets, seen_fallback_types, "video", policy)
            # "other" silently ignored

        if extracted_for_message:
            base = (message.content or "").strip()
            joined = "\n\n".join(extracted_for_message)
            new_content = f"{base}\n\n{joined}".strip() if base else joined
            new_messages.append(message.model_copy(update={"content": new_content}))
        else:
            new_messages.append(message)

    new_payload = payload.model_copy(update={"messages": new_messages})
    fallback_prefix = "\n\n".join(fallback_snippets).strip() or None

    # Short-circuit if every message has no text content and we have a fallback
    has_any_content = any((m.content or "").strip() for m in new_messages)
    if fallback_prefix and not has_any_content:
        logger.info("media.short_circuit_fallback", extra={"workspace_id": payload.workspace_id})
        return PreprocessResult(
            payload=new_payload,
            fallback_prefix=fallback_prefix,
            short_circuit_response=_build_short_circuit_response(fallback_prefix),
        )

    return PreprocessResult(payload=new_payload, fallback_prefix=fallback_prefix, short_circuit_response=None)


def _maybe_add_fallback(snippets: list[str], seen: set[MediaKind], kind: MediaKind, policy: MediaPolicy) -> None:
    if kind in seen:
        return
    msg = getattr(policy, kind).fallback_message.strip()
    if msg:
        snippets.append(msg)
        seen.add(kind)


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


# ---- audio ---------------------------------------------------------------

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


async def _whisper(data: bytes, att: MediaAttachment, key: LlmCredentialPayload, workspace_id: int) -> str:
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
        logger.exception("media.whisper_failed", extra={"workspace_id": workspace_id, "error": str(exc)})
        return "[Áudio: erro inesperado na transcrição]"


async def _gemini_audio(data: bytes, att: MediaAttachment, key: LlmCredentialPayload, workspace_id: int) -> str:
    # NOTE: payload format verified via search-docs in Task 3 Step 1 before this is relied upon.
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
        message = HumanMessage(content=[
            {"type": "text", "text": "Transcreva este áudio em português. Retorne apenas o texto transcrito."},
            {"type": "media", "data": f"data:{content_type};base64,{b64}", "mimeType": content_type},
        ])
        response = await llm.ainvoke([message])
        text = response.content if isinstance(response.content, str) else ""
        text = (text or "").strip()
        return f"[Transcrição de áudio]: {text}" if text else "[Áudio: transcrição vazia]"
    except Exception as exc:
        logger.exception("media.gemini_audio_failed", extra={"workspace_id": workspace_id, "error": str(exc)})
        return f"[Áudio: erro Gemini — {type(exc).__name__}]"


# ---- image ---------------------------------------------------------------

async def _process_image(
    att: MediaAttachment,
    vision_key: LlmCredentialPayload | None,
    specialist_vision_key: LlmCredentialPayload | None,
    internal_base: str | None,
    workspace_id: int,
) -> str:
    key = vision_key if (vision_key and vision_key.provider in VISION_PROVIDERS) else specialist_vision_key
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

        message = HumanMessage(content=[
            {"type": "text", "text": (
                "Descreva esta imagem de forma objetiva e detalhada em português. "
                "Foque no que é relevante para um atendimento ao cliente."
            )},
            {"type": "image_url", "image_url": {"url": url}},
        ])
        response = await chat_model.ainvoke([message])
        description = (response.content if isinstance(response.content, str) else "") or ""
        description = description.strip()
        return f"[Descrição de imagem]: {description}" if description else "[Imagem: descrição vazia]"
    except Exception as exc:
        logger.exception("media.vision_failed", extra={"workspace_id": workspace_id, "error": str(exc)})
        return f"[Imagem: erro na descrição — {type(exc).__name__}]"


def _build_vision_chat_model(key: LlmCredentialPayload) -> Any | None:
    if key.provider == "openai":
        from langchain_openai import ChatOpenAI
        return ChatOpenAI(model=key.model, api_key=key.api_key.get_secret_value(), temperature=0)
    if key.provider == "anthropic":
        from langchain_anthropic import ChatAnthropic
        return ChatAnthropic(model=key.model, api_key=key.api_key.get_secret_value(), temperature=0)
    if key.provider == "gemini":
        from langchain_google_genai import ChatGoogleGenerativeAI
        return ChatGoogleGenerativeAI(model=key.model, google_api_key=key.api_key.get_secret_value(), temperature=0)
    return None


async def _download(att: MediaAttachment, internal_base: str | None) -> bytes | None:
    if not att.data_url:
        return None
    url = rewrite_localhost_url(att.data_url, internal_base)
    try:
        async with httpx.AsyncClient(timeout=DOWNLOAD_TIMEOUT_SECONDS) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()
            if len(response.content) > MAX_DOWNLOAD_BYTES:
                logger.warning("media.too_large", extra={"size": len(response.content)})
                return None
            return response.content
    except httpx.HTTPError:
        logger.exception("media.download_failed", extra={"url_head": url[:120]})
        return None


__all__ = [
    "preprocess_media",
    "rewrite_localhost_url",
    "classify_attachment",
    "PreprocessResult",
]
```

- [ ] **Step 3: Linters**

```bash
docker compose exec agent-python ruff check src/oryntra_agent/agent/media.py
docker compose exec agent-python mypy src/oryntra_agent/agent/media.py
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: media.py — classify/dispatch/fallback prefix/short-circuit"
```

---

### Task 4: Endpoint wiring — short-circuit + prepend fallback

**Files:**
- Modify: `agent-python/src/oryntra_agent/api/chatwoot_messages.py`

- [ ] **Step 1: Rewrite endpoint**

```python
import asyncio

from fastapi import APIRouter, Depends

from oryntra_agent.agent.media import preprocess_media
from oryntra_agent.agent.supervisor import run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest, ChatwootRuntimeResponse
from oryntra_agent.auth import verify_internal_token

router = APIRouter(
    prefix="/internal/chatwoot",
    tags=["chatwoot-runtime"],
    dependencies=[Depends(verify_internal_token)],
)


@router.post("/messages", response_model=ChatwootRuntimeResponse)
async def handle_chatwoot_messages(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
    result = await preprocess_media(payload)

    if result.short_circuit_response is not None:
        return result.short_circuit_response

    response = await asyncio.to_thread(run_chatwoot_runtime, result.payload)

    if result.fallback_prefix:
        response = _prepend_prefix(response, result.fallback_prefix)

    return response


def _prepend_prefix(response: ChatwootRuntimeResponse, prefix: str) -> ChatwootRuntimeResponse:
    payload = response.response
    if payload.type not in {"text", "clarify"}:
        return response
    existing = (payload.content or "").strip()
    new_content = f"{prefix}\n\n{existing}".strip() if existing else prefix
    return response.model_copy(update={
        "response": payload.model_copy(update={"content": new_content}),
    })
```

- [ ] **Step 2: Linters**

```bash
docker compose exec agent-python ruff check src/oryntra_agent/api/chatwoot_messages.py
docker compose exec agent-python mypy src/oryntra_agent/api/chatwoot_messages.py
```

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "feat: async endpoint with media preprocessing, short-circuit, prefix"
```

---

### Task 5: Laravel — payload shape + config

**Files:**
- Modify: `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php`
- Modify: `laravel/config/services.php`

- [ ] **Step 1: Add `chatwoot.internal_base_url` to config**

In `laravel/config/services.php`:

```php
'chatwoot' => [
    'internal_base_url' => env('CHATWOOT_PLATFORM_BASE_URL'),
],
```

- [ ] **Step 2: Eager loads in `payload()`**

```php
$run->loadMissing([
    'agent',
    'agent.supervisorLlmKey',
    'agent.audioLlmKey',
    'agent.visionLlmKey',
    'agent.specialists' => ...,
    'contact',
]);
```

- [ ] **Step 3: Replace `media_config` line; add 2 keys**

```php
'media_policy' => $this->mediaPolicyPayload($agent),
'audio_llm_key' => $this->mediaCredentialFromKey($agent->audioLlmKey, $run->workspace_id, $agent->audio_llm_model),
'vision_llm_key' => $this->mediaCredentialFromKey($agent->visionLlmKey, $run->workspace_id, $agent->vision_llm_model),
```

(Drop the old `'media_config' => $this->objectInput(...)` line entirely. The Python schema field is now `media_policy`.)

- [ ] **Step 4: Add helpers**

```php
/**
 * @return array{
 *   audio:    array{enabled:bool, fallback_message:string},
 *   image:    array{enabled:bool, fallback_message:string},
 *   document: array{enabled:bool, fallback_message:string},
 *   video:    array{enabled:bool, fallback_message:string},
 * }
 */
private function mediaPolicyPayload(Agent $agent): array
{
    $raw = is_array($agent->media_policy) ? $agent->media_policy : [];

    return [
        'audio'    => $this->mediaTypeSlice($raw, 'audio'),
        'image'    => $this->mediaTypeSlice($raw, 'image'),
        // document/video are always-disabled this phase, regardless of stored value
        'document' => ['enabled' => false, 'fallback_message' => $this->fallbackSlice($raw, 'document')],
        'video'    => ['enabled' => false, 'fallback_message' => $this->fallbackSlice($raw, 'video')],
    ];
}

/**
 * @param  array<string,mixed> $raw
 * @return array{enabled:bool, fallback_message:string}
 */
private function mediaTypeSlice(array $raw, string $key): array
{
    $slice = is_array($raw[$key] ?? null) ? $raw[$key] : [];
    return [
        'enabled' => (bool) ($slice['enabled'] ?? false),
        'fallback_message' => (string) ($slice['fallback_message'] ?? ''),
    ];
}

/** @param  array<string,mixed> $raw */
private function fallbackSlice(array $raw, string $key): string
{
    $slice = is_array($raw[$key] ?? null) ? $raw[$key] : [];
    return (string) ($slice['fallback_message'] ?? '');
}

/**
 * @return array{provider:string, model:string, api_key:string}|null
 */
private function mediaCredentialFromKey(?AgentLlmKey $llmKey, int $workspaceId, ?string $model): ?array
{
    if (
        ! $llmKey instanceof AgentLlmKey
        || $llmKey->workspace_id !== $workspaceId
        || $llmKey->status !== AgentLlmKeyStatus::Active
        || $model === null
        || trim($model) === ''
    ) {
        return null;
    }

    $provider = $llmKey->getAttribute('provider');
    if ($provider instanceof AgentLlmProvider) {
        $provider = $provider->value;
    }
    if (! is_string($provider) || $provider === '') {
        return null;
    }

    return [
        'provider' => $provider,
        'model' => $model,
        'api_key' => (string) $llmKey->api_key,
    ];
}
```

- [ ] **Step 5: Inject `chatwoot_internal_base_url` into `runtimeConfig()`**

```php
return [
    ...$agentConfig,
    ...$inputConfig,
    'agent_run_id' => $run->id,
    'conversation_id' => $run->conversation_id,
    'workspace_timezone' => $this->stringOrDefault($run->workspace?->timezone, 'UTC'),
    'chatwoot_internal_base_url' => (string) config('services.chatwoot.internal_base_url', ''),
];
```

- [ ] **Step 6: Pint**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: media_policy, audio/vision llm keys, internal base url in payload"
```

---

### Task 6: Filament — aba Mídia com 4 seções

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/Schemas/AgentForm.php`

- [ ] **Step 1: Inspect existing form**

Read `AgentForm.php` to identify the Tabs/Sections pattern in use and the workspace-scoping convention for relationship queries. Match it.

- [ ] **Step 2: Add "Mídia" tab**

Skeleton (adapt to the actual workspace scope helper used elsewhere):

```php
Tabs\Tab::make('Mídia')
    ->icon('heroicon-o-musical-note')
    ->schema([
        Section::make('Áudio')
            ->description('Transcreva áudios do cliente (Whisper / Gemini) ou envie fallback.')
            ->schema([
                Toggle::make('media_policy.audio.enabled')
                    ->label('Transcrever áudio')
                    ->live()
                    ->default(false),
                Select::make('audio_llm_key_id')
                    ->label('Chave LLM (áudio)')
                    ->relationship(
                        name: 'audioLlmKey',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->whereIn('provider', ['openai', 'gemini'])
                            ->where('status', 'active'),
                    )
                    ->searchable()->preload()->nullable()
                    ->visible(fn ($get) => (bool) $get('media_policy.audio.enabled')),
                TextInput::make('audio_llm_model')
                    ->label('Modelo')
                    ->placeholder('whisper-1, gemini-2.0-flash')
                    ->visible(fn ($get) => (bool) $get('media_policy.audio.enabled')),
                Textarea::make('media_policy.audio.fallback_message')
                    ->label('Mensagem de fallback')
                    ->rows(2)
                    ->default('Desculpe, não consigo processar áudios no momento. Pode enviar em texto?'),
            ]),
        Section::make('Imagem')
            ->description('Descreva imagens (GPT-4o / Claude / Gemini) ou envie fallback.')
            ->schema([
                Toggle::make('media_policy.image.enabled')
                    ->label('Descrever imagem')
                    ->live()
                    ->default(false),
                Select::make('vision_llm_key_id')
                    ->label('Chave LLM (visão)')
                    ->relationship(
                        name: 'visionLlmKey',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->whereIn('provider', ['openai', 'anthropic', 'gemini'])
                            ->where('status', 'active'),
                    )
                    ->searchable()->preload()->nullable()
                    ->visible(fn ($get) => (bool) $get('media_policy.image.enabled')),
                TextInput::make('vision_llm_model')
                    ->label('Modelo')
                    ->placeholder('gpt-4o, claude-sonnet-4-20250514, gemini-2.0-flash')
                    ->visible(fn ($get) => (bool) $get('media_policy.image.enabled')),
                Textarea::make('media_policy.image.fallback_message')
                    ->label('Mensagem de fallback')
                    ->rows(2)
                    ->default('Desculpe, não consigo processar imagens no momento. Pode descrever em texto?'),
            ]),
        Section::make('Documento')
            ->description('Processamento ainda não disponível. Configure a mensagem que o bot envia quando o cliente mandar um documento.')
            ->schema([
                Toggle::make('media_policy.document.enabled')
                    ->label('Processar documento (em breve)')
                    ->disabled()
                    ->default(false),
                Textarea::make('media_policy.document.fallback_message')
                    ->label('Mensagem de fallback')
                    ->rows(2)
                    ->default('Desculpe, ainda não processo documentos. Pode resumir o conteúdo em texto?'),
            ]),
        Section::make('Vídeo')
            ->description('Processamento ainda não disponível. Configure a mensagem fallback.')
            ->schema([
                Toggle::make('media_policy.video.enabled')
                    ->label('Processar vídeo (em breve)')
                    ->disabled()
                    ->default(false),
                Textarea::make('media_policy.video.fallback_message')
                    ->label('Mensagem de fallback')
                    ->rows(2)
                    ->default('Desculpe, ainda não processo vídeos. Pode descrever em texto?'),
            ]),
    ]),
```

- [ ] **Step 3: Pint**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: media tab with audio/image/document/video sections and fallbacks"
```

---

### Task 7: Python tests

**Files:**
- Create: `agent-python/tests/test_media_url_rewrite.py`
- Create: `agent-python/tests/test_media_classification.py`
- Create: `agent-python/tests/test_media_preprocess_paths.py`

- [ ] **Step 1: `test_media_url_rewrite.py`**

```python
from oryntra_agent.agent.media import rewrite_localhost_url


def test_rewrites_with_port():
    assert rewrite_localhost_url(
        "http://localhost:3000/foo/bar",
        "http://host.docker.internal:3000",
    ) == "http://host.docker.internal:3000/foo/bar"


def test_preserves_query():
    assert rewrite_localhost_url(
        "http://localhost:3000/foo?a=1&b=2",
        "http://internal:3000",
    ).endswith("/foo?a=1&b=2")


def test_no_rewrite_for_real_host():
    url = "https://chatwoot.example.com/x"
    assert rewrite_localhost_url(url, "http://internal:3000") == url


def test_no_rewrite_when_base_missing():
    url = "http://localhost:3000/x"
    assert rewrite_localhost_url(url, None) == url
    assert rewrite_localhost_url(url, "") == url
```

- [ ] **Step 2: `test_media_classification.py`**

```python
from oryntra_agent.agent.media import classify_attachment
from oryntra_agent.api.schemas import MediaAttachment


def test_classify_audio_opus():
    assert classify_attachment(MediaAttachment(content_type="audio/opus", file_type="audio")) == "audio"


def test_classify_image_jpeg():
    assert classify_attachment(MediaAttachment(content_type="image/jpeg", file_type="image")) == "image"


def test_classify_video():
    assert classify_attachment(MediaAttachment(content_type="video/mp4", file_type="video")) == "video"


def test_classify_pdf_document():
    assert classify_attachment(MediaAttachment(content_type="application/pdf", file_type="file")) == "document"


def test_classify_unknown():
    assert classify_attachment(MediaAttachment(content_type="something/weird", file_type=None)) == "other"


def test_classify_falls_back_to_file_type():
    assert classify_attachment(MediaAttachment(content_type=None, file_type="audio")) == "audio"
```

- [ ] **Step 3: `test_media_preprocess_paths.py`**

```python
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


def _make_request(**overrides) -> ChatwootRuntimeRequest:
    base = {
        "workspace_id": 1,
        "agent_id": 1,
        "agent_mode": "single",
        "thread_id": "t",
        "messages": [],
        "media_policy": MediaPolicy(),
    }
    base.update(overrides)
    return ChatwootRuntimeRequest(**base)


@pytest.mark.asyncio
async def test_short_circuits_when_only_audio_attachment_and_disabled():
    msg = ChatwootMessage(
        content=None,
        attachments=[MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://x")],
    )
    policy = MediaPolicy(
        audio=MediaTypePolicy(enabled=False, fallback_message="manda em texto por favor"),
    )
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is not None
    assert result.short_circuit_response.response.content == "manda em texto por favor"


@pytest.mark.asyncio
async def test_prepends_fallback_when_message_has_text_and_disabled_attachment():
    msg = ChatwootMessage(
        content="oi tenho uma duvida",
        attachments=[MediaAttachment(file_type="audio", content_type="audio/opus", data_url="http://x")],
    )
    policy = MediaPolicy(audio=MediaTypePolicy(enabled=False, fallback_message="sem audio aqui"))
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is None
    assert result.fallback_prefix == "sem audio aqui"
    # original text content preserved (no extracted to append)
    assert result.payload.messages[0].content == "oi tenho uma duvida"


@pytest.mark.asyncio
async def test_uses_transcribed_text_cache():
    msg = ChatwootMessage(
        content=None,
        attachments=[MediaAttachment(
            file_type="audio", content_type="audio/opus",
            data_url="http://x", transcribed_text="ola tudo bem",
        )],
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
async def test_document_always_falls_back_even_if_enabled_in_storage():
    """document.enabled is ignored — always fallback this phase."""
    msg = ChatwootMessage(
        content=None,
        attachments=[MediaAttachment(file_type="file", content_type="application/pdf", data_url="http://x")],
    )
    policy = MediaPolicy(document=MediaTypePolicy(enabled=True, fallback_message="docs em breve"))
    payload = _make_request(messages=[msg], media_policy=policy)

    result = await preprocess_media(payload)

    assert result.short_circuit_response is not None
    assert "docs em breve" in result.short_circuit_response.response.content
```

- [ ] **Step 4: Run**

```bash
docker compose exec agent-python pytest tests/test_media_url_rewrite.py tests/test_media_classification.py tests/test_media_preprocess_paths.py -v
docker compose exec agent-python ruff check src/ tests/
docker compose exec agent-python mypy src/
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "test: media url rewrite, classification, and preprocess paths"
```

---

### Task 8: Laravel Pest tests

**Files:**
- Create: `laravel/tests/Feature/Agent/MediaPolicyPayloadTest.php`
- Create: `laravel/tests/Feature/Agent/MediaPipelineTest.php`

- [ ] **Step 1: Policy payload test**

```php
<?php

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('media_policy payload contains the four buckets with stored fallbacks', function () {
    $workspace = Workspace::factory()->create();
    $audioKey = AgentLlmKey::factory()->create([
        'workspace_id' => $workspace->id,
        'provider' => AgentLlmProvider::OpenAI->value,
        'status' => AgentLlmKeyStatus::Active->value,
    ]);
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'mode' => 'single',
        'media_policy' => [
            'audio'    => ['enabled' => true,  'fallback_message' => 'sem audio'],
            'image'    => ['enabled' => false, 'fallback_message' => 'sem imagem'],
            'document' => ['enabled' => true,  'fallback_message' => 'sem doc'],   // forced false in payload
            'video'    => ['enabled' => true,  'fallback_message' => 'sem video'], // forced false in payload
        ],
        'audio_llm_key_id' => $audioKey->id,
        'audio_llm_model' => 'whisper-1',
    ]);
    AgentSpecialist::factory()->create(['workspace_id' => $workspace->id, 'agent_id' => $agent->id, 'status' => 'active']);
    $connection = ChatwootConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'conversation_id' => 1,
        'status' => 'running',
        'input' => ['messages' => []],
    ]);

    $client = new AgentRuntimeClient;
    $m = new ReflectionMethod($client, 'payload');
    $m->setAccessible(true);
    $payload = $m->invoke($client, $run);

    expect($payload['media_policy']['audio'])->toBe(['enabled' => true,  'fallback_message' => 'sem audio']);
    expect($payload['media_policy']['image'])->toBe(['enabled' => false, 'fallback_message' => 'sem imagem']);
    expect($payload['media_policy']['document'])->toBe(['enabled' => false, 'fallback_message' => 'sem doc']);
    expect($payload['media_policy']['video'])->toBe(['enabled' => false, 'fallback_message' => 'sem video']);
    expect($payload['audio_llm_key']['provider'])->toBe('openai');
    expect($payload['audio_llm_key']['model'])->toBe('whisper-1');
});
```

- [ ] **Step 2: Pipeline test**

```php
<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('attachments preserved and internal base url is in runtime_config', function () {
    config()->set('services.chatwoot.internal_base_url', 'http://host.docker.internal:3000');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id, 'mode' => 'single']);
    AgentSpecialist::factory()->create(['workspace_id' => $workspace->id, 'agent_id' => $agent->id, 'status' => 'active']);
    $connection = ChatwootConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'conversation_id' => 37,
        'status' => 'running',
        'input' => [
            'messages' => [[
                'content' => null,
                'content_type' => 'text',
                'attachments' => [[
                    'id' => 122,
                    'file_type' => 'audio',
                    'content_type' => 'audio/opus',
                    'data_url' => 'http://localhost:3000/rails/active_storage/blobs/redirect/tok/x.oga',
                ]],
            ]],
        ],
    ]);

    $client = new AgentRuntimeClient;
    $m = new ReflectionMethod($client, 'payload');
    $m->setAccessible(true);
    $payload = $m->invoke($client, $run);

    expect($payload['messages'][0]['attachments'][0]['content_type'])->toBe('audio/opus');
    expect($payload['runtime_config']['chatwoot_internal_base_url'])->toBe('http://host.docker.internal:3000');
});
```

- [ ] **Step 3: Run**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app ./vendor/bin/pest tests/Feature/Agent/MediaPolicyPayloadTest.php tests/Feature/Agent/MediaPipelineTest.php --compact
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "test: pest for media_policy payload and pipeline plumbing"
```

---

### Task 9: Smoke test against real Chatwoot

- [ ] **Step 1**: send a voice note via WhatsApp.
- [ ] **Step 2**: check the run input:

```bash
docker compose exec laravel-app php artisan tinker --execute '$r = \App\Models\AgentRun::query()->where("conversation_id", 37)->latest("id")->first(); echo json_encode($r->input["messages"], JSON_PRETTY_PRINT);'
```

- [ ] **Step 3**: check the run output — expect substantive response (transcript-informed) instead of generic greeting.
- [ ] **Step 4**: with audio.enabled = false, repeat — expect the configured fallback as response.
- [ ] **Step 5**: send a PDF document — expect document fallback.

---

### Task 10: Final verification + ROADMAP

- [ ] **Step 1**: full verification

```bash
docker compose exec laravel-app ./vendor/bin/pest --compact
docker compose exec laravel-app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec laravel-app ./vendor/bin/pint --format agent
docker compose exec agent-python pytest tests/ -v
docker compose exec agent-python ruff check src/ tests/
docker compose exec agent-python mypy src/
```

- [ ] **Step 2**: ROADMAP entry

```markdown
| 14.1 | `2026-05-24-vision-audio-phase-14-1.md` | Preprocessamento automático de mídia + fallbacks customer-facing. Migration `audio_llm_key_id`, `audio_llm_model`, `vision_llm_key_id`, `vision_llm_model`. `media_policy` jsonb reusado com 4 buckets (audio/image/document/video). Bypass LLM (short-circuit) quando só attachment desabilitado. Prefix fallback à resposta LLM quando misto. URL rewrite localhost→internal. Endpoint async + `to_thread` no supervisor sync. Filament: aba Mídia com 4 seções (document/video locked com fallback editável). |
```

- [ ] **Step 3**: commit

```bash
git add docs/tasks/ROADMAP.md && git commit -m "docs: mark phase 14.1 delivered"
```

---

## Done When

- [ ] WhatsApp voice note (audio/opus) with `audio.enabled=true` and Whisper key configured → transcript replaces empty content → supervisor routes to specialist normally
- [ ] WhatsApp voice note with `audio.enabled=false` → bot replies the configured `audio.fallback_message` (no LLM call, trace logs `media.short_circuit_fallback`)
- [ ] WhatsApp image with `image.enabled=true` and vision key configured → description injected; routes normally
- [ ] WhatsApp PDF → bot replies `document.fallback_message` (document never processed this phase)
- [ ] WhatsApp video → bot replies `video.fallback_message`
- [ ] Mixed message (text + audio attachment, audio disabled) → bot replies `<audio fallback>\n\n<LLM response to text>`
- [ ] Chatwoot `transcribed_text` cache used when present (no Whisper call)
- [ ] Migration applied; Filament tab functional; both supervisor and endpoint remain stable
- [ ] All tests passing; PHPStan / Pint / ruff / mypy clean
- [ ] ROADMAP updated
