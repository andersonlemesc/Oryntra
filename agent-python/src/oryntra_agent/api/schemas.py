from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class ChatwootMessage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str | None = None
    content: str | None = None
    created_at: datetime | None = None
    message_type: str | None = None
    content_type: str | None = None
    attachments: list[dict[str, Any]] = Field(default_factory=list)


class ChatwootRuntimeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_mode: Literal["single", "supervisor"] = "single"
    thread_id: str
    messages: list[ChatwootMessage] = Field(default_factory=list)
    contact: dict[str, Any] = Field(default_factory=dict)
    inbox: dict[str, Any] = Field(default_factory=dict)
    guard_config: dict[str, Any] = Field(default_factory=dict)
    media_config: dict[str, Any] = Field(default_factory=dict)
    runtime_config: dict[str, Any] = Field(default_factory=dict)


class RuntimeResponsePayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["text", "send_document", "escalate", "clarify", "multi"]
    content: str | None = None
    document_id: int | None = None
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
    total_cost_cents: int = 0


class ChatwootRuntimeResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["completed", "waiting_human", "failed"]
    response: RuntimeResponsePayload
    specialist_id: int | None = None
    trace: list[TraceStep] = Field(default_factory=list)
    usage: RuntimeUsage = Field(default_factory=RuntimeUsage)
