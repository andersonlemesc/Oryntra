# Supervisor Admin UX Phase 6.1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use the local project and Laravel Boost guidance before editing Filament/Laravel code. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the supervisor runtime configurable from Filament without `tinker`, so an operator can create a supervisor agent, add specialists, and run the real Laravel -> Python -> LLM flow.

**Architecture:** Laravel/Filament remains the source of truth for agent configuration. The `Agent` form should separate single-agent LLM settings from supervisor routing settings, and the specialists relation manager should enforce the minimal fields needed by the runtime payload.

**Tech Stack:** Laravel 13, Filament 5, Pest, Livewire, PostgreSQL.

---

## Files

- Modify: `laravel/app/Filament/Resources/Agents/Schemas/AgentForm.php`
- Modify: `laravel/app/Filament/Resources/Agents/RelationManagers/SpecialistsRelationManager.php`
- Create: `laravel/tests/Feature/AgentSupervisorAdminUxTest.php`

## Tasks

### Task 1: Supervisor form visibility and validation

- [x] Add tests proving supervisor fields are required only when `mode=supervisor`.
- [x] Keep single-agent LLM fields visible only for `mode=single`.
- [x] Require `supervisor_llm_key_id`, `supervisor_llm_model`, and `supervisor_prompt` for supervisor agents.
- [x] Keep supervisor fields hidden and not required for single agents.

### Task 2: Specialist relation manager runtime readiness

- [x] Add tests proving a specialist requires `role_prompt`, `llm_key_id`, `llm_model`, and `confidence_threshold`.
- [x] Improve field grouping and table columns so specialist readiness is visible.
- [x] Ensure `workspace_id` continues to be injected from the current Filament tenant.
- [x] Ensure LLM key options remain scoped to the current workspace and active keys only.

### Task 3: Manual-admin runtime path coverage

- [x] Add a feature test that creates a supervisor agent and specialist with the same fields the admin UI requires.
- [x] Assert `AgentRuntimeClient` sends supervisor and specialist provider/model/key fields to Python.
- [x] Assert empty object fields serialize as JSON objects, not arrays.
- [x] Add an Edit Agent action that runs a runtime smoke from an admin-configured agent without `tinker`.

### Task 4: Verification

- [x] Run `./vendor/bin/pint --dirty --format agent`.
- [x] Run `php artisan test --compact --filter=AgentSupervisorAdminUxTest`.
- [x] Run `php artisan test --compact --filter=AgentSupervisorRuntimePayloadTest`.
- [x] Run `./vendor/bin/phpstan analyse --memory-limit=1G --no-progress`.
