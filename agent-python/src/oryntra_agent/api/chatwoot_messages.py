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
async def handle_chatwoot_messages(
    payload: ChatwootRuntimeRequest,
) -> ChatwootRuntimeResponse:
    result = await preprocess_media(payload)

    if result.short_circuit_response is not None:
        return result.short_circuit_response

    response = await asyncio.to_thread(run_chatwoot_runtime, result.payload)

    if result.fallback_prefix:
        response = _prepend_prefix(response, result.fallback_prefix)

    return response


def _prepend_prefix(
    response: ChatwootRuntimeResponse,
    prefix: str,
) -> ChatwootRuntimeResponse:
    payload = response.response
    if payload.type not in {"text", "clarify"}:
        return response
    existing = (payload.content or "").strip()
    new_content = f"{prefix}\n\n{existing}".strip() if existing else prefix
    return response.model_copy(
        update={"response": payload.model_copy(update={"content": new_content})},
    )
