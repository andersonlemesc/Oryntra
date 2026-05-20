# Human Handoff Tool Phase 7 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add the first real internal tool, `request_human_handoff`, so a supervisor specialist can pause automation and ask Laravel/Chatwoot for human intervention.

**Architecture:** Python remains the private LangGraph runtime and never calls Chatwoot directly. Laravel exposes a private internal tool gateway protected by `X-Internal-Token`, validates tenancy against `agent_runs.workspace_id`, records the handoff in `agent_runs.output.trace`, marks the run as `waiting_human`, and optionally performs Chatwoot side effects such as sending a transfer message or adding a label. This phase implements one fixed internal tool first; the generic `agent_tools` table/registry can come later when a second or third tool exists.

**Tech Stack:** Laravel 13, PHP 8.4, Laravel HTTP Client, Pest, FastAPI, LangGraph, Pydantic v2, httpx, pytest, ruff, mypy.

---

## Current Context

- Python endpoint already exists: `POST /internal/chatwoot/messages`.
- Laravel runtime client already sends supervisor and specialist config to Python.
- `AgentRunStatus::WaitingHuman` already exists.
- Python contract already supports `response.type = "escalate"` and `status = "waiting_human"`.
- `SpecialistConfig.tools` already exists in Python and maps from Laravel specialist allowlist.
- `agent_runs.output` stores full runtime response and trace.
- Python must not call Chatwoot, MinIO, or Laravel business tables directly. All side effects go through Laravel.

## Runtime Behavior

1. A specialist decides it needs human intervention.
2. Python verifies `request_human_handoff` is present in the selected specialist allowlist.
3. Python calls Laravel internal gateway:

```http
POST /api/internal/agent-tools/request-human-handoff
X-Internal-Token: <services.agent_runtime.internal_token>
Content-Type: application/json
```

4. Laravel validates the token, request shape, tenancy, run ownership, and optional specialist ownership.
5. Laravel updates the `agent_run` to `waiting_human` and appends `tool_call` / `tool_result` trace entries.
6. Laravel may perform Chatwoot side effects from safe local config:
   - add a handoff label when configured;
   - send a customer-facing transfer message when `customer_message` is present;
   - never expose internal reason text directly unless explicitly provided as customer message.
7. Python returns the normal runtime response with `status = "waiting_human"` and `response.type = "escalate"`.

## File Map

### Laravel

- Create: `laravel/app/Http/Middleware/VerifyInternalRuntimeToken.php`
  - Verifies `X-Internal-Token` against `services.agent_runtime.internal_token`.
- Modify: `laravel/bootstrap/app.php`
  - Register route middleware alias `internal.runtime`.
- Modify: `laravel/routes/api.php`
  - Add `POST internal/agent-tools/request-human-handoff`.
- Create: `laravel/app/Http/Requests/Internal/RequestHumanHandoffRequest.php`
  - Validates tool payload.
- Create: `laravel/app/Http/Controllers/Internal/RequestHumanHandoffController.php`
  - Thin controller that delegates to the action.
- Create: `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`
  - Validates model ownership, appends trace, marks run waiting human, performs optional Chatwoot side effects.
- Create: `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php`
  - Sends agent-bot scoped Chatwoot conversation messages and labels using `ChatwootConnection::chatwootHeaders()`.
- Create: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`
  - Feature coverage for auth, tenancy, trace/status mutation, Chatwoot side effects.

### Python

- Modify: `agent-python/src/oryntra_agent/settings.py`
  - Add Laravel internal base URL and token settings.
- Create: `agent-python/src/oryntra_agent/agent/tools.py`
  - Defines handoff request/response models and HTTP client.
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`
  - Add optional handoff config to `runtime_config` only if typed access is needed; otherwise keep generic dict.
- Modify: `agent-python/src/oryntra_agent/agent/supervisor.py`
  - Detect handoff intent, enforce allowlist, call Laravel gateway, include trace and waiting-human response.
- Create: `agent-python/tests/test_human_handoff_tool.py`
  - Unit tests for allowlist, payload, successful waiting-human response, gateway failure fallback.

## Contract

### Python -> Laravel Request

```json
{
  "workspace_id": 1,
  "agent_id": 10,
  "agent_run_id": 55,
  "thread_id": "workspace:1:account:5:conversation:99",
  "conversation_id": 99,
  "specialist_id": 5,
  "reason": "Cliente pediu cancelamento e reembolso.",
  "priority": "normal",
  "suggested_team": "suporte",
  "customer_message": "Vou transferir você para um atendente."
}
```

### Laravel -> Python Response

```json
{
  "status": "waiting_human",
  "handoff_id": 55,
  "message": "Human handoff requested."
}
```

### Python Runtime Response

```json
{
  "status": "waiting_human",
  "response": {
    "type": "escalate",
    "content": "Vou transferir você para um atendente.",
    "document_id": null,
    "handoff_reason": "Cliente pediu cancelamento e reembolso.",
    "confidence": 1
  },
  "specialist_id": 5,
  "trace": [
    {
      "step": 1,
      "type": "runtime_mock",
      "specialist_id": null,
      "tool": null,
      "input": {},
      "output": {},
      "tokens": {"input": 0, "output": 0},
      "latency_ms": 0,
      "ts": "2026-05-19T00:00:00Z"
    },
    {
      "step": 2,
      "type": "supervisor_route",
      "specialist_id": 5,
      "tool": null,
      "input": {},
      "output": {"specialist_id": 5, "reason": "keyword_match"},
      "tokens": {"input": 0, "output": 0},
      "latency_ms": 0,
      "ts": "2026-05-19T00:00:00Z"
    },
    {
      "step": 3,
      "type": "tool_call",
      "specialist_id": 5,
      "tool": "request_human_handoff",
      "input": {"priority": "normal", "suggested_team": "suporte"},
      "output": {"status": "waiting_human", "handoff_id": 55},
      "tokens": {"input": 0, "output": 0},
      "latency_ms": 0,
      "ts": "2026-05-19T00:00:00Z"
    }
  ],
  "usage": {
    "supervisor": {"input_tokens": 0, "output_tokens": 0},
    "specialist": {"input_tokens": 0, "output_tokens": 0},
    "total_cost_cents": 0
  }
}
```

## Task 1: Laravel Internal Runtime Middleware

**Files:**
- Create: `laravel/app/Http/Middleware/VerifyInternalRuntimeToken.php`
- Modify: `laravel/bootstrap/app.php`
- Test: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`

- [x] **Step 1: Search version-specific docs before Laravel edits**

Use Laravel Boost `search-docs` with these queries:

```text
middleware alias
form request validation
http client fake
```

Expected: confirm Laravel 13 middleware alias registration and Pest request testing patterns.

- [x] **Step 2: Create failing auth test**

Run:

```bash
cd /home/anderson/Oryntra/laravel
php artisan make:test --pest AgentTools/RequestHumanHandoffToolTest --no-interaction
```

Put this initial test in `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`:

```php
<?php

use Illuminate\Support\Facades\Config;

it('rejects handoff tool requests without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $this->postJson('/api/internal/agent-tools/request-human-handoff', [])
        ->assertForbidden();
});
```

Run:

```bash
php artisan test --compact --filter=RequestHumanHandoffToolTest
```

Expected: FAIL with `404` until the route exists.

- [x] **Step 3: Add middleware**

Create `laravel/app/Http/Middleware/VerifyInternalRuntimeToken.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalRuntimeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.agent_runtime.internal_token');
        $providedToken = (string) $request->header('X-Internal-Token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
```

Register alias in `laravel/bootstrap/app.php` inside the middleware configuration:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'internal.runtime' => \App\Http\Middleware\VerifyInternalRuntimeToken::class,
    ]);
})
```

If `bootstrap/app.php` already has `withMiddleware`, add only the alias entry and keep existing aliases.

- [x] **Step 4: Add temporary route closure**

Modify `laravel/routes/api.php`:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('internal.runtime')
    ->post('internal/agent-tools/request-human-handoff', function () {
        return response()->json(['status' => 'waiting_human']);
    });
```

Keep existing Chatwoot webhook route intact.

- [x] **Step 5: Verify auth behavior**

Run:

```bash
php artisan test --compact --filter=RequestHumanHandoffToolTest
```

Expected: PASS.

## Task 2: Laravel Handoff Request And Action

**Files:**
- Create: `laravel/app/Http/Requests/Internal/RequestHumanHandoffRequest.php`
- Create: `laravel/app/Http/Controllers/Internal/RequestHumanHandoffController.php`
- Create: `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`
- Modify: `laravel/routes/api.php`
- Test: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`

- [x] **Step 1: Add failing successful-handoff test**

Append to `RequestHumanHandoffToolTest.php`:

```php
use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;

it('marks the agent run waiting human and appends handoff trace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['request_human_handoff']]);
    $run = AgentRun::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'conversation_id' => 99,
            'thread_id' => 'workspace:'.$workspace->id.':account:5:conversation:99',
            'status' => AgentRunStatus::Running,
            'output' => [
                'trace' => [
                    [
                        'step' => 1,
                        'type' => 'runtime_mock',
                        'input' => ['message_count' => 1],
                        'output' => ['response_type' => 'text'],
                    ],
                ],
            ],
        ]);

    $this->withHeader('X-Internal-Token', 'ci-token')
        ->postJson('/api/internal/agent-tools/request-human-handoff', [
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'agent_run_id' => $run->id,
            'thread_id' => $run->thread_id,
            'conversation_id' => 99,
            'specialist_id' => $specialist->id,
            'reason' => 'Cliente pediu cancelamento e reembolso.',
            'priority' => 'normal',
            'suggested_team' => 'suporte',
            'customer_message' => 'Vou transferir você para um atendente.',
        ])
        ->assertOk()
        ->assertJson([
            'status' => 'waiting_human',
            'handoff_id' => $run->id,
            'message' => 'Human handoff requested.',
        ]);

    $run->refresh();

    expect($run->status)->toBe(AgentRunStatus::WaitingHuman)
        ->and($run->output['handoff']['reason'])->toBe('Cliente pediu cancelamento e reembolso.')
        ->and($run->output['trace'])->toHaveCount(3)
        ->and($run->output['trace'][1]['type'])->toBe('tool_call')
        ->and($run->output['trace'][1]['tool'])->toBe('request_human_handoff')
        ->and($run->output['trace'][2]['type'])->toBe('tool_result')
        ->and($run->output['trace'][2]['output']['status'])->toBe('waiting_human');
});
```

Run:

```bash
php artisan test --compact --filter='marks the agent run waiting human'
```

Expected: FAIL because request/action/controller do not exist.

- [x] **Step 2: Create form request**

Create `laravel/app/Http/Requests/Internal/RequestHumanHandoffRequest.php`:

```php
<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestHumanHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'min:1'],
            'agent_id' => ['required', 'integer', 'min:1'],
            'agent_run_id' => ['required', 'integer', 'min:1'],
            'thread_id' => ['required', 'string', 'max:500'],
            'conversation_id' => ['required', 'integer', 'min:1'],
            'specialist_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'suggested_team' => ['nullable', 'string', 'max:120'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [x] **Step 3: Create action**

Create `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`:

```php
<?php

namespace App\Actions\AgentTools;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestHumanHandoff
{
    /**
     * @param  array{
     *     workspace_id:int,
     *     agent_id:int,
     *     agent_run_id:int,
     *     thread_id:string,
     *     conversation_id:int,
     *     specialist_id?:int|null,
     *     reason:string,
     *     priority:string,
     *     suggested_team?:string|null,
     *     customer_message?:string|null
     * }  $payload
     * @return array{status:string,handoff_id:int,message:string}
     */
    public function execute(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $run = AgentRun::query()
                ->where('id', $payload['agent_run_id'])
                ->where('workspace_id', $payload['workspace_id'])
                ->where('agent_id', $payload['agent_id'])
                ->where('thread_id', $payload['thread_id'])
                ->where('conversation_id', $payload['conversation_id'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AgentRun) {
                throw ValidationException::withMessages([
                    'agent_run_id' => 'The agent run does not match the workspace, agent, thread, and conversation.',
                ]);
            }

            $specialistId = $payload['specialist_id'] ?? null;

            if ($specialistId !== null) {
                $this->assertSpecialistCanRequestHandoff($payload, $specialistId);
            }

            $output = is_array($run->output) ? $run->output : [];
            $trace = is_array($output['trace'] ?? null) ? $output['trace'] : [];
            $nextStep = count($trace) + 1;
            $timestamp = Carbon::now()->toISOString();

            $trace[] = [
                'step' => $nextStep,
                'type' => 'tool_call',
                'specialist_id' => $specialistId,
                'tool' => 'request_human_handoff',
                'input' => [
                    'priority' => $payload['priority'],
                    'suggested_team' => $payload['suggested_team'] ?? null,
                    'has_customer_message' => filled($payload['customer_message'] ?? null),
                ],
                'output' => [],
                'tokens' => ['input' => 0, 'output' => 0],
                'latency_ms' => 0,
                'ts' => $timestamp,
            ];

            $trace[] = [
                'step' => $nextStep + 1,
                'type' => 'tool_result',
                'specialist_id' => $specialistId,
                'tool' => 'request_human_handoff',
                'input' => [],
                'output' => [
                    'status' => 'waiting_human',
                    'handoff_id' => $run->id,
                ],
                'tokens' => ['input' => 0, 'output' => 0],
                'latency_ms' => 0,
                'ts' => $timestamp,
            ];

            $output['handoff'] = [
                'reason' => $payload['reason'],
                'priority' => $payload['priority'],
                'suggested_team' => $payload['suggested_team'] ?? null,
                'customer_message' => $payload['customer_message'] ?? null,
                'requested_at' => $timestamp,
            ];
            $output['trace'] = $trace;

            $run->update([
                'status' => AgentRunStatus::WaitingHuman,
                'output' => $output,
                'finished_at' => null,
            ]);

            return [
                'status' => 'waiting_human',
                'handoff_id' => $run->id,
                'message' => 'Human handoff requested.',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSpecialistCanRequestHandoff(array $payload, int $specialistId): void
    {
        $specialist = AgentSpecialist::query()
            ->where('id', $specialistId)
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist does not belong to this workspace and agent.',
            ]);
        }

        if (! in_array('request_human_handoff', $specialist->tools_allowlist ?? [], true)) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist is not allowed to request human handoff.',
            ]);
        }
    }
}
```

- [x] **Step 4: Create controller and replace route closure**

Create `laravel/app/Http/Controllers/Internal/RequestHumanHandoffController.php`:

```php
<?php

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\RequestHumanHandoff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RequestHumanHandoffRequest;
use Illuminate\Http\JsonResponse;

class RequestHumanHandoffController extends Controller
{
    public function __invoke(
        RequestHumanHandoffRequest $request,
        RequestHumanHandoff $handoff,
    ): JsonResponse {
        return response()->json($handoff->execute($request->validated()));
    }
}
```

Modify `laravel/routes/api.php`:

```php
use App\Http\Controllers\Internal\RequestHumanHandoffController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal.runtime')
    ->post('internal/agent-tools/request-human-handoff', RequestHumanHandoffController::class);
```

- [x] **Step 5: Verify successful handoff**

Run:

```bash
php artisan test --compact --filter=RequestHumanHandoffToolTest
```

Expected: PASS.

## Task 3: Laravel Tenancy And Allowlist Guardrails

**Files:**
- Modify: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`
- Modify only if needed: `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`

- [x] **Step 1: Add failing cross-workspace rejection test**

Append:

```php
it('rejects handoff when the run belongs to another workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $run = AgentRun::factory()
        ->for($otherWorkspace)
        ->create([
            'conversation_id' => 99,
            'thread_id' => 'workspace:'.$otherWorkspace->id.':account:5:conversation:99',
            'status' => AgentRunStatus::Running,
        ]);

    $this->withHeader('X-Internal-Token', 'ci-token')
        ->postJson('/api/internal/agent-tools/request-human-handoff', [
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'agent_run_id' => $run->id,
            'thread_id' => $run->thread_id,
            'conversation_id' => 99,
            'specialist_id' => null,
            'reason' => 'Tenancy check.',
            'priority' => 'normal',
        ])
        ->assertUnprocessable();
});
```

- [x] **Step 2: Add failing allowlist rejection test**

Append:

```php
it('rejects handoff when specialist allowlist does not include the tool', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => []]);
    $run = AgentRun::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'conversation_id' => 99,
            'thread_id' => 'workspace:'.$workspace->id.':account:5:conversation:99',
            'status' => AgentRunStatus::Running,
        ]);

    $this->withHeader('X-Internal-Token', 'ci-token')
        ->postJson('/api/internal/agent-tools/request-human-handoff', [
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'agent_run_id' => $run->id,
            'thread_id' => $run->thread_id,
            'conversation_id' => 99,
            'specialist_id' => $specialist->id,
            'reason' => 'Need human.',
            'priority' => 'normal',
        ])
        ->assertUnprocessable();
});
```

- [x] **Step 3: Run guardrail tests**

Run:

```bash
php artisan test --compact --filter=RequestHumanHandoffToolTest
```

Expected: PASS. If the tests fail because `tools_allowlist` has a different property name in the model, update the test and action to use the existing casted attribute from `AgentSpecialist`.

## Task 4: Optional Chatwoot Side Effects

**Files:**
- Create: `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php`
- Modify: `laravel/app/Actions/AgentTools/RequestHumanHandoff.php`
- Modify: `laravel/tests/Feature/AgentTools/RequestHumanHandoffToolTest.php`

- [x] **Step 1: Add failing Chatwoot message test**

Append:

```php
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use Illuminate\Support\Facades\Http;

it('sends the customer handoff message through the Chatwoot agent bot when configured', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    AgentChatwootBinding::factory()
        ->for($workspace)
        ->for($agent)
        ->for($connection)
        ->create(['handoff_label_name' => 'human_handoff']);
    $run = AgentRun::factory()
        ->for($workspace)
        ->for($agent)
        ->for($connection)
        ->create([
            'chatwoot_account_id' => 5,
            'conversation_id' => 99,
            'thread_id' => 'workspace:'.$workspace->id.':account:5:conversation:99',
            'status' => AgentRunStatus::Running,
        ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['human_handoff']]),
    ]);

    $this->withHeader('X-Internal-Token', 'ci-token')
        ->postJson('/api/internal/agent-tools/request-human-handoff', [
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'agent_run_id' => $run->id,
            'thread_id' => $run->thread_id,
            'conversation_id' => 99,
            'specialist_id' => null,
            'reason' => 'Cliente pediu humano.',
            'priority' => 'normal',
            'customer_message' => 'Vou transferir você para um atendente.',
        ])
        ->assertOk();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['content'] === 'Vou transferir você para um atendente.');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['labels'] === ['human_handoff']);
});
```

- [x] **Step 2: Create Chatwoot agent-bot client**

Create `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php`:

```php
<?php

namespace App\Services\Chatwoot;

use App\Models\ChatwootConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootAgentBotClient
{
    public function __construct(private readonly ChatwootConnection $connection) {}

    public function sendConversationMessage(int $conversationId, string $content): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/messages"), [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => false,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot sendConversationMessage({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function addConversationLabel(int $conversationId, string $label): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/labels"), [
                'labels' => [$label],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot addConversationLabel({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) $this->connection->base_url, '/');
        $accountId = (int) $this->connection->account_id;

        return "{$baseUrl}/api/v1/accounts/{$accountId}/{$path}";
    }
}
```

- [x] **Step 3: Call Chatwoot side effects from the action**

In `RequestHumanHandoff::execute`, after the run is loaded and before returning, load the run connection and active binding:

```php
$run->loadMissing('chatwootConnection.activeAgentBinding');
$connection = $run->chatwootConnection;
$binding = $connection?->activeAgentBinding;
```

After `$run->update(...)`, add:

```php
if ($connection !== null) {
    $client = new \App\Services\Chatwoot\ChatwootAgentBotClient($connection);

    if (filled($payload['customer_message'] ?? null)) {
        $client->sendConversationMessage(
            (int) $payload['conversation_id'],
            (string) $payload['customer_message'],
        );
    }

    if ($binding !== null && filled($binding->handoff_label_name)) {
        $client->addConversationLabel(
            (int) $payload['conversation_id'],
            (string) $binding->handoff_label_name,
        );
    }
}
```

If `AgentRun` uses a different relationship name for the connection, use the existing relationship from `laravel/app/Models/AgentRun.php`.

- [x] **Step 4: Verify side effects**

Run:

```bash
php artisan test --compact --filter='sends the customer handoff message'
```

Expected: PASS.

## Task 5: Python Tool Client

**Files:**
- Modify: `agent-python/src/oryntra_agent/settings.py`
- Create: `agent-python/src/oryntra_agent/agent/tools.py`
- Create: `agent-python/tests/test_human_handoff_tool.py`

- [x] **Step 1: Add failing Python client test**

Create `agent-python/tests/test_human_handoff_tool.py`:

```python
import respx
from httpx import Response

from oryntra_agent.agent.tools import HumanHandoffRequest, request_human_handoff


@respx.mock
def test_request_human_handoff_posts_to_laravel_gateway(monkeypatch) -> None:
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.laravel_internal_base_url",
        "http://laravel-app",
    )
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.agent_runtime_internal_token",
        "ci-token",
    )
    route = respx.post(
        "http://laravel-app/api/internal/agent-tools/request-human-handoff"
    ).mock(
        return_value=Response(
            200,
            json={
                "status": "waiting_human",
                "handoff_id": 55,
                "message": "Human handoff requested.",
            },
        )
    )

    response = request_human_handoff(
        HumanHandoffRequest(
            workspace_id=1,
            agent_id=10,
            agent_run_id=55,
            thread_id="workspace:1:account:5:conversation:99",
            conversation_id=99,
            specialist_id=5,
            reason="Cliente pediu humano.",
            priority="normal",
            suggested_team="suporte",
            customer_message="Vou transferir você para um atendente.",
        )
    )

    assert response.status == "waiting_human"
    assert response.handoff_id == 55
    assert route.calls[0].request.headers["X-Internal-Token"] == "ci-token"
    assert route.calls[0].request.url.path == "/api/internal/agent-tools/request-human-handoff"
```

Run:

```bash
cd /home/anderson/Oryntra/agent-python
uv run pytest tests/test_human_handoff_tool.py -q
```

Expected: FAIL because `oryntra_agent.agent.tools` does not exist. If `respx` is not installed, use `pytest_httpx` already present in the project instead of adding a dependency.

- [x] **Step 2: Add settings**

Modify `agent-python/src/oryntra_agent/settings.py`:

```python
laravel_internal_base_url: str = "http://laravel-app"
agent_runtime_internal_token: str = ""
```

Use the existing settings class style and env naming convention.

- [x] **Step 3: Create tool client**

Create `agent-python/src/oryntra_agent/agent/tools.py`:

```python
from typing import Literal

import httpx
from pydantic import BaseModel, ConfigDict, Field

from oryntra_agent.settings import settings


class HumanHandoffRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_run_id: int
    thread_id: str
    conversation_id: int
    specialist_id: int | None = None
    reason: str
    priority: Literal["low", "normal", "high", "urgent"] = "normal"
    suggested_team: str | None = None
    customer_message: str | None = None


class HumanHandoffResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["waiting_human"]
    handoff_id: int
    message: str


def request_human_handoff(payload: HumanHandoffRequest) -> HumanHandoffResponse:
    base_url = settings.laravel_internal_base_url.rstrip("/")

    with httpx.Client(timeout=10) as client:
        response = client.post(
            f"{base_url}/api/internal/agent-tools/request-human-handoff",
            headers={"X-Internal-Token": settings.agent_runtime_internal_token},
            json=payload.model_dump(mode="json"),
        )

    response.raise_for_status()

    return HumanHandoffResponse.model_validate(response.json())
```

- [x] **Step 4: Verify client test**

Run:

```bash
uv run pytest tests/test_human_handoff_tool.py -q
```

Expected: PASS.

## Task 6: Python Runtime Integration

**Files:**
- Modify: `agent-python/src/oryntra_agent/agent/supervisor.py`
- Modify: `agent-python/tests/test_human_handoff_tool.py`
- Modify if needed: `agent-python/src/oryntra_agent/api/schemas.py`

- [x] **Step 1: Add failing allowlist test**

Append to `tests/test_human_handoff_tool.py`:

```python
from pydantic import SecretStr

from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import run_chatwoot_runtime
from oryntra_agent.api.schemas import ChatwootRuntimeRequest


def handoff_payload(tools: list[str]) -> ChatwootRuntimeRequest:
    return ChatwootRuntimeRequest.model_validate(
        {
            "workspace_id": 1,
            "agent_id": 10,
            "agent_mode": "supervisor",
            "thread_id": "workspace:1:account:5:conversation:99",
            "runtime_config": {
                "agent_run_id": 55,
                "conversation_id": 99,
            },
            "messages": [{"id": "123", "content": "quero falar com humano"}],
            "specialists": [
                {
                    "id": 5,
                    "name": "Suporte",
                    "role_prompt": "Ajude em suporte. Transfira para humano quando solicitado.",
                    "llm_provider": "openai",
                    "llm_model": "gpt-4.1-nano",
                    "llm_api_key": SecretStr("sk-test"),
                    "llm_temperature": 0.2,
                    "tools": tools,
                    "intent_keywords": ["humano", "suporte"],
                    "confidence_threshold": 0.5,
                }
            ],
        }
    )


def test_specialist_can_request_human_handoff_when_tool_is_allowed(monkeypatch) -> None:
    payload = handoff_payload(["request_human_handoff"])

    monkeypatch.setattr(
        supervisor,
        "generate_specialist_response_with_llm",
        lambda payload, selected_specialist: "__REQUEST_HUMAN_HANDOFF__",
    )
    monkeypatch.setattr(
        supervisor,
        "request_human_handoff",
        lambda request: supervisor.HumanHandoffResponse(
            status="waiting_human",
            handoff_id=55,
            message="Human handoff requested.",
        ),
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "waiting_human"
    assert response.response.type == "escalate"
    assert response.response.handoff_reason == "Human handoff requested by specialist."
    assert response.trace[2].type == "tool_call"
    assert response.trace[2].tool == "request_human_handoff"


def test_specialist_cannot_request_human_handoff_without_allowlist(monkeypatch) -> None:
    payload = handoff_payload([])

    monkeypatch.setattr(
        supervisor,
        "generate_specialist_response_with_llm",
        lambda payload, selected_specialist: "__REQUEST_HUMAN_HANDOFF__",
    )

    response = run_chatwoot_runtime(payload)

    assert response.status == "completed"
    assert response.response.content != "__REQUEST_HUMAN_HANDOFF__"
    assert response.trace[2].output["source"] == "blocked_tool"
```

Run:

```bash
uv run pytest tests/test_human_handoff_tool.py -q
```

Expected: FAIL until supervisor integration exists.

- [x] **Step 2: Import tool models in supervisor**

Modify `agent-python/src/oryntra_agent/agent/supervisor.py` imports:

```python
from oryntra_agent.agent.tools import (
    HumanHandoffRequest,
    HumanHandoffResponse,
    request_human_handoff,
)
```

- [x] **Step 3: Add handoff detection helper**

Add near specialist response helpers:

```python
HUMAN_HANDOFF_SENTINEL = "__REQUEST_HUMAN_HANDOFF__"


def specialist_requested_human_handoff(content: str | None) -> bool:
    if content is None:
        return False

    return HUMAN_HANDOFF_SENTINEL in content
```

This sentinel is a phase-7 bridge. A later tool-calling implementation should replace it with structured tool calls from LangChain.

- [x] **Step 4: Update routed specialist response**

In `routed_specialist_response`, after `llm_response = generate_specialist_response_with_llm(...)`, add a branch:

```python
    if specialist_requested_human_handoff(llm_response):
        if "request_human_handoff" not in selected_specialist.tools:
            return blocked_handoff_response(
                payload=payload,
                selected_specialist=selected_specialist,
                confidence=confidence,
                reason=reason,
                turn_count=turn_count,
            )

        return human_handoff_response(
            payload=payload,
            selected_specialist=selected_specialist,
            confidence=confidence,
            reason=reason,
            turn_count=turn_count,
        )
```

- [x] **Step 5: Add handoff response helpers**

Add:

```python
def human_handoff_response(
    payload: ChatwootRuntimeRequest,
    selected_specialist: SpecialistConfig,
    confidence: float,
    reason: str,
    turn_count: int,
) -> ChatwootRuntimeResponse:
    tool_response = request_human_handoff(
        HumanHandoffRequest(
            workspace_id=payload.workspace_id,
            agent_id=payload.agent_id,
            agent_run_id=int(payload.runtime_config["agent_run_id"]),
            thread_id=payload.thread_id,
            conversation_id=int(payload.runtime_config["conversation_id"]),
            specialist_id=selected_specialist.id,
            reason="Human handoff requested by specialist.",
            priority="normal",
            customer_message="Vou transferir você para um atendente.",
        )
    )

    return ChatwootRuntimeResponse(
        status="waiting_human",
        response=RuntimeResponsePayload(
            type="escalate",
            content="Vou transferir você para um atendente.",
            handoff_reason="Human handoff requested by specialist.",
            confidence=confidence,
        ),
        specialist_id=selected_specialist.id,
        trace=[
            runtime_trace_step(payload=payload, turn_count=turn_count),
            TraceStep(
                step=2,
                type="supervisor_route",
                specialist_id=selected_specialist.id,
                input={"specialists": [specialist.name for specialist in payload.specialists]},
                output={
                    "specialist_id": selected_specialist.id,
                    "specialist_name": selected_specialist.name,
                    "confidence": confidence,
                    "reason": reason,
                },
                ts=datetime.now(UTC),
            ),
            TraceStep(
                step=3,
                type="tool_call",
                specialist_id=selected_specialist.id,
                tool="request_human_handoff",
                input={"priority": "normal"},
                output={
                    "status": tool_response.status,
                    "handoff_id": tool_response.handoff_id,
                },
                ts=datetime.now(UTC),
            ),
        ],
    )


def blocked_handoff_response(
    payload: ChatwootRuntimeRequest,
    selected_specialist: SpecialistConfig,
    confidence: float,
    reason: str,
    turn_count: int,
) -> ChatwootRuntimeResponse:
    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content="Preciso encaminhar este caso, mas a transferencia humana nao esta habilitada para este especialista.",
            confidence=confidence,
        ),
        specialist_id=selected_specialist.id,
        trace=[
            runtime_trace_step(payload=payload, turn_count=turn_count),
            TraceStep(
                step=2,
                type="supervisor_route",
                specialist_id=selected_specialist.id,
                input={"specialists": [specialist.name for specialist in payload.specialists]},
                output={
                    "specialist_id": selected_specialist.id,
                    "specialist_name": selected_specialist.name,
                    "confidence": confidence,
                    "reason": reason,
                },
                ts=datetime.now(UTC),
            ),
            TraceStep(
                step=3,
                type="specialist_response",
                specialist_id=selected_specialist.id,
                input={"role_prompt": selected_specialist.role_prompt},
                output={"response_type": "text", "source": "blocked_tool"},
                ts=datetime.now(UTC),
            ),
        ],
    )
```

- [x] **Step 6: Verify runtime integration**

Run:

```bash
uv run pytest tests/test_human_handoff_tool.py tests/test_supervisor_runtime.py -q
```

Expected: PASS.

## Task 7: Quality Gates

**Files:**
- All modified files.

- [x] **Step 1: Run Python checks**

Run:

```bash
cd /home/anderson/Oryntra/agent-python
uv run ruff check .
uv run mypy src/
uv run pytest
```

Expected: all PASS.

- [x] **Step 2: Run Laravel formatting and focused tests**

Run:

```bash
cd /home/anderson/Oryntra/laravel
./vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=RequestHumanHandoffToolTest
php artisan test --compact --filter=DispatchAgentRunJobTest
php artisan test --compact --filter=AgentSupervisorRuntimePayloadTest
```

Expected: all PASS.

- [x] **Step 3: Run Laravel static analysis**

Run:

```bash
cd /home/anderson/Oryntra/laravel
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: PASS.

## Deferred

- Generic `agent_tools` table and Filament resource.
- Native LangChain tool-calling instead of the temporary sentinel.
- HITL approval/resume UI.
- Assigning Chatwoot teams or agents by `suggested_team`.
- Dedicated `agent_handoffs` table.
- RAG, document sending, MCP, media tools, and DB query tools.

## Self-Review

- Spec coverage: the plan covers human intervention semantics, Laravel gateway, token protection, tenancy, allowlist, Chatwoot side effects, Python client, runtime response, and tests.
- Placeholder scan: no `TBD` or open-ended implementation steps remain; deferred work is explicitly out of scope.
- Type consistency: the plan consistently uses `request_human_handoff`, `waiting_human`, `agent_run_id`, `conversation_id`, `specialist_id`, `tools_allowlist`, and `X-Internal-Token`.
