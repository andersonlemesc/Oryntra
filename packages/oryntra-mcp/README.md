# @oryntra/mcp

MCP server that lets an AI agent (Claude, Cursor, a LangGraph graph, …) manage an
**Oryntra workspace** — agents, specialists, LLM keys, categories, products, knowledge
base (RAG), HTTP connectors and MCP servers — through a workspace-scoped API token.

It is a thin wrapper over Oryntra's public REST API (`/api/v1`): every tool maps to one
HTTP call, authenticated with a personal access token you generate in the panel. The
token is scoped to **one workspace**; the server can never touch another.

## Get a token

In the Oryntra panel: avatar menu → **Tokens da API** → *Gerar token*. Pick the workspace
and the abilities (scopes) the token may use. Copy the token — it is shown only once.

## Install

Published package (recommended):

```bash
claude mcp add oryntra \
  --env ORYNTRA_API_URL=https://your-domain.com/api/v1 \
  --env ORYNTRA_API_TOKEN='your-token' \
  -- npx -y @oryntra/mcp
```

From a local checkout (before publishing), point at the built entrypoint instead:

```bash
npm install && npm run build      # in packages/oryntra-mcp
claude mcp add oryntra \
  --env ORYNTRA_API_URL=http://localhost:8080/api/v1 \
  --env ORYNTRA_API_TOKEN='your-token' \
  -- node /absolute/path/to/packages/oryntra-mcp/dist/index.js
```

> The API token contains a `|` character. Always wrap it in single quotes so the shell
> doesn't interpret it as a pipe.

Environment variables:

| Var | Required | Description |
|-----|----------|-------------|
| `ORYNTRA_API_URL` | yes | Base URL of your Oryntra API, ending in `/api/v1`. |
| `ORYNTRA_API_TOKEN` | yes | Personal access token (or set `ORYNTRA_API_TOKEN_FILE` to read it from a file). |

## Self-documenting

The server ships its own usage guidance, so a consuming agent learns *how* to drive the
workspace, not just what each tool does:

- **`instructions`** — returned on `initialize`; a short orientation every client sees.
- **Resources** — readable on demand:
  - `oryntra://guide/intake` — the questions to ask the user **before** building an agent
    (mode, voice, LLM, routing, each specialist's tools, knowledge, activation).
  - `oryntra://guide/getting-started` — order of operations, scoping, async knowledge,
    write-only secrets, error semantics.
  - `oryntra://guide/agent-design` — how an Oryntra agent maps to the **LangGraph**
    runtime; modes, specialists, `tools_allowlist`, RAG.
  - `oryntra://guide/tools-and-scopes` — ability→tool map, pagination, connectors vs MCP
    servers.

## Tools

Call `whoami` first to confirm the connection and see which abilities your token grants.

- **Agents** — `list_agents`, `get_agent`, `create_agent`, `update_agent`, `delete_agent`
- **Specialists** — `list_specialists`, `create_specialist`, `update_specialist`, `delete_specialist`
- **LLM keys** — `list_llm_keys`, `list_llm_models`, `create_llm_key`, `delete_llm_key`
- **Catalog** — `list_categories`, `create_category`, `list_products`, `create_product`, `update_product`, `delete_product`
- **Knowledge (RAG)** — `list_knowledge`, `add_knowledge_from_text`, `delete_knowledge`
- **External tools** — `list_connectors`, `create_connector`, `delete_connector`, `list_mcp_servers`, `create_mcp_server`, `list_mcp_server_tools`, `delete_mcp_server`

### Typical flow to build a working agent

1. `create_llm_key` — register a provider key (BYOK); `api_key` is write-only.
2. `list_llm_models` — discover a valid `llm_model` for that key.
3. `create_agent` (mode `single`) — a specialist is auto-created.
4. `list_specialists` then `update_specialist` — set `role_prompt`, `llm_key_id`,
   `llm_model`, and `tools_allowlist`.
5. `add_knowledge_from_text` — feed domain knowledge (indexed in the background:
   `pending` → `indexed`).
6. `update_agent { status: "active" }`.

## How agents run (LangGraph)

Oryntra compiles each agent to a LangGraph `StateGraph` (`route → specialist → respond`).
In **supervisor** mode the `route` node uses the supervisor LLM to pick a specialist by
`intent_keywords` / `confidence_threshold` (falling back to `fallback_specialist_id`); in
**single** mode one specialist handles everything. Each specialist is a tool-calling loop
limited to its `tools_allowlist` — native tools (e.g. `query_products`,
`search_knowledge_base`), connector slugs, and registered MCP server tools. See
`oryntra://guide/agent-design`.

## Security

`llm_key.api_key`, `connector.secret`, and `mcp_server.secret.token` are write-only:
accepted on create, stored encrypted, and never returned. Reads expose only
`has_credentials`. A read-only token (no `:write` scopes) can inspect but not mutate.

## Required token abilities

Each tool needs the matching ability on the token: `agent:read/write`,
`specialist:read/write`, `llmkey:read/write`, `category:read/write`,
`product:read/write`, `knowledge:read/write`, `tool:read/write`, `media:read/write`.

## Development

- `npm run build` — transpile `src/` → `dist/`.
- `npm run typecheck` — diagnostics only, no emit.
- Node **22+** required.
