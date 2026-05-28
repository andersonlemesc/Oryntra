from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, SecretStr


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
    """Transport-only credential. Supervisor keeps its own internal LlmCredential."""

    model_config = ConfigDict(extra="forbid")

    provider: Literal["openai", "anthropic", "gemini", "local"]
    model: str
    api_key: SecretStr = Field(exclude=True)


class LlmCredential(BaseModel):
    """Internal credential used by supervisor.py (plain string api_key)."""

    provider: Literal["openai", "anthropic", "gemini", "local"]
    model: str
    api_key: str


class RuntimeLlmCredentials(BaseModel):
    supervisor: LlmCredential | None = None
    specialists: dict[int, LlmCredential] = Field(default_factory=dict)


class ChatwootMessage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str | None = None
    chatwoot_message_id: str | None = None
    webhook_event_id: int | None = None
    content: str | None = None
    created_at: datetime | None = None
    received_at: datetime | None = None
    message_type: str | None = None
    content_type: str | None = None
    attachments: list[MediaAttachment] = Field(default_factory=list)


class SupervisorConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    prompt: str | None = None
    llm_key_id: int | None = None
    llm_provider: Literal["openai", "anthropic", "gemini", "local"] | None = None
    llm_model: str | None = None
    llm_api_key: SecretStr | None = Field(default=None, exclude=True)


class HandoffRuleConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    enabled: bool = True
    keywords: list[str] = Field(default_factory=list)
    priority: Literal["low", "normal", "high", "urgent"] = "normal"
    reason: str
    customer_message: str | None = None


class HandoffConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    enabled: bool = False
    team_enabled: bool = False
    summary_llm_enabled: bool = False
    default_priority: Literal["low", "normal", "high", "urgent"] = "normal"
    customer_message: str | None = "Vou transferir voce para um atendente."
    private_note: str | None = None
    assign_strategy: Literal["none", "team", "agent", "team_then_agent"] = "none"
    team_id: int | None = None
    agent_id: int | None = None
    label_name: str | None = None
    private_note_template: str | None = None
    rules: list[HandoffRuleConfig] = Field(default_factory=list)


class ResolutionRuleConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    enabled: bool = True
    keywords: list[str] = Field(default_factory=list)
    reason: str
    customer_message: str | None = None
    label_name: str | None = None


class ResolutionConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    enabled: bool = False
    customer_message: str | None = None
    label_name: str | None = None
    rules: list[ResolutionRuleConfig] = Field(default_factory=list)


class MemoryConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    extraction_enabled: bool = False
    injection_enabled: bool = False
    injection_limit: int | None = None
    extraction_types: list[str] = Field(default_factory=list)
    max_tool_iterations: int = Field(default=4, ge=1, le=20)


class ContactMemorySnapshot(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["preference", "fact", "constraint", "history", "custom"]
    content: str
    source: Literal["agent_extracted", "manual", "chatwoot_attribute", "tool"]
    confidence: float | None = None
    created_at: str | None = None
    conversation_id: int | None = None


class ExternalToolConfig(BaseModel):
    """Admin-defined HTTP connector exposed to the specialist as a dynamic tool.

    Carries only what Python needs to build the tool schema — never base_url,
    auth or secrets, which stay in Laravel.
    """

    model_config = ConfigDict(extra="forbid")

    slug: str
    description: str | None = None
    param_schema: dict[str, Any] = Field(default_factory=dict)


class SpecialistConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: int
    name: str
    description: str | None = None
    role_prompt: str
    llm_key_id: int | None = None
    llm_provider: Literal["openai", "anthropic", "gemini", "local"] | None = None
    llm_model: str | None = None
    llm_api_key: SecretStr | None = Field(default=None, exclude=True)
    llm_temperature: float = Field(ge=0, le=2)
    tools: list[str] = Field(default_factory=list)
    external_tools: list[ExternalToolConfig] = Field(default_factory=list)
    handoff_config: HandoffConfig = Field(default_factory=HandoffConfig)
    memory_config: MemoryConfig = Field(default_factory=MemoryConfig)
    resolution_config: ResolutionConfig = Field(default_factory=ResolutionConfig)
    intent_keywords: list[str] = Field(default_factory=list)
    confidence_threshold: float = Field(ge=0, le=1)
    fallback_specialist_id: int | None = None


class ChatwootRuntimeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_mode: Literal["single", "supervisor"] = "single"
    fallback_specialist_id: int | None = None
    thread_id: str
    supervisor: SupervisorConfig = Field(default_factory=SupervisorConfig)
    specialists: list[SpecialistConfig] = Field(default_factory=list)
    messages: list[ChatwootMessage] = Field(default_factory=list)
    contact: dict[str, Any] = Field(default_factory=dict)
    inbox: dict[str, Any] = Field(default_factory=dict)
    guard_config: dict[str, Any] = Field(default_factory=dict)
    media_policy: MediaPolicy = Field(default_factory=MediaPolicy)
    audio_llm_key: LlmCredentialPayload | None = Field(default=None, exclude=True)
    vision_llm_key: LlmCredentialPayload | None = Field(default=None, exclude=True)
    runtime_config: dict[str, Any] = Field(default_factory=dict)


class RuntimeResponsePayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["text", "send_document", "escalate", "clarify", "multi"]
    content: str | None = None
    document_ids: list[int] | None = None
    document_type: Literal["product", "standalone"] | None = None
    handoff_reason: str | None = None
    confidence: float = Field(ge=0, le=1)


class TraceTokens(BaseModel):
    model_config = ConfigDict(extra="forbid")

    input: int = 0
    output: int = 0


class TraceStep(BaseModel):
    model_config = ConfigDict(extra="forbid")

    step: int
    type: str
    specialist_id: int | None = None
    tool: str | None = None
    input: dict[str, Any] = Field(default_factory=dict)
    output: dict[str, Any] = Field(default_factory=dict)
    tokens: TraceTokens = Field(default_factory=TraceTokens)
    latency_ms: int = 0
    ts: datetime


class UsageBucket(BaseModel):
    model_config = ConfigDict(extra="forbid")

    input_tokens: int = 0
    output_tokens: int = 0


class RuntimeUsage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    supervisor: UsageBucket = Field(default_factory=UsageBucket)
    specialist: UsageBucket = Field(default_factory=UsageBucket)
    media: UsageBucket = Field(default_factory=UsageBucket)
    total_cost_cents: int = 0


class ChatwootRuntimeResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["completed", "waiting_human", "failed"]
    response: RuntimeResponsePayload
    specialist_id: int | None = None
    trace: list[TraceStep] = Field(default_factory=list)
    usage: RuntimeUsage = Field(default_factory=RuntimeUsage)


class SendDocumentArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    document_ids: list[int] = Field(
        description=(
            "IDs dos documentos a enviar numa unica mensagem (PDF, imagem, etc). "
            "Passe varios IDs para enviar um catalogo/galeria de uma vez. "
            "Todos devem ser da mesma origem (mesmo document_type)."
        ),
        min_length=1,
    )
    document_type: Literal["product", "standalone"] = Field(
        description=(
            "Origem dos documentos: 'product' para IDs vindos de query_products "
            "(anexados a um produto), 'standalone' para IDs da biblioteca geral "
            "vindos de query_documents."
        ),
    )
    caption: str = Field(default="", description="Texto descritivo que acompanha os documentos.")
