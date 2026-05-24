"""Native LangChain tool-calling for specialists.

The supervisor decision/respond loop only knows how to act on
``respond_text`` / ``request_human_handoff`` / ``request_reroute``. This
module wires the remaining "executable" tools (``chatwoot_update_contact``,
``chatwoot_get_contact``, ``update_contact_memory``) into a proper tool
calling loop using ``ChatModel.bind_tools`` so the specialist can actually
invoke them mid-turn instead of just describing the action in plain text.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any, Literal

from langchain_core.messages import (
    AIMessage,
    HumanMessage,
    SystemMessage,
    ToolMessage,
)
from langchain_core.tools import StructuredTool
from pydantic import BaseModel, ConfigDict, Field

from oryntra_agent.agent.tools import (
    GetContactRequest,
    UpdateContactMemoryRequest,
    UpdateContactRequest,
    chatwoot_get_contact,
    chatwoot_update_contact,
    update_contact_memory,
)

logger = logging.getLogger(__name__)


EXECUTABLE_TOOLS: frozenset[str] = frozenset(
    {
        "chatwoot_update_contact",
        "chatwoot_get_contact",
        "update_contact_memory",
    }
)


MAX_TOOL_ITERATIONS = 4
TOOL_ITERATIONS_HARD_CAP = 20


@dataclass(frozen=True)
class ToolRuntimeContext:
    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None
    contact_id: int | None
    conversation_id: int


class ChatwootUpdateContactArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str | None = Field(default=None, description="Novo nome do contato.")
    email: str | None = Field(default=None, description="Novo email do contato.")
    phone_number: str | None = Field(default=None, description="Novo telefone do contato (E.164).")


class ChatwootGetContactArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")


class UpdateContactMemoryArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["preference", "fact", "constraint", "history", "custom"] = Field(
        description="Categoria da memoria.",
    )
    content: str = Field(description="Frase curta descrevendo o fato sobre o cliente.")
    confidence: float | None = Field(
        default=None,
        description="Confianca de 0 a 1 sobre a memoria extraida.",
    )


def build_specialist_tools(
    allowed_tools: list[str], ctx: ToolRuntimeContext
) -> list[StructuredTool]:
    tools: list[StructuredTool] = []

    if ctx.contact_id is None:
        return tools

    if "chatwoot_update_contact" in allowed_tools:
        tools.append(_make_update_contact_tool(ctx))

    if "chatwoot_get_contact" in allowed_tools:
        tools.append(_make_get_contact_tool(ctx))

    if "update_contact_memory" in allowed_tools:
        tools.append(_make_update_memory_tool(ctx))

    return tools


def _make_update_contact_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        name: str | None = None,
        email: str | None = None,
        phone_number: str | None = None,
    ) -> str:
        if not any([name, email, phone_number]):
            return "error: provide at least one of name, email, phone_number."

        try:
            response = chatwoot_update_contact(
                UpdateContactRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                    name=name,
                    email=email,
                    phone_number=phone_number,
                )
            )
        except Exception as exc:
            logger.exception("chatwoot_update_contact tool call failed")
            return f"error: chatwoot_update_contact failed ({exc})."

        return f"ok: contato atualizado no Chatwoot. status={response.status}."

    return StructuredTool.from_function(
        name="chatwoot_update_contact",
        description=(
            "Atualiza nome, email ou telefone do contato no Chatwoot e na base local. "
            "Use quando o cliente informar um novo dado de contato."
        ),
        args_schema=ChatwootUpdateContactArgs,
        func=run,
    )


def _make_get_contact_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run() -> str:
        try:
            response = chatwoot_get_contact(
                GetContactRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                )
            )
        except Exception as exc:
            logger.exception("chatwoot_get_contact tool call failed")
            return f"error: chatwoot_get_contact failed ({exc})."

        return f"contact: {response.contact}"

    return StructuredTool.from_function(
        name="chatwoot_get_contact",
        description="Le os dados atuais do contato no Chatwoot (cache local de 5 minutos).",
        args_schema=ChatwootGetContactArgs,
        func=run,
    )


def _make_update_memory_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(type: str, content: str, confidence: float | None = None) -> str:
        try:
            response = update_contact_memory(
                UpdateContactMemoryRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                    type=type,  # type: ignore[arg-type]
                    content=content,
                    confidence=confidence,
                )
            )
        except Exception as exc:
            logger.exception("update_contact_memory tool call failed")
            return f"error: update_contact_memory failed ({exc})."

        return f"ok: memoria gravada. memory_id={response.memory_id}."

    return StructuredTool.from_function(
        name="update_contact_memory",
        description=(
            "Registra um fato/preferencia/restricao sobre o cliente na memoria longo prazo. "
            "Use quando ouvir uma informacao que sera util em conversas futuras."
        ),
        args_schema=UpdateContactMemoryArgs,
        func=run,
    )


@dataclass(frozen=True)
class ToolLoopResult:
    text: str | None
    tool_calls: list[dict[str, Any]]
    debug_prompt: dict[str, str] | None = None


def run_specialist_tool_loop(
    chat_model: Any,
    tools: list[StructuredTool],
    system_prompt: str,
    user_prompt: str,
    max_iterations: int = MAX_TOOL_ITERATIONS,
) -> ToolLoopResult:
    """Run a bounded ReAct loop: invoke the model, dispatch any tool calls,
    feed back ``ToolMessage``s, and repeat until the model returns plain text
    or we hit ``max_iterations`` (clamped to ``TOOL_ITERATIONS_HARD_CAP``).
    """

    if not tools:
        raise ValueError("run_specialist_tool_loop requires at least one tool.")

    iterations = max(1, min(max_iterations, TOOL_ITERATIONS_HARD_CAP))

    chat_with_tools = chat_model.bind_tools(tools)
    tool_by_name = {tool.name: tool for tool in tools}
    messages: list[Any] = [
        SystemMessage(content=system_prompt),
        HumanMessage(content=user_prompt),
    ]
    tool_calls_trace: list[dict[str, Any]] = []

    for _ in range(iterations):
        try:
            ai_message: AIMessage = chat_with_tools.invoke(messages)
        except Exception:
            logger.exception("specialist tool loop invoke failed")
            return ToolLoopResult(text=None, tool_calls=tool_calls_trace)

        messages.append(ai_message)
        pending_calls = getattr(ai_message, "tool_calls", None) or []

        if not pending_calls:
            content = ai_message.content
            text = _extract_text(content)
            return ToolLoopResult(text=text, tool_calls=tool_calls_trace)

        for call in pending_calls:
            name = call.get("name") if isinstance(call, dict) else getattr(call, "name", None)
            args = call.get("args") if isinstance(call, dict) else getattr(call, "args", {})
            call_id = call.get("id") if isinstance(call, dict) else getattr(call, "id", "")

            tool = tool_by_name.get(name) if isinstance(name, str) else None

            if tool is None:
                result = f"error: tool '{name}' is not available."
            else:
                try:
                    result = tool.invoke(args or {})
                except Exception as exc:
                    logger.exception("tool dispatch failed")
                    result = f"error: tool '{name}' raised {exc}."

            tool_calls_trace.append(
                {
                    "tool": name,
                    "input": args or {},
                    "output": _truncate(str(result), 500),
                }
            )
            messages.append(
                ToolMessage(
                    content=str(result),
                    tool_call_id=str(call_id) if call_id else "",
                )
            )

    logger.warning(
        "specialist tool loop exhausted iterations",
        extra={"max_iterations": iterations},
    )
    return ToolLoopResult(text=None, tool_calls=tool_calls_trace)


def _extract_text(content: Any) -> str | None:
    if isinstance(content, str):
        text = content.strip()
        return text or None

    if isinstance(content, list):
        parts = [
            part.get("text", "").strip()
            for part in content
            if isinstance(part, dict) and isinstance(part.get("text"), str)
        ]
        joined = "\n".join(part for part in parts if part)
        return joined or None

    return None


def _truncate(value: str, limit: int) -> str:
    if len(value) <= limit:
        return value
    return value[: limit - 3] + "..."


__all__ = [
    "EXECUTABLE_TOOLS",
    "MAX_TOOL_ITERATIONS",
    "ToolLoopResult",
    "ToolRuntimeContext",
    "build_specialist_tools",
    "run_specialist_tool_loop",
]
