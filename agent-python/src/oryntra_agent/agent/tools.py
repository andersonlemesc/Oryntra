from typing import Literal

import httpx
from pydantic import BaseModel, ConfigDict

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


class HumanHandoffResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["waiting_human"]
    handoff_id: int
    message: str


def request_human_handoff(payload: HumanHandoffRequest) -> HumanHandoffResponse:
    base_url = settings.laravel_internal_base_url.rstrip("/")

    with httpx.Client(timeout=10) as client:
        response = client.post(
            f"{base_url}/api/internal/agent-tools/request-human-handoff",
            headers={"X-Internal-Token": settings.agent_runtime_internal_token},
            json=payload.model_dump(mode="json"),
        )

    response.raise_for_status()

    return HumanHandoffResponse.model_validate(response.json())
