"""Opt-in streaming sinks for the playground runtime.

The production Chatwoot path calls the LLMs with ``.invoke()`` and never sets a
sink, so its behaviour is unchanged. The playground streaming endpoint installs
a token sink (forwards text deltas as they are generated) and an event sink
(routing decision, tool calls/results) via context variables. When a token sink
is active, ``invoke_or_stream`` switches the model call to ``.stream()`` and
aggregates the chunks back into a single message, emitting each text delta.
"""

from __future__ import annotations

from collections.abc import Callable
from contextvars import ContextVar, Token
from typing import Any

_token_sink: ContextVar[Callable[[str], None] | None] = ContextVar(
    "playground_token_sink",
    default=None,
)
_event_sink: ContextVar[Callable[[dict[str, Any]], None] | None] = ContextVar(
    "playground_event_sink",
    default=None,
)


def set_token_sink(sink: Callable[[str], None]) -> Token[Callable[[str], None] | None]:
    return _token_sink.set(sink)


def reset_token_sink(token: Token[Callable[[str], None] | None]) -> None:
    _token_sink.reset(token)


def set_event_sink(
    sink: Callable[[dict[str, Any]], None],
) -> Token[Callable[[dict[str, Any]], None] | None]:
    return _event_sink.set(sink)


def reset_event_sink(token: Token[Callable[[dict[str, Any]], None] | None]) -> None:
    _event_sink.reset(token)


def streaming_active() -> bool:
    """True when a token sink is installed (playground streaming run)."""
    return _token_sink.get() is not None


def emit_token(delta: str) -> None:
    if not delta:
        return
    sink = _token_sink.get()
    if sink is not None:
        sink(delta)


def emit_event(event: dict[str, Any]) -> None:
    sink = _event_sink.get()
    if sink is not None:
        sink(event)


def _chunk_text(chunk: Any) -> str:
    """Extract raw text from a message/chunk without trimming whitespace."""
    content = getattr(chunk, "content", None)

    if isinstance(content, str):
        return content

    if isinstance(content, list):
        parts: list[str] = []
        for part in content:
            if isinstance(part, dict):
                text = part.get("text")
                if isinstance(text, str):
                    parts.append(text)
        return "".join(parts)

    return ""


def invoke_or_stream(runnable: Any, messages: list[Any]) -> Any:
    """Invoke ``runnable``; when streaming is active, stream and emit text deltas.

    Returns the aggregated message (an ``AIMessageChunk`` when streamed, which
    still exposes ``content``, ``tool_calls`` and ``usage_metadata``), so callers
    can treat the result like the message returned by ``.invoke()``.
    """
    if not streaming_active():
        return runnable.invoke(messages)

    aggregate: Any = None
    for chunk in runnable.stream(messages):
        aggregate = chunk if aggregate is None else aggregate + chunk
        emit_token(_chunk_text(chunk))

    if aggregate is None:
        return runnable.invoke(messages)

    return aggregate
