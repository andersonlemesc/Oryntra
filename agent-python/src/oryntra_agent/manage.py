import argparse
import json
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


def smoke_supervisor(thread_id: str) -> dict[str, Any]:
    payload = ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": thread_id,
            "messages": [{"id": "smoke-1", "content": "preciso de ajuda no suporte"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Answer support questions.",
                    "llm_temperature": 0.2,
                    "tools": [],
                    "intent_keywords": ["ajuda", "suporte"],
                    "confidence_threshold": 0.5,
                }
            ],
        }
    )
    response = run_chatwoot_runtime(payload)
    turn_count = response.trace[0].input.get("turn_count") if response.trace else None

    return {
        "status": response.status,
        "specialist_id": response.specialist_id,
        "turn_count": turn_count,
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

    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command == "setup-checkpointer":
        setup_checkpointer(args.postgres_url)
        print("LangGraph checkpointer setup complete.")
        return 0

    if args.command == "smoke-supervisor":
        print(json.dumps(smoke_supervisor(args.thread_id), sort_keys=True))
        return 0

    raise ValueError(f"Unknown command: {args.command}")


if __name__ == "__main__":
    raise SystemExit(main())
