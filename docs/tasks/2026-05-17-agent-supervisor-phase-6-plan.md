# Agent Supervisor Phase 6 Implementation Plan

**Goal:** Add the durable Laravel and Python contract foundation for supervisor-mode agents with one or more specialist sub-agents.

**Architecture:** Laravel owns configuration, tenancy, Filament UI, and the runtime payload. Python keeps the same `/internal/chatwoot/messages` endpoint and expands the existing Pydantic contract to receive supervisor and specialist configuration before LangGraph execution replaces the current deterministic handler.

**Scope for this branch:**
- Add `agents.mode` and supervisor config fields.
- Add `agent_specialists` with strict workspace and agent ownership.
- Add `AgentSpecialist` model, factory, tests, and minimal Filament relation manager.
- Include `agent_mode`, `supervisor`, and `specialists` in `AgentRuntimeClient`.
- Expand Python Pydantic schemas and endpoint trace to understand supervisor payloads.

**Deferred:**
- Real LLM supervisor routing.
- Tool execution and `agent_tools`.
- RAG, media, MCP, HITL approval UI.

## Implementation Order

1. Schema and model layer.
2. Filament minimal supervisor UI.
3. Laravel runtime payload expansion.
4. Python schema/handler expansion.
5. Focused tests and quality gates.
