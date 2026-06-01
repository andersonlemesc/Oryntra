/**
 * In-band documentation surfaced to consuming agents via the MCP `instructions`
 * field (returned on initialize) and as readable resources under `oryntra://guide/*`.
 *
 * Keep these grounded in the real REST API and the LangGraph runtime
 * (agent-python). Do not document behaviour that does not exist.
 */

export const GETTING_STARTED = `# Oryntra MCP — Getting Started

This server manages **one Oryntra workspace** (the one your API token is scoped to).
Every tool is workspace-scoped: you can never see or touch another workspace's data.

## First call
Always call \`whoami\` first. It returns the workspace and the **abilities** your token
grants. A tool whose scope is missing will fail with HTTP 403 — don't retry it, ask the
user for a token with that scope.

## Recommended order to build a working agent
1. \`list_llm_keys\` — a specialist needs a BYOK provider key to answer. If none exists,
   \`create_llm_key\` (the \`api_key\` is write-only, stored encrypted, never returned).
2. \`list_llm_models { llm_key_id }\` — discover a valid \`llm_model\` string before you
   set it on a specialist.
3. \`create_agent { name, mode }\`:
   - **single** — one specialist is auto-created. Configure it with \`update_specialist\`.
   - **supervisor** — you add specialists yourself with \`create_specialist\` and the
     supervisor routes between them.
4. Configure the specialist(s): \`role_prompt\`, \`llm_key_id\`, \`llm_model\`,
   \`tools_allowlist\`. See the agent-design guide.
5. Optionally seed the knowledge base (\`add_knowledge_from_text\`) and register tools
   (\`create_connector\`, \`create_mcp_server\`).
6. \`update_agent { status: "active" }\` when ready.

## Knowledge base is asynchronous
\`add_knowledge_from_text\` returns \`index_status: "pending"\`. Embedding happens in a
background job; poll \`list_knowledge\` until the document is \`indexed\` (then
\`chunks_count\` is set). \`failed\` means \`index_error\` explains why.

## Write-only secrets
\`llm_key.api_key\`, \`connector.secret\`, and \`mcp_server.secret.token\` are accepted on
write, stored encrypted, and **never** returned. Reads expose only \`has_credentials\`.

## Errors
Validation failures return HTTP 422 with a per-field \`errors\` map — fix the input and
retry. 403 = missing ability. 404 = not in this workspace (or wrong id). 429 = rate
limited (per token), back off.
`;

export const AGENT_DESIGN = `# Designing Oryntra agents (LangGraph runtime)

Oryntra agents run on a **LangGraph** \`StateGraph\` in the Python runtime. Understanding
the graph helps you configure agents that actually behave well.

## How an agent maps to the graph
- An agent compiles to a graph: \`START → route → (specialist) → respond → END\`.
- **Supervisor mode**: the \`route\` node runs the *supervisor LLM*
  (\`supervisor_llm_key_id\` + \`supervisor_llm_model\`, guided by \`supervisor_prompt\`).
  It picks the specialist whose \`intent_keywords\` / \`confidence_threshold\` best match
  the incoming message. If nothing matches, it uses \`fallback_specialist_id\`.
- **Single mode**: no routing — one specialist handles everything. Cheapest, best when
  the scope is narrow.
- Each **specialist** is a tool-calling (ReAct-style) loop: its \`role_prompt\` is the
  system prompt, and it may only call the tools listed in its \`tools_allowlist\`.

## Picking a mode
- One clear job (e.g. "delivery ordering") → **single**.
- Distinct intents that need different prompts/tools (e.g. *Sales* vs *Support*) →
  **supervisor** with one specialist per intent. Give each tight \`intent_keywords\`.

## tools_allowlist — what can go in it
Entries are matched by name against the tools the runtime can bind:
- **Native tools**: \`query_products\`, \`search_knowledge_base\`, \`query_documents\`,
  \`send_document\`, \`update_contact_memory\`, \`chatwoot_get_contact\`,
  \`chatwoot_update_contact\`, \`resolve_conversation\`, and Google Calendar tools
  \`gcal_create_event\`, \`gcal_update_event\`, \`gcal_delete_event\`, \`gcal_list_events\`,
  \`gcal_find_free_slots\`.
- **Connector slugs**: the \`slug\` of any HTTP connector you created (\`create_connector\`).
- **MCP server tools**: tools exposed by an MCP server you registered
  (\`create_mcp_server\`); confirm names with \`list_mcp_server_tools\`.

Only grant what the specialist needs — a smaller allowlist means cleaner tool selection
and fewer wrong calls. A sales specialist typically gets \`query_products\` +
\`search_knowledge_base\`; a support specialist gets \`resolve_conversation\` +
\`update_contact_memory\`.

## Retrieval (RAG)
\`search_knowledge_base\` retrieves from the workspace knowledge base. The embedding model
is pinned per workspace, so just keep documents indexed (\`add_knowledge_from_text\` →
\`indexed\`). Add a specialist's \`search_knowledge_base\` to its allowlist and tell it in
\`role_prompt\` to ground answers in retrieved context.

## Good prompts
- \`role_prompt\`: state the persona, the allowed actions, and when to hand off or stop.
- \`supervisor_prompt\`: describe each specialist and the routing rule in one short list.
- Keep temperature low (0.2–0.4) for transactional flows.
`;

export const TOOLS_AND_SCOPES = `# Tools, scopes & conventions

## Ability → tools
| Ability | Tools |
| --- | --- |
| \`agent:read\` / \`agent:write\` | \`list_agents\`, \`get_agent\` / \`create_agent\`, \`update_agent\`, \`delete_agent\` |
| \`specialist:read\` / \`specialist:write\` | \`list_specialists\` / \`create_specialist\`, \`update_specialist\`, \`delete_specialist\` |
| \`llmkey:read\` / \`llmkey:write\` | \`list_llm_keys\`, \`list_llm_models\` / \`create_llm_key\`, \`delete_llm_key\` |
| \`category:read\` / \`category:write\` | \`list_categories\` / \`create_category\` |
| \`product:read\` / \`product:write\` | \`list_products\` / \`create_product\`, \`update_product\`, \`delete_product\` |
| \`knowledge:read\` / \`knowledge:write\` | \`list_knowledge\` / \`add_knowledge_from_text\`, \`delete_knowledge\` |
| \`tool:read\` / \`tool:write\` | \`list_connectors\`, \`list_mcp_servers\`, \`list_mcp_server_tools\` / \`create_connector\`, \`delete_connector\`, \`create_mcp_server\`, \`delete_mcp_server\` |

A read-only token (no \`:write\` scopes) can inspect everything but mutate nothing.

## Pagination
List tools accept \`per_page\` (1–100, default 20) and return Laravel pagination
metadata (\`meta.current_page\`, \`meta.last_page\`, \`links.next\`).

## Connectors vs MCP servers (both are agent tools)
- **Connector** = one HTTP endpoint. \`config\` defines method/url/path;
  \`config.param_schema.properties\` declares the typed args the LLM fills (each with
  \`type\`, \`description\`, \`location\` = path|query|body|header, \`required\`); \`secret\`
  holds credentials.
- **MCP server** = a Streamable-HTTP MCP endpoint whose tools become callable. Register
  with \`create_mcp_server\`, then \`list_mcp_server_tools\` does a live handshake to list
  what it exposes.

## Config blocks (advanced)
\`create_agent\`/\`update_agent\` accept \`debounce_config\`, \`guard_config\`, \`rag_config\`.
\`create_specialist\`/\`update_specialist\` accept \`contact_tools_config\`,
\`product_tools_config\`, \`document_tools_config\`, \`memory_config\`, \`resolution_config\`,
\`handoff_config\`, \`google_calendar_config\`. All optional with sane defaults — only send
what you mean to change. Resolve their id fields with the lookup tools first:
\`list_chatwoot_teams\`, \`list_chatwoot_agents\`, \`list_chatwoot_labels\`,
\`list_calendar_connections\`, \`list_calendar_calendars\`. See the intake guide.

## Lookups (reference data)
\`list_chatwoot_teams\`, \`list_chatwoot_agents\` (optional \`team_id\` filter),
\`list_chatwoot_labels\`, \`list_calendar_connections\`, \`list_calendar_calendars\`
(\`connection_id\`) — all \`specialist:read\`. They exist to fill the id fields of
\`handoff_config\` and \`google_calendar_config\`.
`;

export const INTAKE = `# Before you build: interview the user

Creating an agent commits many choices at once. **Do not guess them.** Ask the user a
short round of questions first, confirm the plan, then call \`create_agent\`. Present
options as a small menu (with a recommended default) rather than open questions — it is
faster for the user to pick than to specify.

Never invent business facts (prices, hours, policies, tone). Get them from the user or
from the knowledge base.

## 1. Purpose → decides the mode
- "What should this agent do, in one sentence?"
- One focused job → **single** mode. Several distinct intents that need different prompts
  or tools (e.g. *Sales* vs *Support*) → **supervisor** mode, one specialist per intent.
- Confirm: "single specialist, or a supervisor routing to N specialists?"

## 2. Voice & delivery (agent-level)
- \`locale\` — language/region for replies (e.g. \`pt-BR\`, default \`en\`).
- \`timezone\` — IANA tz used for any time logic (e.g. \`America/Sao_Paulo\`, default \`UTC\`).
- \`response_mode\` — how replies reach the customer:
  - \`automatic\` — agent answers directly (default).
  - \`suggestion_only\` — drafts a suggestion for a human to send.
  - \`human_approval\` — waits for a human to approve before sending.
- \`status\` — create as \`inactive\` (draft) and activate later, or \`active\` now?

## 3. Brain (LLM)
- Which provider key (\`list_llm_keys\`; create one if none) and which model
  (\`list_llm_models\`)?
- \`llm_temperature\` — low (0.2–0.4) for transactional/factual; higher for chatty. Ask
  per specialist if they should differ.

## 4. If supervisor: routing
- Enumerate the intents → one specialist each (\`name\`, what it handles).
- \`supervisor_prompt\` — the routing rules, one short line per specialist.
- \`supervisor_llm_key_id\` + \`supervisor_llm_model\` — model for the router (can be a
  cheaper/faster model than the specialists).
- Per specialist: \`intent_keywords\` (words that route to it), \`confidence_threshold\`
  (how sure the router must be), \`priority\` (tie-break order).
- A fallback specialist for "no clear match" (set via \`fallback_specialist_id\` once the
  specialists exist).

## 5. Each specialist's job & tools
For every specialist ask:
- \`role_prompt\` — persona, allowed actions, when to hand off or stop.
- \`tools_allowlist\` — what it may DO. Walk the options and grant only what is needed:
  - Look up the catalog? → \`query_products\`
  - Answer from the knowledge base (RAG)? → \`search_knowledge_base\`
  - Read/attach documents? → \`query_documents\`, \`send_document\`
  - Read/update the contact or its memory? → \`chatwoot_get_contact\`,
    \`chatwoot_update_contact\`, \`update_contact_memory\`
  - Close the conversation? → \`resolve_conversation\`
  - Scheduling? → \`gcal_find_free_slots\`, \`gcal_create_event\`, \`gcal_update_event\`,
    \`gcal_delete_event\`, \`gcal_list_events\`
  - Call an external API or another MCP server? → the connector \`slug\` /
    registered MCP tool (create it first; see the tools-and-scopes guide).

## 6. Knowledge & catalog scope (avoid cross-agent mixing)
- Any policies/FAQ/docs to seed now? → \`add_knowledge_from_text\` (indexes in background).
  A specialist must also have \`search_knowledge_base\` in its allowlist to use it.
- **Scope data to this agent.** Products and knowledge docs are workspace-wide by default —
  if the workspace has more than one agent, pass \`agent_ids: [thisAgentId]\` on
  \`create_product\`/\`update_product\` and \`add_knowledge_from_text\` so each agent only sees
  its own catalog/knowledge. An item with no \`agent_ids\` is **global** (every agent sees
  it) — use that only for truly shared data. Filter checks with \`list_products?agent_id=\`
  and \`list_knowledge?agent_id=\`.

## 7. Advanced behaviour (optional config blocks)
These are settable here too — offer them only if the user cares; sensible defaults exist.
- Agent: \`debounce_config\` (batch rapid messages), \`guard_config\` (block sensitive
  data / prompt injection, low-confidence handling), \`rag_config\` (top_k, min_score,
  answer-only-with-context).
- Specialist: \`contact_tools_config\`, \`product_tools_config\`, \`document_tools_config\`
  (which doc categories it may send), \`memory_config\` (extract/inject contact memory,
  tool-call budget), \`resolution_config\` (auto-close rules), \`handoff_config\` (human
  handoff rules), \`google_calendar_config\`.
- **Id fields — resolve them with a lookup tool first:** \`handoff_config.team_id\` ←
  \`list_chatwoot_teams\`; \`handoff_config.agent_id\` ← \`list_chatwoot_agents\`;
  \`*.label_name\` ← \`list_chatwoot_labels\`; \`google_calendar_config.connection_id\` ←
  \`list_calendar_connections\`; \`google_calendar_config.calendar_id\` ←
  \`list_calendar_calendars\`. Never guess these ids — call the lookup, show the user the
  options, and use the chosen value.

## 8. Confirm, then build
Summarize the plan (mode, specialists, models, tools, knowledge, advanced config, status)
and get a yes before creating anything.`;

export const SERVER_INSTRUCTIONS = `Oryntra MCP manages one workspace (scoped to your API token): agents, specialists, BYOK
LLM keys, product catalog, RAG knowledge base, and the HTTP/MCP tools agents can call.

Call \`whoami\` first to confirm the workspace and your abilities.

Building an agent commits many choices at once — first INTERVIEW the user (mode, voice,
LLM, routing, each specialist's tools, knowledge, activation) and confirm the plan before
calling create_agent. See oryntra://guide/intake. Then: create/choose an llm_key →
list_llm_models → create_agent (single|supervisor) → configure the specialist
(role_prompt, llm_key_id, llm_model, tools_allowlist) → activate. The knowledge base
indexes asynchronously (pending → indexed). Secrets are write-only.

Agents run on a LangGraph StateGraph (route → specialist → respond). Read these resources
for detail:
- oryntra://guide/intake
- oryntra://guide/getting-started
- oryntra://guide/agent-design
- oryntra://guide/tools-and-scopes`;
