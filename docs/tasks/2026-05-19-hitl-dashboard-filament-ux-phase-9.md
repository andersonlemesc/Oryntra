# HITL + Dashboard + Filament UX Phase 9 Implementation Plan

> **For agentic workers:** Use checkbox (`- [ ]`) syntax for tracking. Implement task-by-task. Do not skip ahead.

**Goal:** Close the human-in-the-loop loop (list, approve, edit, reject `waiting_human` runs), give the panel a real dashboard with operational widgets, and rework the Agent edit screen so configuration fields are grouped into legible tabs instead of a long scroll of collapsed sections.

**Architecture:** Filament 5 panel keeps tenancy. A new `AgentRunResource` exposes runs scoped by workspace, with an Activity view and HITL actions that hit Laravel-only services (Python is untouched in this phase except for the resume contract, which is a placeholder). The dashboard lives at the panel root as widgets. The Agent form is restructured into `Tabs` with the same underlying fields; no schema changes.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5 (Schemas, Tabs, Widgets), Horizon, Pest, Postgres jsonb.

---

## Current State

- `AgentRun.status` already supports `waiting_human`, `completed`, `failed`, `running`, `debouncing`.
- `agent_runs.output.handoff` carries reason/priority/customer_message + `side_effects` block (Phase 8).
- No `AgentRunResource` exists. Runs can only be inspected via SQL/Telescope.
- No Filament dashboard widgets exist. Panel root is the default Filament dashboard.
- `AgentForm` is one long schema of stacked `Section`s — Identity, Supervisor, single-LLM, Prompts, Debounce, Media, Guards, RAG, Runtime — collapsed sections create high visual weight without grouping by intent.
- `SpecialistsRelationManager` form is also a single column of sections; the handoff repeater is dense.
- `ChatwootBindingsRelationManager` (Phase 8) has the new handoff destination fields but they are stacked with no visual grouping.
- No resume endpoint for HITL exists. Python checkpointer is in place (LangGraph), but Laravel has no `/internal/agent-runs/{id}/resume` route yet.

## Out Of Scope For This Phase

- Python-side resume implementation (`Command(resume=...)` wiring inside LangGraph). This phase adds the Laravel endpoint and stub call; Python wiring is the next phase.
- Trace timeline UI. Only a JSON viewer + summary metadata in this phase.
- RAG, vision, audio tools.
- Cost/billing widgets requiring token accounting beyond what `agent_runs.output.usage` already stores.

## Task 1: AgentRunResource Skeleton

**Files:**
- Create: `laravel/app/Filament/Resources/AgentRuns/AgentRunResource.php`
- Create: `laravel/app/Filament/Resources/AgentRuns/Pages/ListAgentRuns.php`
- Create: `laravel/app/Filament/Resources/AgentRuns/Pages/ViewAgentRun.php`
- Create: `laravel/app/Filament/Resources/AgentRuns/Tables/AgentRunsTable.php`
- Create: `laravel/app/Filament/Resources/AgentRuns/Schemas/AgentRunInfolist.php`
- Modify: `laravel/app/Providers/Filament/AdminPanelProvider.php` (or whichever panel provider is registered)
- Test: `laravel/tests/Feature/Filament/AgentRunResourceTest.php`

- [ ] Generate resource scaffold:

```bash
cd /home/anderson/Oryntra/laravel
php artisan make:filament-resource AgentRun --no-interaction --view --generate
```

- [ ] Register tenancy on the resource so it is scoped to the active workspace (mirror `AgentResource`'s tenant ownership relationship).
- [ ] Make the resource read-only at the list/view level. Disable `CreateAction` and bulk delete; keep only `ViewAction` plus the HITL actions added in Task 3.
- [ ] Title attribute: `thread_id`. Navigation group: `Agentes`. Navigation label: `Execucoes`.

- [ ] Configure table columns:

```php
TextColumn::make('id')->label('#')->sortable(),
TextColumn::make('agent.name')->label('Agente')->searchable(),
TextColumn::make('status')
    ->label('Status')
    ->badge()
    ->formatStateUsing(fn (AgentRunStatus|string $state): string => ($state instanceof AgentRunStatus ? $state : AgentRunStatus::from($state))->label())
    ->color(fn (AgentRunStatus|string $state): string => match ($state instanceof AgentRunStatus ? $state : AgentRunStatus::from($state)) {
        AgentRunStatus::Completed => 'success',
        AgentRunStatus::WaitingHuman => 'warning',
        AgentRunStatus::Failed => 'danger',
        AgentRunStatus::Running => 'info',
        default => 'gray',
    }),
TextColumn::make('conversation_id')->label('Conversa')->searchable(),
TextColumn::make('started_at')->label('Iniciou')->dateTime()->since()->sortable(),
TextColumn::make('finished_at')->label('Finalizou')->dateTime()->since()->sortable()->toggleable(),
```

- [ ] Configure filters:

```php
SelectFilter::make('status')->options(AgentRunStatus::options()),
SelectFilter::make('agent_id')->relationship('agent', 'name'),
Filter::make('waiting_human')->query(fn (Builder $q) => $q->where('status', AgentRunStatus::WaitingHuman))->label('So aguardando humano')->toggle(),
```

- [ ] Configure default sort: `started_at` desc.

- [ ] Feature test asserts:
  - List page renders for a workspace member.
  - Filter `waiting_human` returns only `waiting_human` runs.
  - Runs from other workspaces are not visible.

Run:

```bash
cd /home/anderson/Oryntra/laravel
php artisan test --compact --filter=AgentRunResourceTest
```

Expected: PASS.

## Task 2: AgentRun Infolist (View Page)

**Files:**
- Modify: `laravel/app/Filament/Resources/AgentRuns/Schemas/AgentRunInfolist.php`
- Test: same as Task 1 (extend).

- [ ] Build the view page using Filament Infolist Sections and Tabs:

```text
Tabs
├── Resumo
│   ├── Section "Identidade" — agent, status badge, thread_id, conversation_id, debounce window
│   └── Section "Tempo" — started_at, finished_at, duration, debounce_started_at, debounce_until
├── Handoff
│   ├── Section "Pedido" — reason, priority, suggested_team, customer_message, private_note
│   └── Section "Side effects" — actions matrix (customer_message/private_note/label/team/agent) with status badges
├── Trace bruto
│   └── KeyValue/JSON viewer of agent_runs.output (read-only)
└── Erros
    └── Section "Falha" — error_message + side_effects.error (only visible when status = failed)
```

- [ ] Render handoff side-effect action statuses as colored badges (`pending`=gray, `queued`=info, `completed`=success, `failed`=danger, `skipped`=warning).
- [ ] Hide Handoff tab entirely when `output.handoff` is null.

- [ ] Extend feature test to assert:
  - View page renders for a `waiting_human` run with handoff payload.
  - Side-effect action badges are present.

Run:

```bash
php artisan test --compact --filter=AgentRunResourceTest
```

Expected: PASS.

## Task 3: HITL Actions On AgentRunResource

**Files:**
- Modify: `laravel/app/Filament/Resources/AgentRuns/Tables/AgentRunsTable.php`
- Modify: `laravel/app/Filament/Resources/AgentRuns/Pages/ViewAgentRun.php`
- Create: `laravel/app/Actions/AgentRuns/ApproveAgentRun.php`
- Create: `laravel/app/Actions/AgentRuns/RejectAgentRun.php`
- Create: `laravel/app/Actions/AgentRuns/EditAgentRunResponse.php`
- Test: `laravel/tests/Feature/AgentRuns/ApproveAgentRunTest.php`
- Test: `laravel/tests/Feature/AgentRuns/RejectAgentRunTest.php`
- Test: `laravel/tests/Feature/AgentRuns/EditAgentRunResponseTest.php`

- [ ] Approve action (visible only for `waiting_human`):
  - Confirms with modal.
  - Calls `ApproveAgentRun` action: marks `status = completed`, sets `finished_at`, stores `output.hitl = { decision: 'approved', actor_id, decided_at }`.
  - Triggers a placeholder Python resume call via a new `AgentRuntimeClient::resume(int $agentRunId, string $decision, ?string $editedMessage)` method. For this phase the client just logs + posts to `/internal/runtime/resume` (real Python wiring deferred). Use Laravel HTTP client with `Http::fake()` in tests.

- [ ] Reject action (visible only for `waiting_human`):
  - Confirms with modal + required reason text input.
  - Calls `RejectAgentRun`: marks `status = failed`, stores `output.hitl = { decision: 'rejected', actor_id, decided_at, reason }`, dispatches no Chatwoot side effects.

- [ ] Edit-and-approve action (visible only for `waiting_human` with `output.response.content`):
  - Modal with Textarea pre-filled from `output.response.content`.
  - On submit calls `EditAgentRunResponse`: replaces `output.response.content`, stores `output.hitl = { decision: 'edited', actor_id, decided_at, original_content }`, then runs the same approve path.

- [ ] Add these actions both on the table row and on the view page header.

- [ ] Workspace authorization: each action must verify the run belongs to the active tenant. Add a policy method `update` on `AgentRunPolicy` (create policy if it does not exist) and register it.

- [ ] Tests assert:
  - Approve transitions status, writes `hitl` block, calls runtime client once.
  - Reject transitions to `failed`, stores reason, does not call runtime client.
  - Edit-and-approve mutates response content and preserves original under `output.hitl.original_content`.
  - Cross-workspace approval is forbidden (403).

Run:

```bash
php artisan test --compact --filter='ApproveAgentRunTest|RejectAgentRunTest|EditAgentRunResponseTest'
```

Expected: PASS.

## Task 4: Resume Endpoint Contract

**Files:**
- Create: `laravel/routes/internal.php` entry (or matching existing internal route file)
- Create: `laravel/app/Http/Controllers/Internal/AgentRunResumeController.php`
- Modify: `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php` (add `resume()` method)
- Test: `laravel/tests/Feature/Internal/AgentRunResumeControllerTest.php`

- [ ] Add internal route:

```php
Route::post('/internal/agent-runs/{agentRun}/resume', AgentRunResumeController::class)
    ->middleware(['internal.auth']);
```

- [ ] `AgentRunResumeController` accepts:

```json
{
  "decision": "approved" | "rejected" | "edited",
  "response_content": "string|null",
  "reason": "string|null",
  "actor_id": "int|null"
}
```

- [ ] Controller is idempotent: if the run is already `completed` or `failed`, return the current state with `200` and `idempotent: true`.

- [ ] `AgentRuntimeClient::resume()` posts to a Python endpoint placeholder URL from config (`services.agent_runtime.url` + `/v1/runs/{id}/resume`). Python implementation is deferred; this method only needs to be testable via `Http::fake()` and tolerate non-200 responses by logging + throwing a typed exception caught by the calling action.

- [ ] Tests assert:
  - Endpoint authentication via existing internal middleware.
  - Idempotent behavior for terminal states.
  - 422 on missing `decision`.

Run:

```bash
php artisan test --compact --filter=AgentRunResumeControllerTest
```

Expected: PASS.

## Task 5: Dashboard Widgets

**Files:**
- Create: `laravel/app/Filament/Widgets/AgentRunStatsOverview.php`
- Create: `laravel/app/Filament/Widgets/WaitingHumanRunsTable.php`
- Create: `laravel/app/Filament/Widgets/RecentFailedRunsTable.php`
- Create: `laravel/app/Filament/Widgets/RunsThroughputChart.php`
- Modify: panel provider to register widgets on the dashboard.
- Test: `laravel/tests/Feature/Filament/DashboardWidgetsTest.php`

- [ ] `AgentRunStatsOverview` (StatsOverviewWidget) cards, all scoped to active tenant for the last 24h:
  - Total runs
  - Completed runs (with delta vs previous 24h)
  - Waiting human (badge color warning if > 0)
  - Failed runs (badge color danger if > 0)

- [ ] `WaitingHumanRunsTable` (TableWidget): same query as the resource's `waiting_human` filter, columns: agent, conversation, started_at (relative), priority (from `output.handoff.priority`). Action button: `View` linking to the resource view page.

- [ ] `RecentFailedRunsTable` (TableWidget): latest 10 `failed` runs in last 7 days with `error_message` truncated.

- [ ] `RunsThroughputChart` (ChartWidget, line): runs per hour for the last 24h grouped by status (`completed`, `failed`, `waiting_human`). Use Postgres `date_trunc('hour', started_at)` with `started_at >= now() - interval '24 hours'`.

- [ ] Sort widgets on the dashboard so stats overview is first, waiting-human is second (full width), the chart is third, recent failures last.

- [ ] Tenancy: every widget filters by `workspace_id = Filament::getTenant()->getKey()`. Cross-workspace data must not leak.

- [ ] Tests assert:
  - Dashboard page renders 200 for an authenticated tenant member.
  - Stats counts match seeded data.
  - Widgets do not include data from other workspaces.

Run:

```bash
php artisan test --compact --filter=DashboardWidgetsTest
```

Expected: PASS.

## Task 6: Agent Edit Screen — Tabs Layout

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/Schemas/AgentForm.php`
- Modify: `laravel/tests/Feature/AgentSupervisorAdminUxTest.php`

Goal: replace the long stack of collapsed Sections with a `Tabs` schema. No field renames or schema changes.

- [ ] Replace the top-level `->components([...])` content with a single `Tabs::make('config')`:

```php
Tabs::make('config')
    ->columnSpanFull()
    ->persistTabInQueryString()
    ->tabs([
        Tabs\Tab::make('Geral')
            ->icon('heroicon-o-identification')
            ->schema([
                /* Identidade section moved here, kept 2 columns */
                /* Prompts section moved here */
            ]),
        Tabs\Tab::make('Modelo')
            ->icon('heroicon-o-cpu-chip')
            ->schema([
                /* Supervisor section + LLM do agente unico section */
                /* Existing visibility rules on mode are preserved */
            ]),
        Tabs\Tab::make('Comportamento')
            ->icon('heroicon-o-shield-check')
            ->schema([
                /* Guards section + RAG section + Politica de midia section */
            ]),
        Tabs\Tab::make('Execucao')
            ->icon('heroicon-o-bolt')
            ->schema([
                /* Debounce section + Runtime section */
            ]),
    ]);
```

- [ ] Inside each tab, remove `->collapsed()`/`->collapsible()` from inner sections. The tabs themselves replace the collapse affordance.
- [ ] Keep helper text where it already exists. Do not introduce new fields.
- [ ] Preserve all existing `visible(fn (Get $get))` rules verbatim.

- [ ] Extend `AgentSupervisorAdminUxTest` (or add a new `AgentFormTabsTest`) to assert each tab name renders and that switching `mode` between `single` and `supervisor` still hides/shows the correct LLM blocks.

Run:

```bash
php artisan test --compact --filter='AgentSupervisorAdminUxTest|AgentFormTabsTest'
```

Expected: PASS.

## Task 7: Specialists Form — Two-Column Restructure

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/RelationManagers/SpecialistsRelationManager.php`
- Modify: existing tests covering specialist edits.

- [ ] Wrap the four current sections in a `Tabs`:

```text
Tabs
├── Identidade
├── LLM
├── Roteamento e ferramentas
└── Transferencia humana (with the rules repeater)
```

- [ ] Within `Transferencia humana`, wrap the repeater in a `Section` with `compact()` styling and reduce the repeater rows by setting `collapsible()` + `itemLabel(fn (array $state) => $state['name'] ?? 'Nova regra')`.

- [ ] Adjust `Repeater::make('handoff_config.rules')` to use `columns(2)` already in place, but move `customer_message` into a collapsed sub-section to reduce visual noise on rules that do not override it.

- [ ] Tests: keep existing assertions on tools allowlist normalization and add an assertion that creating a specialist still works when navigating into the `Transferencia humana` tab.

Run:

```bash
php artisan test --compact
```

Expected: PASS.

## Task 8: Chatwoot Binding Form — Group Handoff Destination

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/RelationManagers/ChatwootBindingsRelationManager.php`

- [ ] Wrap the existing fields in two `Section`s:
  - `Section "Conexao"` — connection + bot identity fields (existing).
  - `Section "Destino do handoff"` — `handoff_assign_strategy`, `handoff_team_id`/`name`, `handoff_agent_id`/`name`, `handoff_private_note_template`, `handoff_label_name`.
- [ ] Keep all existing `visible()` rules and helpers.
- [ ] No new fields.

Run:

```bash
php artisan test --compact --filter=AgentChatwootBindingTest
```

Expected: PASS.

## Task 9: Panel Branding & Navigation

**Files:**
- Modify: `laravel/app/Providers/Filament/AdminPanelProvider.php` (or equivalent)
- Modify: `laravel/config/filament.php` if present

- [ ] Set a primary color via `->colors(['primary' => Color::Indigo])` (or whichever matches the brand). Do not invent a hex — use Filament's `Color` palette.
- [ ] Set `->brandName('Oryntra')` and `->favicon(asset('favicon.ico'))` if an asset exists; otherwise skip favicon.
- [ ] Group navigation: `Agentes` (agents, runs, llm keys), `Conexoes` (chatwoot connections), `Configuracoes` (chatwoot platform settings).
- [ ] Enable `->sidebarCollapsibleOnDesktop()` and `->sidebarFullyCollapsibleOnDesktop()` so dense forms have more horizontal room.
- [ ] Do not change auth, tenancy or middleware setup.

- [ ] Smoke test: load `/admin` and the dashboard URL for a seeded user; assert 200 and that the new nav group labels appear.

Run:

```bash
php artisan test --compact
```

Expected: PASS.

## Task 10: Quality Gates

**Files:** all modified files.

- [ ] Pint:

```bash
cd /home/anderson/Oryntra/laravel
./vendor/bin/pint --dirty --format agent
```

- [ ] Full Laravel test suite:

```bash
php artisan test --compact
```

- [ ] Static analysis:

```bash
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

- [ ] Manual UI walkthrough checklist (record results in PR description):
  - Dashboard renders with all four widgets.
  - Stats deltas update after creating a synthetic run.
  - Approve action on a `waiting_human` run transitions to `completed` and the row disappears from the waiting-human widget.
  - Reject action sets `failed` with reason visible in the view page.
  - Agent edit page now uses tabs; switching `mode` toggles the correct LLM tab content.
  - Specialist edit page uses tabs; the handoff rules repeater is readable.
  - Chatwoot binding edit page shows the two grouped sections.

## Deferred

- Python `Command(resume=...)` wiring inside LangGraph.
- Trace timeline UI with step-by-step replay.
- Token-cost breakdown widget (requires consistent `output.usage` from runtime).
- Drag-and-drop priority ordering for specialists.
- Notifications/Slack on new `waiting_human` runs.
- Bulk approve/reject.
- Localization strings extracted to lang files.

## Self-Review

- Spec coverage: HITL list/approve/edit/reject + dashboard widgets + Agent/Specialist/Binding form restructure + panel branding. Matches user request "atencao no front", "dashboard", "agente esta mais estranho".
- Placeholder scan: the runtime resume HTTP call is intentionally a stub (`AgentRuntimeClient::resume`) because Python wiring is out of scope; this is documented under Deferred.
- Type consistency: uses `AgentRunStatus`, `output.handoff`, `output.hitl` consistently. No new schema columns introduced; everything fits in existing jsonb `output`.
- No new tools or migrations; this phase is pure UX + a small read/write controller. Safe to ship behind the existing tenant guard.
