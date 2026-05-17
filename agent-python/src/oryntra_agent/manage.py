import argparse
from collections.abc import Sequence

from langgraph.checkpoint.postgres import PostgresSaver

from oryntra_agent.settings import settings


def setup_checkpointer(postgres_url: str | None = None) -> None:
    conn_string = postgres_url or settings.postgres_url

    with PostgresSaver.from_conn_string(conn_string) as checkpointer:
        checkpointer.setup()


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

    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command == "setup-checkpointer":
        setup_checkpointer(args.postgres_url)
        print("LangGraph checkpointer setup complete.")
        return 0

    raise ValueError(f"Unknown command: {args.command}")


if __name__ == "__main__":
    raise SystemExit(main())
