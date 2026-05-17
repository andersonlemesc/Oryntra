import argparse
import json
import os
from collections.abc import Sequence
from typing import Any

from langgraph.checkpoint.postgres import PostgresSaver

from oryntra_agent.agent.supervisor import run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest
from oryntra_agent.settings import settings


def setup_checkpointer(postgres_url: str | None = None) -> None:
    conn_string = postgres_url or settings.postgres_url

    with PostgresSaver.from_conn_string(conn_string) as checkpointer:
        checkpointer.setup()


def smoke_supervisor(
    thread_id: str,
    real_llm: bool = False,
    llm_provider: str | None = None,
    llm_model: str | None = None,
    llm_api_key_env: str = "OPENAI_API_KEY",
    real_specialist_llm: bool = False,
    specialist_llm_provider: str | None = None,
    specialist_llm_model: str | None = None,
    specialist_llm_api_key_env: str | None = None,
) -> dict[str, Any]:
    supervisor_config: dict[str, Any] = {
        "prompt": "Route the message to the best specialist.",
    }

    if real_llm:
        llm_api_key = os.getenv(llm_api_key_env)

        if not llm_provider or not llm_model:
            raise RuntimeError("--llm-provider and --llm-model are required with --real-llm.")

        if not llm_api_key:
            raise RuntimeError(f"{llm_api_key_env} is required with --real-llm.")

        supervisor_config.update(
            {
                "llm_provider": llm_provider,
                "llm_model": llm_model,
                "llm_api_key": llm_api_key,
            }
        )

    specialist_config: dict[str, Any] = {
        "id": 5,
        "name": "Suporte",
        "role_prompt": "Answer support questions.",
        "llm_temperature": 0.2,
        "tools": [],
        "intent_keywords": ["ajuda", "suporte"],
        "confidence_threshold": 0.5,
    }

    if real_specialist_llm:
        resolved_provider = specialist_llm_provider or llm_provider
        resolved_model = specialist_llm_model or llm_model
        resolved_key_env = specialist_llm_api_key_env or llm_api_key_env
        specialist_llm_api_key = os.getenv(resolved_key_env)

        if not resolved_provider or not resolved_model:
            raise RuntimeError(
                "--specialist-llm-provider and --specialist-llm-model are required "
                "with --real-specialist-llm unless --llm-provider and --llm-model are set."
            )

        if not specialist_llm_api_key:
            raise RuntimeError(f"{resolved_key_env} is required with --real-specialist-llm.")

        specialist_config.update(
            {
                "llm_provider": resolved_provider,
                "llm_model": resolved_model,
                "llm_api_key": specialist_llm_api_key,
            }
        )

    payload = ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": thread_id,
            "supervisor": supervisor_config,
            "messages": [{"id": "smoke-1", "content": "preciso de ajuda no suporte"}],
            "specialists": [specialist_config],
        }
    )
    response = run_chatwoot_runtime(payload)
    turn_count = response.trace[0].input.get("turn_count") if response.trace else None
    specialist_trace = next(
        (trace_step for trace_step in response.trace if trace_step.type == "specialist_response"),
        None,
    )
    response_source = (
        specialist_trace.output.get("source")
        if specialist_trace is not None and isinstance(specialist_trace.output.get("source"), str)
        else None
    )

    return {
        "status": response.status,
        "specialist_id": response.specialist_id,
        "turn_count": turn_count,
        "response_source": response_source,
        "content_preview": (response.response.content or "")[:120],
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="oryntra_agent.manage")
    subparsers = parser.add_subparsers(dest="command", required=True)

    setup_parser = subparsers.add_parser(
        "setup-checkpointer",
        help="Create or update LangGraph Postgres checkpoint tables.",
    )
    setup_parser.add_argument(
        "--postgres-url",
        default=None,
        help="Override POSTGRES_URL for this command.",
    )
    smoke_parser = subparsers.add_parser(
        "smoke-supervisor",
        help="Run a local supervisor runtime smoke test.",
    )
    smoke_parser.add_argument(
        "--thread-id",
        default="workspace:1:account:1:conversation:smoke-supervisor",
        help="LangGraph thread_id used by the smoke run.",
    )
    smoke_parser.add_argument(
        "--real-llm",
        action="store_true",
        help="Use a real supervisor LLM instead of deterministic fallback.",
    )
    smoke_parser.add_argument(
        "--llm-provider",
        choices=["openai", "anthropic", "gemini"],
        default=None,
        help="LLM provider used with --real-llm.",
    )
    smoke_parser.add_argument(
        "--llm-model",
        default=None,
        help="LLM model used with --real-llm.",
    )
    smoke_parser.add_argument(
        "--llm-api-key-env",
        default="OPENAI_API_KEY",
        help="Environment variable that contains the LLM API key.",
    )
    smoke_parser.add_argument(
        "--real-specialist-llm",
        action="store_true",
        help="Use a real specialist LLM instead of mock specialist response.",
    )
    smoke_parser.add_argument(
        "--specialist-llm-provider",
        choices=["openai", "anthropic", "gemini"],
        default=None,
        help="Specialist LLM provider used with --real-specialist-llm.",
    )
    smoke_parser.add_argument(
        "--specialist-llm-model",
        default=None,
        help="Specialist LLM model used with --real-specialist-llm.",
    )
    smoke_parser.add_argument(
        "--specialist-llm-api-key-env",
        default=None,
        help="Environment variable that contains the specialist LLM API key.",
    )

    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command == "setup-checkpointer":
        setup_checkpointer(args.postgres_url)
        print("LangGraph checkpointer setup complete.")
        return 0

    if args.command == "smoke-supervisor":
        print(
            json.dumps(
                smoke_supervisor(
                    thread_id=args.thread_id,
                    real_llm=args.real_llm,
                    llm_provider=args.llm_provider,
                    llm_model=args.llm_model,
                    llm_api_key_env=args.llm_api_key_env,
                    real_specialist_llm=args.real_specialist_llm,
                    specialist_llm_provider=args.specialist_llm_provider,
                    specialist_llm_model=args.specialist_llm_model,
                    specialist_llm_api_key_env=args.specialist_llm_api_key_env,
                ),
                sort_keys=True,
            )
        )
        return 0

    raise ValueError(f"Unknown command: {args.command}")


if __name__ == "__main__":
    raise SystemExit(main())
