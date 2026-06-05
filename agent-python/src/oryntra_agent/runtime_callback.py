"""Posts agent-run results back to Laravel after async background execution.

The Chatwoot message flow is fire-and-forget: Laravel dispatches a run, Python
accepts (HTTP 202), executes the graph in the background and then calls this
module to hand the result back through the internal callback endpoint
(``POST /api/internal/agent-runs/{run_id}/result``). Laravel finalizes the run
(Chatwoot delivery, status transition) from there.
"""

from __future__ import annotations

import asyncio
import logging
from typing import Any

import httpx

from oryntra_agent.settings import settings

logger = logging.getLogger(__name__)


async def post_agent_run_result(
    agent_run_id: int,
    body: dict[str, Any],
    *,
    max_attempts: int = 3,
) -> None:
    """POST a run result to Laravel, retrying transient failures with backoff.

    Laravel may briefly be unavailable (deploy/restart), so we retry a few
    times. A run that is already terminal on the Laravel side is acknowledged
    idempotently (HTTP 200), which we treat as success.
    """
    base_url = settings.laravel_internal_base_url.rstrip("/")
    url = f"{base_url}/api/internal/agent-runs/{agent_run_id}/result"
    headers = {
        "Accept": "application/json",
        "X-Internal-Token": settings.agent_runtime_internal_token,
    }

    last_exc: Exception | None = None

    for attempt in range(1, max_attempts + 1):
        try:
            async with httpx.AsyncClient(timeout=settings.callback_timeout_seconds) as client:
                response = await client.post(url, headers=headers, json=body)
            response.raise_for_status()
            return
        except httpx.HTTPError as exc:
            last_exc = exc
            logger.warning(
                "agent-run callback failed (attempt %d/%d) for run %s: %s",
                attempt,
                max_attempts,
                agent_run_id,
                exc,
            )
            if attempt < max_attempts:
                await asyncio.sleep(min(2**attempt, 8))

    logger.error(
        "agent-run callback permanently failed for run %s: %s",
        agent_run_id,
        last_exc,
    )
