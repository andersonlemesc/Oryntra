from datetime import datetime
from typing import Any, Literal

from fastapi import APIRouter, Depends
from pydantic import BaseModel, ConfigDict, Field, SecretStr

from oryntra_agent.agent.supervisor import chat_model_for_credential
from oryntra_agent.auth import verify_internal_token

router = APIRouter(
    prefix="/internal/memory",
    tags=["memory-extraction"],
    dependencies=[Depends(verify_internal_token)],
)


MemoryType = Literal["preference", "fact", "constraint", "history", "custom"]


class TranscriptMessage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    role: Literal["user", "assistant"]
    content: str
    created_at: datetime | None = None


class ExistingMemory(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: MemoryType
    content: str


class LlmCredentialInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    provider: Literal["openai", "anthropic", "gemini", "local"]
    model: str
    api_key: SecretStr = Field(exclude=True)


class MemoryExtractionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    specialist_id: int | None = None
    contact_id: int
    transcript: list[TranscriptMessage] = Field(default_factory=list)
    existing_memories: list[ExistingMemory] = Field(default_factory=list)
    allowed_types: list[MemoryType] = Field(default_factory=list)
    credential: LlmCredentialInput


class ExtractedMemory(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: MemoryType
    content: str
    confidence: float = Field(ge=0.0, le=1.0, default=0.7)


class ExtractedMemoryList(BaseModel):
    """Wrapper used as structured_output target."""

    memories: list[ExtractedMemory] = Field(default_factory=list)


class MemoryExtractionResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["ok", "skipped"]
    memories: list[ExtractedMemory] = Field(default_factory=list)
    reason: str | None = None


@router.post("/extract", response_model=MemoryExtractionResponse)
async def extract_memories(payload: MemoryExtractionRequest) -> MemoryExtractionResponse:
    if not payload.transcript:
        return MemoryExtractionResponse(status="skipped", reason="empty_transcript")

    if not payload.allowed_types:
        return MemoryExtractionResponse(status="skipped", reason="no_allowed_types")

    from langchain_core.messages import HumanMessage, SystemMessage

    from oryntra_agent.agent.supervisor import LlmCredential

    credential = LlmCredential(
        provider=payload.credential.provider,
        model=payload.credential.model,
        api_key=payload.credential.api_key.get_secret_value(),
    )

    chat_model = chat_model_for_credential(credential, temperature=0.2)

    if chat_model is None:
        return MemoryExtractionResponse(status="skipped", reason="unsupported_provider")

    transcript_lines = [
        f"{message.role}: {message.content}" for message in payload.transcript
    ]
    existing_lines = [
        f"- [{memory.type}] {memory.content}" for memory in payload.existing_memories
    ]
    allowed = ", ".join(payload.allowed_types)

    system_prompt = (
        "Voce analisa transcricoes de atendimento e extrai fatos longo prazo "
        "sobre o cliente que vao ajudar a IA em conversas futuras.\n"
        f"Tipos permitidos: {allowed}.\n"
        "Regras:\n"
        "- Extraia apenas fatos novos, NAO duplique memorias existentes.\n"
        "- Cada item deve ser uma frase curta, objetiva, em portugues.\n"
        "- Use confidence 0-1 conforme certeza.\n"
        "- Se nao houver fatos novos relevantes, retorne lista vazia.\n"
        "- Nao invente. Apenas o que esta explicito na transcricao."
    )

    human_prompt_parts = ["Transcricao:", *transcript_lines]
    if existing_lines:
        human_prompt_parts.extend(["", "Memorias ja registradas:", *existing_lines])

    try:
        structured = chat_model.with_structured_output(ExtractedMemoryList)
        result = structured.invoke(
            [
                SystemMessage(content=system_prompt),
                HumanMessage(content="\n".join(human_prompt_parts)),
            ]
        )
    except Exception:
        return MemoryExtractionResponse(status="skipped", reason="llm_error")

    if not isinstance(result, ExtractedMemoryList):
        result = ExtractedMemoryList.model_validate(result)

    filtered: list[ExtractedMemory] = []
    existing_set = {
        (memory.type, _normalize(memory.content))
        for memory in payload.existing_memories
    }

    for memory in result.memories:
        if memory.type not in payload.allowed_types:
            continue
        key = (memory.type, _normalize(memory.content))
        if key in existing_set:
            continue
        existing_set.add(key)
        filtered.append(memory)

    return MemoryExtractionResponse(status="ok", memories=filtered)


def _normalize(value: str) -> str:
    return " ".join(value.lower().split())


__all__ = [
    "router",
    "MemoryExtractionRequest",
    "MemoryExtractionResponse",
    "ExtractedMemory",
    "TranscriptMessage",
    "ExistingMemory",
    "LlmCredentialInput",
]


# Silence unused-import warning when verifying typing
_ = Any
