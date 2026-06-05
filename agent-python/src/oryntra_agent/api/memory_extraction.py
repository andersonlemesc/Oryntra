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

    from oryntra_agent.api.schemas import LlmCredential

    credential = LlmCredential(
        provider=payload.credential.provider,
        model=payload.credential.model,
        api_key=payload.credential.api_key.get_secret_value(),
    )

    chat_model = chat_model_for_credential(credential, temperature=0.2)

    if chat_model is None:
        return MemoryExtractionResponse(status="skipped", reason="unsupported_provider")

    transcript_lines = [f"{message.role}: {message.content}" for message in payload.transcript]
    existing_lines = [f"- [{memory.type}] {memory.content}" for memory in payload.existing_memories]
    allowed = ", ".join(payload.allowed_types)

    system_prompt = (
        "Voce analisa transcricoes de atendimento e extrai memoria de longo prazo "
        "sobre o cliente, util em QUALQUER conversa futura.\n\n"
        "Classifique cada item no tipo CORRETO:\n"
        "- preference (Preferencia): gosto ou habito estavel. "
        "Ex: 'paga em dinheiro e pede troco', 'nao gosta de cebola'.\n"
        "- fact (Fato): atributo objetivo e estavel do cliente. "
        "Ex: 'endereco e Rua Rio Branco 169, bairro Sao Joao', 'nome da empresa e Acme'.\n"
        "- constraint (Restricao): limitacao real e permanente do cliente. "
        "Ex: 'alergico a gluten', 'so recebe entregas apos as 18h'.\n"
        "- history (Historico): evento pontual/transacional daquele momento. "
        "Ex: 'pediu 2 X-Salada', 'comprou 1 refrigerante 2L'.\n"
        "- custom (Personalizado): relevante e duravel, mas nao encaixa acima.\n\n"
        f"Tipos permitidos nesta extracao: {allowed}.\n\n"
        "Regras:\n"
        "- Classifique pelo tipo CORRETO. Se o tipo correto NAO esta nos permitidos, "
        "DESCARTE o item (nao force noutro tipo so para registrar).\n"
        "- NAO registre requisitos do proprio atendimento nem coisas validas so para a "
        "conversa atual. Ex descartar: 'precisa fornecer o endereco para a entrega', "
        "'interessado em cardapios'.\n"
        "- NAO duplique memorias ja registradas, mesmo que ditas com outras palavras.\n"
        "- Cada item: frase curta, objetiva, em portugues. Use confidence 0-1 conforme certeza.\n"
        "- Nao invente. Apenas o que esta explicito na transcricao. "
        "Sem fatos novos relevantes, retorne lista vazia."
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
        (memory.type, _normalize(memory.content)) for memory in payload.existing_memories
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
    "ExistingMemory",
    "ExtractedMemory",
    "LlmCredentialInput",
    "MemoryExtractionRequest",
    "MemoryExtractionResponse",
    "TranscriptMessage",
    "router",
]


# Silence unused-import warning when verifying typing
_ = Any
