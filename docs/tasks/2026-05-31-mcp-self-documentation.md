# Task — Self-documenting Oryntra MCP (instructions + resources + README)

**Date:** 2026-05-31
**Branch:** develop
**Depends on:** `feat(api): Oryntra MCP server` (909ced6) and the E2E validation fixes (f9bf20e).

## Goal

Make `packages/oryntra-mcp` self-explanatory to any consuming agent (Claude, Cursor,
a LangGraph graph) so it knows *how* to drive the workspace, not just *what* each tool
does. Decisions confirmed with the user:

1. **Embed guidance in the MCP itself** — server `instructions` (returned on `initialize`)
   plus MCP **resources** the client can read on demand.
2. **README** — expand `packages/oryntra-mcp/README.md` for npm consumers.
3. **LangGraph** — the guidance includes an *agent-design* section grounded in the real
   runtime (`agent-python`), which compiles Oryntra agents to a LangGraph `StateGraph`.

## Grounding (do not invent)

`agent-python/src/oryntra_agent/agent/supervisor.py`:
- `StateGraph(SupervisorState)` with nodes `route` → conditional edges → `respond`.
- Supervisor mode: `route` node (supervisor LLM) selects a specialist using
  `intent_keywords` + `confidence_threshold`, falls back to `fallback_specialist_id`.
- Single mode: one specialist, no routing.
- `tool_runtime.build_specialist_tools()` binds each specialist's `tools_allowlist`
  to native tools + connector slugs + MCP server tools.

Native tool names (for allowlist examples): `query_products`, `search_knowledge_base`,
`query_documents`, `send_document`, `chatwoot_get_contact`, `chatwoot_update_contact`,
`update_contact_memory`, `resolve_conversation`, `gcal_create_event`, `gcal_update_event`,
`gcal_delete_event`, `gcal_list_events`, `gcal_find_free_slots`.

## Changes

- `src/guides.ts` (new) — markdown constants: intake (interview the user before building),
  getting-started, agent-design (LangGraph), tools-and-scopes; plus a short composed
  `SERVER_INSTRUCTIONS`.
- `src/server.ts` — pass `instructions` in `McpServer` options; `registerResource` for the
  four guides under `oryntra://guide/*`.
- `scripts/build.mjs` — add `guides.ts` to the explicit `sourceFiles` list.
- `README.md` — install (npx + local), env vars, tool catalog by domain, workflows,
  LangGraph note, security (write-only secrets), abilities table.

## Follow-up — config blocks exposed (typed)

The REST API already accepted the agent/specialist config blocks (loose `array` rules);
only the MCP wrapper omitted them. Added typed Zod schemas (`src/schemas.ts`) mirroring the
panel forms and wired them into `create_agent`/`update_agent` (`debounce_config`,
`guard_config`, `rag_config`) and `create_specialist`/`update_specialist`
(`contact_tools_config`, `product_tools_config`, `document_tools_config`, `memory_config`,
`resolution_config`, `handoff_config`, `google_calendar_config`). Id fields that need a
panel lookup (`handoff_config.team_id`/`agent_id`, `google_calendar_config.connection_id`/
`calendar_id`) are typed but flagged "Advanced". Guides/README updated to match. Verified
round-trip via a stdio client against the live API.

## Follow-up — reference lookup tools (close the Advanced id fields)

`LookupController` (gated `specialist:read`) + 5 routes under `/api/v1/lookups/*`:
chatwoot teams, chatwoot agents (optional `team_id`), chatwoot labels, calendar
connections, and live calendar listing for a connection. Data sources mirror the panel
form helpers (`chatwoot_teams`, `workspace_members⋈users`, `chatwoot_labels`,
`GoogleCalendarConnection`, `GoogleCalendarClient::listCalendars`). Five MCP tools wrap
them (`list_chatwoot_teams`, `list_chatwoot_agents`, `list_chatwoot_labels`,
`list_calendar_connections`, `list_calendar_calendars`) — total tools 30 → 35. The config
schemas now point their id fields at these lookups instead of "panel-only". Verified live
(real teams/agents/labels/calendars) + Pest (`LookupApiTest`: workspace scoping + ability
gating).

## Verification

- `npm run build` clean (transpile diagnostics gate).
- Smoke: connect, `resources/list` shows 3 guides, `resources/read` returns markdown,
  `whoami` still works.
- `claude mcp list` → oryntra ✓ Connected.
