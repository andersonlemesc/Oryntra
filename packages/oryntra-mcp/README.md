# @oryntra/mcp

MCP server that lets an AI agent (Claude, Cursor, …) manage an **Oryntra workspace** — agents,
specialists, LLM keys, categories, products, knowledge base (RAG), HTTP connectors and MCP servers
— through a workspace-scoped API token.

It is a thin wrapper over Oryntra's public REST API (`/api/v1`): every tool maps to one HTTP call,
authenticated with a personal access token you generate in the panel.

## Get a token

In the Oryntra panel: avatar menu → **Tokens da API** → *Gerar token*. Pick the workspace and the
abilities (scopes) the token may use. Copy the token — it is shown only once.

## Install

```bash
claude mcp add oryntra \
  --env ORYNTRA_API_URL=https://your-domain.com/api/v1 \
  --env ORYNTRA_API_TOKEN='your-token' \
  -- npx -y @oryntra/mcp
```

Environment variables:

| Var | Required | Description |
|-----|----------|-------------|
| `ORYNTRA_API_URL` | yes | Base URL of your Oryntra API, ending in `/api/v1`. |
| `ORYNTRA_API_TOKEN` | yes | Personal access token (or set `ORYNTRA_API_TOKEN_FILE` to read from a file). |

## Tools

Call `whoami` first to confirm the connection and see which abilities your token grants.

- **Agents** — `list_agents`, `get_agent`, `create_agent`, `update_agent`, `delete_agent`
- **Specialists** — `list_specialists`, `create_specialist`, `update_specialist`, `delete_specialist`
- **LLM keys** — `list_llm_keys`, `list_llm_models`, `create_llm_key`, `delete_llm_key`
- **Catalog** — `list_categories`, `create_category`, `list_products`, `create_product`, `update_product`, `delete_product`
- **Knowledge (RAG)** — `list_knowledge`, `add_knowledge_from_text`, `delete_knowledge`
- **External tools** — `list_connectors`, `create_connector`, `delete_connector`, `list_mcp_servers`, `create_mcp_server`, `list_mcp_server_tools`, `delete_mcp_server`

### Typical flow to build a working agent

1. `create_llm_key` — register a provider key (BYOK).
2. `list_llm_models` — discover a valid `llm_model` for that key.
3. `create_agent` (mode `single`) — a specialist is auto-created.
4. `list_specialists` then `update_specialist` — set `role_prompt`, `llm_key_id`, `llm_model`.
5. `add_knowledge_from_text` — feed the agent domain knowledge (indexed in the background).

## Required token abilities

Each tool needs the matching ability on the token: `agent:read/write`, `specialist:read/write`,
`llmkey:read/write`, `category:read/write`, `product:read/write`, `knowledge:read/write`,
`tool:read/write`, `media:read/write`.

## Development

- `npm run build` — transpile `src/` → `dist/`.
- `npm run typecheck` — diagnostics only, no emit.
- Node **22+** required.
