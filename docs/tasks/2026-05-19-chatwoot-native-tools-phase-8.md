# Chatwoot Native Tools Phase 8 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Promote common Chatwoot operations into first-class native Oryntra tools and make human handoff side effects asynchronous, retryable, and configurable from Filament.

**Architecture:** Laravel remains the only service that talks to Chatwoot. Python only requests high-level tools through Laravel or receives handoff config in the runtime payload. `request_human_handoff` records the durable state change synchronously, then dispatches a Horizon job to apply Chatwoot side effects: customer message, private note, label, team assignment, and agent assignment.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Horizon queues, Laravel HTTP Client, Pest, Postgres jsonb, FastAPI/Pydantic, LangGraph.

---

## Current State

- `request_human_handoff` already exists as an internal Laravel gateway endpoint.
- `agent_run.status` is already marked `waiting_human`.
- `ChatwootAgentBotClient` currently supports:
  - `sendConversationMessage()`
  - `addConversationLabel()`
- The current handoff applies Chatwoot side effects synchronously inside `RequestHumanHandoff`.
- Specialist handoff rules are configurable through `agent_specialists.handoff_config`.
- `AgentChatwootBinding` already has `handoff_label_name`, but no target team/agent/private-note config yet.

## Target Native Tools

These are internal Oryntra tools, available in code without requiring users to create them manually:

- `request_human_handoff`
- `chatwoot_send_message`
- `chatwoot_add_private_note`
- `chatwoot_add_label`
- `chatwoot_assign_team`
- `chatwoot_assign_agent`
- `chatwoot_list_teams`
- `chatwoot_list_agents`
- `chatwoot_set_status`

This phase implements only the tools needed for handoff side effects:

- `chatwoot_send_message`
- `chatwoot_add_private_note`
- `chatwoot_add_label`
- `chatwoot_assign_team`
- `chatwoot_assign_agent`

`chatwoot_list_teams`, `chatwoot_list_agents`, and `chatwoot_set_status` are deferred unless needed to populate selects during this phase.

## Chatwoot API Calls

Known calls already implemented:

```http
POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/messages
api_access_token: <agent bot token>
```

Customer message body:

```json
{
  "content": "Vou transferir voce para um atendente.",
  "message_type": "outgoing",
  "private": false
}
```

Private note body:

```json
{
  "content": "Resumo interno do handoff...",
  "message_type": "outgoing",
  "private": true
}
```

Label body:

```http
POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/labels
```

```json
{
  "labels": ["human_handoff"]
}
```

Assignment calls must be verified against the local/target Chatwoot version before implementation. Expected candidates:

```http
POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/assignments
```

Team assignment likely body:

```json
{
  "team_id": 12
}
```

Agent assignment likely body:

```json
{
  "assignee_id": 34
}
```

Before implementing assignment, verify the exact Chatwoot API route and payload from the project’s Chatwoot version or local API docs.

## Data Model

### `agent_chatwoot_bindings`

Add handoff destination/config fields:

- `handoff_team_id` nullable integer
- `handoff_team_name` nullable text
- `handoff_agent_id` nullable integer
- `handoff_agent_name` nullable text
- `handoff_private_note_template` nullable text
- `handoff_assign_strategy` text default `none`
  - `none`
  - `team`
  - `agent`
  - `team_then_agent`

Why on binding:

- Handoff destination depends on the Chatwoot account/connection.
- The same agent can be bound differently per Chatwoot connection later.
- Existing `handoff_label_name` already lives here.

### `agent_runs.output.handoff`

Extend output with side-effect status:

```json
{
  "handoff": {
    "reason": "Cliente pediu cancelamento.",
    "priority": "high",
    "suggested_team": "suporte",
    "customer_message": "Vou transferir voce para um atendente.",
    "private_note": "Resumo interno...",
    "side_effects": {
      "status": "queued",
      "job_id": null,
      "attempted_at": null,
      "completed_at": null,
      "failed_at": null,
      "error": null,
      "actions": {
        "customer_message": "pending",
        "private_note": "pending",
        "label": "pending",
        "team_assignment": "pending",
        "agent_assignment": "pending"
      }
    }
  }
}
```

## Task 1: Migration And Model Fields

**Files:**
- Create: `laravel/database/migrations/YYYY_MM_DD_HHMMSS_add_handoff_destination_to_agent_chatwoot_bindings_table.php`
- Modify: `laravel/app/Models/AgentChatwootBinding.php`
- Modify: `laravel/database/factories/AgentChatwootBindingFactory.php`
- Test: `laravel/tests/Feature/AgentChatwootBindingTest.php`

- [x] Add migration:

```php
Schema::table('agent_chatwoot_bindings', function (Blueprint $table) {
    $table->unsignedBigInteger('handoff_team_id')->nullable();
    $table->text('handoff_team_name')->nullable();
    $table->unsignedBigInteger('handoff_agent_id')->nullable();
    $table->text('handoff_agent_name')->nullable();
    $table->text('handoff_private_note_template')->nullable();
    $table->text('handoff_assign_strategy')->default('none');
});
```

- [x] Add PostgreSQL check constraint:

```php
DB::statement("ALTER TABLE agent_chatwoot_bindings ADD CONSTRAINT agent_chatwoot_bindings_handoff_assign_strategy_check CHECK (handoff_assign_strategy IN ('none', 'team', 'agent', 'team_then_agent'))");
```

- [x] Add fields to `AgentChatwootBinding` fillable.
- [x] Add casts:

```php
'handoff_team_id' => 'integer',
'handoff_agent_id' => 'integer',
```

- [x] Add factory defaults:

```php
'handoff_team_id' => null,
'handoff_team_name' => null,
'handoff_agent_id' => null,
'handoff_agent_name' => null,
'handoff_private_note_template' => null,
'handoff_assign_strategy' => 'none',
```

- [x] Add test asserting fields persist and cast correctly.

Run:

```bash
cd /home/anderson/Oryntra/laravel
php artisan test --compact --filter=AgentChatwootBindingTest
```

Expected: PASS.

## Task 2: Filament Handoff Destination UI

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/RelationManagers/ChatwootBindingsRelationManager.php`
- Test: `laravel/tests/Feature/AgentSupervisorAdminUxTest.php`

- [x] Add UI fields to `ChatwootBindingsRelationManager`:

```php
Select::make('handoff_assign_strategy')
    ->label('Destino do handoff')
    ->options([
        'none' => 'Sem atribuicao automatica',
        'team' => 'Atribuir para time',
        'agent' => 'Atribuir para atendente',
        'team_then_agent' => 'Atribuir para time e atendente',
    ])
    ->default('none')
    ->live()
    ->required(),
TextInput::make('handoff_team_id')
    ->label('ID do time Chatwoot')
    ->numeric()
    ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['team', 'team_then_agent'], true)),
TextInput::make('handoff_team_name')
    ->label('Nome do time')
    ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['team', 'team_then_agent'], true)),
TextInput::make('handoff_agent_id')
    ->label('ID do atendente Chatwoot')
    ->numeric()
    ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['agent', 'team_then_agent'], true)),
TextInput::make('handoff_agent_name')
    ->label('Nome do atendente')
    ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['agent', 'team_then_agent'], true)),
Textarea::make('handoff_private_note_template')
    ->label('Nota interna para atendente')
    ->rows(4)
    ->helperText('Use {reason}, {priority}, {specialist_id}, {conversation_id} e {customer_message}.')
    ->columnSpanFull(),
```

- [x] Add table columns for destination:

```php
TextColumn::make('handoff_assign_strategy')->label('Destino')->badge();
TextColumn::make('handoff_team_name')->label('Time')->placeholder('-');
TextColumn::make('handoff_agent_name')->label('Atendente')->placeholder('-');
```

- [x] Add feature test creating/editing a binding with team/agent/private note config.

Run:

```bash
php artisan test --compact --filter=AgentSupervisorAdminUxTest
```

Expected: PASS.

## Task 3: Native Chatwoot Tool Registry

**Files:**
- Create: `laravel/app/Services/AgentTools/NativeTool.php`
- Create: `laravel/app/Services/AgentTools/NativeToolRegistry.php`
- Test: `laravel/tests/Feature/AgentTools/NativeToolRegistryTest.php`

- [x] Create enum:

```php
enum NativeTool: string
{
    case RequestHumanHandoff = 'request_human_handoff';
    case ChatwootSendMessage = 'chatwoot_send_message';
    case ChatwootAddPrivateNote = 'chatwoot_add_private_note';
    case ChatwootAddLabel = 'chatwoot_add_label';
    case ChatwootAssignTeam = 'chatwoot_assign_team';
    case ChatwootAssignAgent = 'chatwoot_assign_agent';
}
```

- [x] Create registry with labels and descriptions for UI/runtime:

```php
final class NativeToolRegistry
{
    /**
     * @return array<string, array{label:string, description:string}>
     */
    public function tools(): array
    {
        return [
            NativeTool::RequestHumanHandoff->value => [
                'label' => 'Transferir para humano',
                'description' => 'Pausa a IA e solicita atendimento humano.',
            ],
            NativeTool::ChatwootSendMessage->value => [
                'label' => 'Enviar mensagem Chatwoot',
                'description' => 'Envia uma mensagem publica ao cliente.',
            ],
            NativeTool::ChatwootAddPrivateNote->value => [
                'label' => 'Adicionar nota interna',
                'description' => 'Adiciona mensagem privada para o atendente.',
            ],
            NativeTool::ChatwootAddLabel->value => [
                'label' => 'Adicionar label',
                'description' => 'Adiciona label na conversa.',
            ],
            NativeTool::ChatwootAssignTeam->value => [
                'label' => 'Atribuir time',
                'description' => 'Atribui a conversa a um time Chatwoot.',
            ],
            NativeTool::ChatwootAssignAgent->value => [
                'label' => 'Atribuir atendente',
                'description' => 'Atribui a conversa a um atendente Chatwoot.',
            ],
        ];
    }
}
```

- [x] Update `SpecialistsRelationManager` tools selector to use registry options instead of free-form tags for native tools.

Run:

```bash
php artisan test --compact --filter=NativeToolRegistryTest
```

Expected: PASS.

## Task 4: Expand Chatwoot Agent Bot Client

**Files:**
- Modify: `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php`
- Test: `laravel/tests/Feature/Chatwoot/ChatwootAgentBotClientTest.php`

- [x] Add `addPrivateNote()`:

```php
public function addPrivateNote(int $conversationId, string $content): void
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->post($this->url("conversations/{$conversationId}/messages"), [
            'content' => $content,
            'message_type' => 'outgoing',
            'private' => true,
        ]);

    if ($response->failed()) {
        throw new RuntimeException("Chatwoot addPrivateNote({$conversationId}) failed: HTTP {$response->status()}");
    }
}
```

- [x] Add `assignTeam()` after verifying route/payload:

```php
public function assignTeam(int $conversationId, int $teamId): void
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->post($this->url("conversations/{$conversationId}/assignments"), [
            'team_id' => $teamId,
        ]);

    if ($response->failed()) {
        throw new RuntimeException("Chatwoot assignTeam({$conversationId}) failed: HTTP {$response->status()}");
    }
}
```

- [x] Add `assignAgent()` after verifying route/payload:

```php
public function assignAgent(int $conversationId, int $agentId): void
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->post($this->url("conversations/{$conversationId}/assignments"), [
            'assignee_id' => $agentId,
        ]);

    if ($response->failed()) {
        throw new RuntimeException("Chatwoot assignAgent({$conversationId}) failed: HTTP {$response->status()}");
    }
}
```

- [x] Add tests with `Http::fake()` asserting URL, token header, and payload for:
  - public message;
  - private note;
  - label;
  - team assignment;
  - agent assignment.

Run:

```bash
php artisan test --compact --filter=ChatwootAgentBotClientTest
```

Expected: PASS.

## Task 5: Handoff Side Effects Job

**Files:**
- Create: `laravel/app/Jobs/Agent/ApplyHumanHandoffToChatwootJob.php`
- Modify: `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`
- Test: `laravel/tests/Feature/AgentTools/ApplyHumanHandoffToChatwootJobTest.php`
- Test: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`

- [x] Create job:

```php
class ApplyHumanHandoffToChatwootJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct(public int $agentRunId) {}

    public function handle(): void
    {
        $run = AgentRun::query()
            ->with('chatwootConnection.activeAgentBinding')
            ->findOrFail($this->agentRunId);

        // Apply customer message, private note, label, team assignment, agent assignment.
    }

    public function failed(Throwable $exception): void
    {
        // Store handoff.side_effects.failed_at and sanitized error in agent_runs.output.
    }
}
```

- [x] Move Chatwoot side effects out of `RequestHumanHandoff`.
- [x] In `RequestHumanHandoff`, after committing `waiting_human`, dispatch:

```php
ApplyHumanHandoffToChatwootJob::dispatch($run->id)->afterCommit();
```

- [x] Store `handoff.side_effects.status = queued`.
- [x] In the job, update side effect action statuses:
  - `skipped`
  - `completed`
  - `failed`
- [x] If one Chatwoot action fails, throw so Horizon retries the full job. Keep operations idempotent enough:
  - sending duplicate customer/private messages is the main risk;
  - use action status in `agent_runs.output.handoff.side_effects.actions` to skip already completed actions on retry.

Run:

```bash
php artisan test --compact --filter='RequestHumanHandoffToolTest|ApplyHumanHandoffToChatwootJobTest'
```

Expected: PASS.

## Task 6: Private Note Rendering

**Files:**
- Create: `laravel/app/Support/AgentTools/HandoffPrivateNoteRenderer.php`
- Test: `laravel/tests/Unit/HandoffPrivateNoteRendererTest.php`

- [x] Renderer input:

```php
[
    'reason' => 'Cliente pediu cancelamento.',
    'priority' => 'high',
    'specialist_id' => 5,
    'conversation_id' => 99,
    'customer_message' => 'Vou transferir voce para um atendente.',
]
```

- [x] Supported tokens:
  - `{reason}`
  - `{priority}`
  - `{specialist_id}`
  - `{conversation_id}`
  - `{customer_message}`

- [x] Default template:

```text
Handoff solicitado pela IA

Motivo: {reason}
Prioridade: {priority}
Especialista: {specialist_id}
Conversa: {conversation_id}

Mensagem ao cliente:
{customer_message}
```

- [x] Add unit tests for default template and custom template.

Run:

```bash
php artisan test --compact --filter=HandoffPrivateNoteRendererTest
```

Expected: PASS.

## Task 7: Python Contract Alignment

**Files:**
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`
- Modify: `agent-python/src/oryntra_agent/agent/supervisor.py`
- Modify: `agent-python/tests/test_human_handoff_tool.py`

- [x] Extend `HandoffConfig` if needed:

```python
private_note: str | None = None
assign_strategy: Literal["none", "team", "agent", "team_then_agent"] = "none"
team_id: int | None = None
agent_id: int | None = None
```

- [x] Keep Python high-level: it should request `request_human_handoff`, not call individual Chatwoot tools directly during this phase.
- [x] Include private-note intent fields in the handoff request only if Laravel accepts them in `RequestHumanHandoffRequest`.
- [x] Add tests proving Python still only calls the Laravel handoff gateway.

Run:

```bash
cd /home/anderson/Oryntra/agent-python
uv run pytest tests/test_human_handoff_tool.py -q
uv run ruff check .
uv run mypy src/
```

Expected: PASS.

## Task 8: Quality Gates

**Files:**
- All modified files.

- [x] Run Laravel:

```bash
cd /home/anderson/Oryntra/laravel
./vendor/bin/pint --dirty --format agent
php artisan test --compact
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: PASS.

- [x] Run Python:

```bash
cd /home/anderson/Oryntra/agent-python
uv run ruff check .
uv run mypy src/
uv run pytest
```

Expected: PASS.

## Deferred

- Persisted generic `agent_tools` table.
- User-created external tools.
- MCP tools.
- RAG tools.
- Periodic sync tables for Chatwoot teams/agents.
- Select fields populated automatically from Chatwoot teams/agents. For this phase, numeric IDs plus display names are acceptable.

## Self-Review

- Spec coverage: the plan covers native Chatwoot tools, private notes, team/agent assignment, async job retry, front configuration, and Python contract alignment.
- Placeholder scan: assignment API route must be verified before implementation because Chatwoot versions may differ; this is an explicit verification step, not an implementation placeholder.
- Type consistency: the plan consistently uses `request_human_handoff`, `chatwoot_add_private_note`, `handoff_assign_strategy`, `handoff_team_id`, `handoff_agent_id`, and `ApplyHumanHandoffToChatwootJob`.
