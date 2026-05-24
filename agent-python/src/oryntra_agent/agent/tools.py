from typing import Any, Literal

import httpx
from pydantic import BaseModel, ConfigDict, model_validator

from oryntra_agent.settings import settings


class HumanHandoffRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    thread_id: str
    conversation_id: int
    specialist_id: int | None = None
    reason: str
    priority: Literal["low", "normal", "high", "urgent"] = "normal"
    suggested_team: str | None = None
    customer_message: str | None = None
    conversation_summary: str | None = None
    key_fact: str | None = None


class HumanHandoffResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["handoff_dispatched"]
    handoff_id: int
    message: str


class TeamHandoffRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    thread_id: str
    conversation_id: int
    specialist_id: int | None = None
    reason: str
    priority: Literal["low", "normal", "high", "urgent"] = "normal"
    customer_message: str | None = None


class TeamHandoffResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["handoff_dispatched"]
    handoff_id: int
    message: str


class GetContactRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None = None
    contact_id: int


class ContactResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["ok"]
    contact: dict[str, Any]


class UpdateContactRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None = None
    contact_id: int
    name: str | None = None
    email: str | None = None
    phone_number: str | None = None

    @model_validator(mode="after")
    def at_least_one_field(self) -> "UpdateContactRequest":
        if not any([self.name, self.email, self.phone_number]):
            raise ValueError("Provide at least one of: name, email, phone_number.")
        return self


class UpdateContactMemoryRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None = None
    contact_id: int
    type: Literal["preference", "fact", "constraint", "history", "custom"]
    content: str
    confidence: float | None = None
    expires_at: str | None = None


class UpdateContactMemoryResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["ok"]
    memory_id: int


class ResolveConversationRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    thread_id: str
    conversation_id: int
    specialist_id: int | None = None
    reason: str
    resolution_summary: str
    customer_message: str | None = None
    label_name: str | None = None


class ResolveConversationResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["resolution_dispatched"]
    resolution_id: int
    message: str


class QueryProductsRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None = None
    query: str | None = None
    category: str | None = None
    min_price: float | None = None
    max_price: float | None = None
    limit: int = 20


class QueryProductsResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    products: list[dict[str, Any]]
    total: int


def _post(path: str, payload: BaseModel) -> dict[str, Any]:
    base_url = settings.laravel_internal_base_url.rstrip("/")

    with httpx.Client(timeout=10) as client:
        response = client.post(
            f"{base_url}{path}",
            headers={
                "Accept": "application/json",
                "X-Internal-Token": settings.agent_runtime_internal_token,
            },
            json=payload.model_dump(mode="json"),
        )

    response.raise_for_status()
    data = response.json()
    if not isinstance(data, dict):
        raise RuntimeError("Laravel internal API returned a non-object payload.")
    return data


def request_human_handoff(payload: HumanHandoffRequest) -> HumanHandoffResponse:
    return HumanHandoffResponse.model_validate(
        _post("/api/internal/agent-tools/request-human-handoff", payload)
    )


def request_team_handoff(payload: TeamHandoffRequest) -> TeamHandoffResponse:
    return TeamHandoffResponse.model_validate(
        _post("/api/internal/agent-tools/request-team-handoff", payload)
    )


def chatwoot_get_contact(payload: GetContactRequest) -> ContactResponse:
    return ContactResponse.model_validate(
        _post("/api/internal/agent-tools/chatwoot-get-contact", payload)
    )


def chatwoot_update_contact(payload: UpdateContactRequest) -> ContactResponse:
    return ContactResponse.model_validate(
        _post("/api/internal/agent-tools/chatwoot-update-contact", payload)
    )


def update_contact_memory(payload: UpdateContactMemoryRequest) -> UpdateContactMemoryResponse:
    return UpdateContactMemoryResponse.model_validate(
        _post("/api/internal/agent-tools/update-contact-memory", payload)
    )


def resolve_conversation(payload: ResolveConversationRequest) -> ResolveConversationResponse:
    return ResolveConversationResponse.model_validate(
        _post("/api/internal/agent-tools/resolve-conversation", payload)
    )


def query_products(payload: QueryProductsRequest) -> QueryProductsResponse:
    return QueryProductsResponse.model_validate(
        _post("/api/internal/agent-tools/query-products", payload)
    )
