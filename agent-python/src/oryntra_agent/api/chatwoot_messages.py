import asyncio

from fastapi import APIRouter, Depends

from oryntra_agent.agent.media import preprocess_media
from oryntra_agent.agent.supervisor import AccumulatedUsage, LlmUsage, _accumulated_usage, run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest, ChatwootRuntimeResponse, TraceStep
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
        _inject_media_usage(result.media_usage)
        response = result.short_circuit_response
        if result.trace_steps:
            existing = list(response.trace)
            for step_data in result.trace_steps:
                existing.append(TraceStep(**step_data))
            response = response.model_copy(update={"trace": existing})
        return response

    response = await asyncio.to_thread(run_chatwoot_runtime, result.payload)

    _inject_media_usage(result.media_usage)

    if result.fallback_prefix:
        response = _prepend_prefix(response, result.fallback_prefix)

    if result.trace_steps:
        existing = list(response.trace)
        for step_data in result.trace_steps:
            existing.append(TraceStep(**step_data))
        response = response.model_copy(update={"trace": existing})

    response = _merge_media_usage(response, result.media_usage)

    return response


def _inject_media_usage(media_usage: list | None) -> None:
    if not media_usage:
        return
    try:
        acc = _accumulated_usage.get()
    except LookupError:
        return
    for m in media_usage:
        acc.add_media(LlmUsage(
            input_tokens=m.input_tokens,
            output_tokens=m.output_tokens,
            latency_ms=m.latency_ms,
        ))


def _merge_media_usage(
    response: ChatwootRuntimeResponse,
    media_usage: list | None,
) -> ChatwootRuntimeResponse:
    if not media_usage:
        return response
    total_in = sum(m.input_tokens for m in media_usage)
    total_out = sum(m.output_tokens for m in media_usage)
    existing = response.usage
    return response.model_copy(update={
        "usage": existing.model_copy(update={
            "media": existing.media.model_copy(update={
                "input_tokens": existing.media.input_tokens + total_in,
                "output_tokens": existing.media.output_tokens + total_out,
            }),
        }),
    })


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
