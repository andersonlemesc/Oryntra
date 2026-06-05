import asyncio
import logging

from fastapi import APIRouter, Depends, HTTPException, status

from oryntra_agent.agent.media import MediaUsage, preprocess_media
from oryntra_agent.agent.supervisor import (
    _accumulated_usage,
    run_chatwoot_runtime,
)
from oryntra_agent.agent.tool_runtime import LlmUsage
from oryntra_agent.api.schemas import ChatwootRuntimeRequest, ChatwootRuntimeResponse, TraceStep
from oryntra_agent.auth import verify_internal_token
from oryntra_agent.runtime_callback import post_agent_run_result
from oryntra_agent.settings import settings

logger = logging.getLogger(__name__)

router = APIRouter(
    prefix="/internal/chatwoot",
    tags=["chatwoot-runtime"],
    dependencies=[Depends(verify_internal_token)],
)

# Caps concurrent background runs per worker process. With N uvicorn workers the
# effective cap is N * agent_max_concurrency (the semaphore is per-process).
_run_semaphore = asyncio.Semaphore(settings.agent_max_concurrency)

# Hold strong references to in-flight background tasks so they are not garbage
# collected mid-run (asyncio only keeps weak references to scheduled tasks).
_background_tasks: set[asyncio.Task[None]] = set()


@router.post("/messages", response_model=ChatwootRuntimeResponse)
async def handle_chatwoot_messages(
    payload: ChatwootRuntimeRequest,
) -> ChatwootRuntimeResponse:
    """Synchronous run — used by the admin runtime preview which wants the
    response inline. The production Chatwoot flow uses ``/messages/dispatch``."""
    return await _execute_runtime(payload)


@router.post("/messages/dispatch", status_code=status.HTTP_202_ACCEPTED)
async def dispatch_chatwoot_messages(payload: ChatwootRuntimeRequest) -> dict[str, object]:
    """Fire-and-forget entry point for the production Chatwoot flow.

    Accepts the payload, schedules the run in the background and returns 202
    immediately so the Laravel worker is freed. The result is posted back to
    Laravel via the internal callback once the graph finishes.
    """
    if payload.agent_run_id is None:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="agent_run_id is required for dispatch.",
        )

    # Backpressure: reject when at capacity instead of queueing the run inside
    # this process. Laravel then releases the job back onto the `agent` queue,
    # keeping the backlog visible in Redis/Horizon (and out of the run-timeout
    # window). The event loop is single-threaded, so the locked() check and the
    # acquire() below run atomically — no slot can be taken in between.
    if _run_semaphore.locked():
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="runtime at capacity",
        )
    await _run_semaphore.acquire()

    task = asyncio.create_task(_run_and_callback(payload.agent_run_id, payload))
    _background_tasks.add(task)
    task.add_done_callback(_background_tasks.discard)

    return {"accepted": True, "agent_run_id": payload.agent_run_id}


async def _run_and_callback(agent_run_id: int, payload: ChatwootRuntimeRequest) -> None:
    """Execute an accepted run and post the result back. The concurrency slot is
    acquired by the dispatch endpoint (backpressure) and released here."""
    try:
        response = await _execute_runtime(payload)
        body = response.model_dump(mode="json")
    except Exception as exc:  # any failure is reported back to Laravel
        logger.exception("agent run %s failed in runtime", agent_run_id)
        body = {"status": "failed", "error": str(exc)}
    finally:
        _run_semaphore.release()

    await post_agent_run_result(agent_run_id, body)


async def _execute_runtime(payload: ChatwootRuntimeRequest) -> ChatwootRuntimeResponse:
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

    return _merge_media_usage(response, result.media_usage)


def _inject_media_usage(media_usage: list[MediaUsage] | None) -> None:
    if not media_usage:
        return
    try:
        acc = _accumulated_usage.get()
    except LookupError:
        return
    for m in media_usage:
        acc.add_media(
            LlmUsage(
                input_tokens=m.input_tokens,
                output_tokens=m.output_tokens,
                latency_ms=m.latency_ms,
            )
        )


def _merge_media_usage(
    response: ChatwootRuntimeResponse,
    media_usage: list[MediaUsage] | None,
) -> ChatwootRuntimeResponse:
    if not media_usage:
        return response
    total_in = sum(m.input_tokens for m in media_usage)
    total_out = sum(m.output_tokens for m in media_usage)
    existing = response.usage
    return response.model_copy(
        update={
            "usage": existing.model_copy(
                update={
                    "media": existing.media.model_copy(
                        update={
                            "input_tokens": existing.media.input_tokens + total_in,
                            "output_tokens": existing.media.output_tokens + total_out,
                        }
                    ),
                }
            ),
        }
    )


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
