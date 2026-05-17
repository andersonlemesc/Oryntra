# Agent Runtime Python Contract Phase 5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or execute this plan task-by-task in the current session. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the local mock in `DispatchAgentRunJob` with a typed Laravel -> Python runtime contract that calls a private FastAPI endpoint and stores a basic structured response, trace, and usage.

**Architecture:** Laravel remains the only public entrypoint and owns tenancy, queueing, locks, Chatwoot, and persistence. Python exposes a private `/internal/chatwoot/messages` endpoint guarded by `X-Internal-Token`, validates request/response with Pydantic, and returns a deterministic mock response until LangGraph is introduced in phase 6.

**Tech Stack:** Laravel 13, PHP 8.4, Laravel HTTP Client, Pest, FastAPI, Pydantic v2, pytest, ruff, mypy.

---

## Scope

Implement now:
- Laravel runtime client with timeout, token header, typed-ish payload builder, and response validation.
- Python Pydantic models and private endpoint.
- `DispatchAgentRunJob` integration replacing `mockRun()`.
- Basic trace step `runtime_mock`.
- Tests for token enforcement, contract payload, successful job completion, waiting-human response, and runtime failure.

Do not implement now:
- LangGraph supervisor routing.
- `agent_specialists`, `agent_tools`, RAG, MCP, media tools, HITL approval UI, or Filament changes.
- New database columns unless implementation proves `agent_runs.output` is insufficient. Current plan stores response, trace, and usage inside `output`.

## Design Decisions To Avoid Rework

- Treat the Python endpoint, Pydantic models, Laravel `AgentRuntimeClient`, and `DispatchAgentRunJob` integration as production paths, not throwaway scaffolding.
- The only temporary part is the Python handler body that creates the deterministic mock response. In phase 6, replace that body with LangGraph execution while preserving the route, auth, request schema, response schema, and Laravel client method.
- Keep request fields forward-compatible now: `agent_mode`, `guard_config`, `media_config`, `runtime_config`, nullable `specialist_id`, structured `trace`, and `usage`.
- Store the full runtime response in `agent_runs.output` so old runs remain readable after phase 6 adds supervisor routing.
- Do not encode mock-specific keys in Laravel. Laravel should only understand the stable response contract: `status`, `response`, `specialist_id`, `trace`, and `usage`.
- Keep Python isolated from Chatwoot, MinIO, database business queries, and external tools. Future tools still go through a Laravel gateway, so no later security inversion is needed.
- Keep `agent_mode` hard-coded to `single` only at the Laravel payload builder boundary. When `agents.mode` exists, this becomes a one-line source change instead of a contract change.
- Avoid naming anything `MockClient`, `FakeRuntime`, or similar in production code. Use durable names like `AgentRuntimeClient`, `ChatwootRuntimeRequest`, and `ChatwootRuntimeResponse`.

## File Map

- Modify: `agent-python/src/oryntra_agent/main.py`
  - Include the new internal Chatwoot router.
- Create: `agent-python/src/oryntra_agent/api/chatwoot_messages.py`
  - FastAPI route for `POST /internal/chatwoot/messages`.
- Create: `agent-python/src/oryntra_agent/api/schemas.py`
  - Pydantic request/response models shared by the endpoint.
- Create: `agent-python/tests/test_chatwoot_messages.py`
  - Endpoint contract and auth tests.
- Modify: `laravel/config/services.php`
  - Add runtime base URL, internal token, and timeout config.
- Create: `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php`
  - Laravel HTTP client wrapper for Python runtime.
- Modify: `laravel/app/Jobs/Agent/DispatchAgentRunJob.php`
  - Inject/resolve runtime client and replace local mock.
- Create: `laravel/tests/Feature/AgentRuntimeClientTest.php`
  - Assert request URL, token header, payload, response shape, and failed response handling.
- Modify: `laravel/tests/Feature/DispatchAgentRunJobTest.php`
  - Update existing mock-output test to runtime-output test and add failure/waiting-human cases.

## Contract

Laravel sends:

```json
{
  "workspace_id": 1,
  "agent_id": 10,
  "agent_mode": "single",
  "thread_id": "workspace:1:account:5:conversation:99",
  "messages": [
    {
      "id": "123",
      "content": "oi",
      "created_at": "2026-05-17T20:00:00Z",
      "message_type": "incoming",
      "content_type": "text"
    }
  ],
  "contact": {},
  "inbox": {},
  "guard_config": {},
  "media_config": {},
  "runtime_config": {}
}
```

Python returns:

```json
{
  "status": "completed",
  "response": {
    "type": "text",
    "content": "[mock] Recebi 1 mensagem(ns).",
    "document_id": null,
    "handoff_reason": null,
    "confidence": 1.0
  },
  "specialist_id": null,
  "trace": [
    {
      "step": 1,
      "type": "runtime_mock",
      "specialist_id": null,
      "tool": null,
      "input": {
        "message_count": 1
      },
      "output": {
        "response_type": "text"
      },
      "tokens": {
        "input": 0,
        "output": 0
      },
      "latency_ms": 0,
      "ts": "2026-05-17T20:00:00Z"
    }
  ],
  "usage": {
    "supervisor": {
      "input_tokens": 0,
      "output_tokens": 0
    },
    "specialist": {
      "input_tokens": 0,
      "output_tokens": 0
    },
    "total_cost_cents": 0
  }
}
```

## Task 1: Python Schemas

**Files:**
- Create: `agent-python/src/oryntra_agent/api/schemas.py`
- Test later in: `agent-python/tests/test_chatwoot_messages.py`

- [ ] **Step 1: Create strict Pydantic models**

Create `agent-python/src/oryntra_agent/api/schemas.py`:

```python
from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class ChatwootMessage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str | None = None
    content: str | None = None
    created_at: datetime | None = None
    message_type: str | None = None
    content_type: str | None = None
    attachments: list[dict[str, Any]] = Field(default_factory=list)


class ChatwootRuntimeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    agent_id: int
    agent_mode: Literal["single", "supervisor"] = "single"
    thread_id: str
    messages: list[ChatwootMessage] = Field(default_factory=list)
    contact: dict[str, Any] = Field(default_factory=dict)
    inbox: dict[str, Any] = Field(default_factory=dict)
    guard_config: dict[str, Any] = Field(default_factory=dict)
    media_config: dict[str, Any] = Field(default_factory=dict)
    runtime_config: dict[str, Any] = Field(default_factory=dict)


class RuntimeResponsePayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["text", "send_document", "escalate", "clarify", "multi"]
    content: str | None = None
    document_id: int | None = None
    handoff_reason: str | None = None
    confidence: float = Field(ge=0, le=1)


class TraceTokens(BaseModel):
    model_config = ConfigDict(extra="forbid")

    input: int = 0
    output: int = 0


class TraceStep(BaseModel):
    model_config = ConfigDict(extra="forbid")

    step: int
    type: str
    specialist_id: int | None = None
    tool: str | None = None
    input: dict[str, Any] = Field(default_factory=dict)
    output: dict[str, Any] = Field(default_factory=dict)
    tokens: TraceTokens = Field(default_factory=TraceTokens)
    latency_ms: int = 0
    ts: datetime


class UsageBucket(BaseModel):
    model_config = ConfigDict(extra="forbid")

    input_tokens: int = 0
    output_tokens: int = 0


class RuntimeUsage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    supervisor: UsageBucket = Field(default_factory=UsageBucket)
    specialist: UsageBucket = Field(default_factory=UsageBucket)
    total_cost_cents: int = 0


class ChatwootRuntimeResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["completed", "waiting_human", "failed"]
    response: RuntimeResponsePayload
    specialist_id: int | None = None
    trace: list[TraceStep] = Field(default_factory=list)
    usage: RuntimeUsage = Field(default_factory=RuntimeUsage)
```

- [ ] **Step 2: Run Python static checks for syntax**

Run:

```bash
cd agent-python && uv run ruff check src/oryntra_agent/api/schemas.py
```

Expected: PASS.

## Task 2: Python Endpoint

**Files:**
- Create: `agent-python/src/oryntra_agent/api/chatwoot_messages.py`
- Modify: `agent-python/src/oryntra_agent/main.py`
- Test: `agent-python/tests/test_chatwoot_messages.py`

- [ ] **Step 1: Add endpoint implementation**

Create `agent-python/src/oryntra_agent/api/chatwoot_messages.py`:

```python
from datetime import UTC, datetime

from fastapi import APIRouter, Depends

from oryntra_agent.api.schemas import (
    ChatwootRuntimeRequest,
    ChatwootRuntimeResponse,
    RuntimeResponsePayload,
    TraceStep,
)
from oryntra_agent.auth import verify_internal_token

router = APIRouter(
    prefix="/internal/chatwoot",
    tags=["chatwoot-runtime"],
    dependencies=[Depends(verify_internal_token)],
)


@router.post("/messages", response_model=ChatwootRuntimeResponse)
async def handle_chatwoot_messages(
    payload: ChatwootRuntimeRequest,
) -> ChatwootRuntimeResponse:
    message_count = len(payload.messages)

    return ChatwootRuntimeResponse(
        status="completed",
        response=RuntimeResponsePayload(
            type="text",
            content=f"[mock] Recebi {message_count} mensagem(ns).",
            confidence=1.0,
        ),
        trace=[
            TraceStep(
                step=1,
                type="runtime_mock",
                input={"message_count": message_count, "thread_id": payload.thread_id},
                output={"response_type": "text"},
                ts=datetime.now(UTC),
            )
        ],
    )
```

- [ ] **Step 2: Include router in FastAPI app**

Modify `agent-python/src/oryntra_agent/main.py`:

```python
from fastapi import FastAPI

from oryntra_agent.api.chatwoot_messages import router as chatwoot_messages_router
from oryntra_agent.api.health import router as health_router

app = FastAPI(
    title="Oryntra Agent Service",
    description="Private LangGraph runtime — invoked by Laravel via internal HTTP.",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)

app.include_router(health_router)
app.include_router(chatwoot_messages_router)
```

- [ ] **Step 3: Add endpoint tests**

Create `agent-python/tests/test_chatwoot_messages.py`:

```python
from fastapi.testclient import TestClient

from oryntra_agent import auth
from oryntra_agent.main import app


def valid_payload() -> dict[str, object]:
    return {
        "workspace_id": 1,
        "agent_id": 10,
        "agent_mode": "single",
        "thread_id": "workspace:1:account:5:conversation:99",
        "messages": [
            {
                "id": "123",
                "content": "oi",
                "created_at": "2026-05-17T20:00:00Z",
                "message_type": "incoming",
                "content_type": "text",
                "attachments": [],
            }
        ],
        "contact": {},
        "inbox": {},
        "guard_config": {},
        "media_config": {},
        "runtime_config": {},
    }


def test_chatwoot_messages_requires_internal_token(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post("/internal/chatwoot/messages", json=valid_payload())

    assert response.status_code == 401


def test_chatwoot_messages_rejects_invalid_internal_token(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post(
        "/internal/chatwoot/messages",
        headers={"X-Internal-Token": "wrong"},
        json=valid_payload(),
    )

    assert response.status_code == 401


def test_chatwoot_messages_returns_typed_mock_response(monkeypatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    response = TestClient(app).post(
        "/internal/chatwoot/messages",
        headers={"X-Internal-Token": "ci-token"},
        json=valid_payload(),
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "completed"
    assert body["response"]["type"] == "text"
    assert body["response"]["content"] == "[mock] Recebi 1 mensagem(ns)."
    assert body["trace"][0]["type"] == "runtime_mock"
    assert body["usage"]["total_cost_cents"] == 0
```

- [ ] **Step 4: Run Python tests**

Run:

```bash
cd agent-python && uv run pytest tests/test_chatwoot_messages.py tests/test_auth.py
```

Expected: PASS.

## Task 3: Laravel Runtime Config And Client

**Files:**
- Modify: `laravel/config/services.php`
- Create: `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php`
- Test: `laravel/tests/Feature/AgentRuntimeClientTest.php`

- [ ] **Step 1: Add runtime config**

Append inside the returned array in `laravel/config/services.php`:

```php
    'agent_runtime' => [
        'base_url' => env('AGENT_RUNTIME_URL', 'http://agent-python:8000'),
        'internal_token' => env('AGENT_RUNTIME_INTERNAL_TOKEN'),
        'timeout' => (int) env('AGENT_RUNTIME_TIMEOUT', 30),
    ],
```

- [ ] **Step 2: Create client**

Create `laravel/app/Services/AgentRuntime/AgentRuntimeClient.php`:

```php
<?php

namespace App\Services\AgentRuntime;

use App\Models\AgentRun;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AgentRuntimeClient
{
    /**
     * @return array{
     *     status: string,
     *     response: array<string, mixed>,
     *     specialist_id?: int|null,
     *     trace: array<int, array<string, mixed>>,
     *     usage: array<string, mixed>
     * }
     *
     * @throws RequestException
     */
    public function run(AgentRun $run): array
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new RuntimeException('Agent runtime internal token is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.agent_runtime.timeout', 30))
            ->withHeaders(['X-Internal-Token' => $token])
            ->post("{$baseUrl}/internal/chatwoot/messages", $this->payload($run))
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Agent runtime returned an invalid response.');
        }

        $status = Arr::get($response, 'status');
        $responsePayload = Arr::get($response, 'response');

        if (! in_array($status, ['completed', 'waiting_human', 'failed'], true) || ! is_array($responsePayload)) {
            throw new RuntimeException('Agent runtime response failed contract validation.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AgentRun $run): array
    {
        $input = is_array($run->input) ? $run->input : [];

        return [
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'agent_mode' => 'single',
            'thread_id' => $run->thread_id ?: $run->buildThreadId(),
            'messages' => array_values(is_array($input['messages'] ?? null) ? $input['messages'] : []),
            'contact' => is_array($input['contact'] ?? null) ? $input['contact'] : [],
            'inbox' => is_array($input['inbox'] ?? null) ? $input['inbox'] : [],
            'guard_config' => is_array($input['guard_config'] ?? null) ? $input['guard_config'] : [],
            'media_config' => is_array($input['media_config'] ?? null) ? $input['media_config'] : [],
            'runtime_config' => is_array($input['runtime_config'] ?? null) ? $input['runtime_config'] : [],
        ];
    }
}
```

- [ ] **Step 3: Add client tests**

Create `laravel/tests/Feature/AgentRuntimeClientTest.php`:

```php
<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('sends the runtime payload with internal token', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.agent_runtime.timeout', 30);

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => '[mock] Recebi 1 mensagem(ns).',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 1.0,
            ],
            'specialist_id' => null,
            'trace' => [],
            'usage' => [
                'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                'total_cost_cents' => 0,
            ],
        ]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => [
            'messages' => [['id' => '123', 'content' => 'oi']],
            'contact' => ['id' => 7],
            'inbox' => ['id' => 3],
        ],
    ]);

    $result = app(AgentRuntimeClient::class)->run($run);

    expect($result['status'])->toBe('completed');

    Http::assertSent(function (Request $request) use ($workspace, $agent) {
        return $request->url() === 'http://agent-python:8000/internal/chatwoot/messages'
            && $request->hasHeader('X-Internal-Token', 'ci-token')
            && $request['workspace_id'] === $workspace->id
            && $request['agent_id'] === $agent->id
            && $request['agent_mode'] === 'single'
            && $request['messages'][0]['content'] === 'oi';
    });
});

it('rejects runtime responses that do not match the contract', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response(['status' => 'completed']),
    ]);

    $run = AgentRun::factory()->create();

    app(AgentRuntimeClient::class)->run($run);
})->throws(RuntimeException::class, 'Agent runtime response failed contract validation.');
```

- [ ] **Step 4: Run the client tests**

Run:

```bash
cd laravel && php artisan test --compact --filter=AgentRuntimeClientTest
```

Expected: PASS.

## Task 4: Laravel Job Integration

**Files:**
- Modify: `laravel/app/Jobs/Agent/DispatchAgentRunJob.php`
- Modify: `laravel/tests/Feature/DispatchAgentRunJobTest.php`

- [ ] **Step 1: Replace local mock with runtime client**

Update `DispatchAgentRunJob`:

```php
use App\Services\AgentRuntime\AgentRuntimeClient;
```

Change:

```php
public function handle(): void
```

to:

```php
public function handle(AgentRuntimeClient $runtime): void
```

Replace:

```php
// Etapa 5 substitui pelo cliente runtime Python.
$output = $this->mockRun($run);
```

with:

```php
$output = $runtime->run($run);
```

Replace the status assignment inside the transaction:

```php
'status' => AgentRunStatus::Completed,
```

with:

```php
'status' => $output['status'] === 'waiting_human'
    ? AgentRunStatus::WaitingHuman
    : AgentRunStatus::Completed,
```

Replace the completion log message:

```php
Log::info('agent_run completed (mock)', [
```

with:

```php
Log::info('agent_run completed by runtime', [
```

Remove the entire private `mockRun()` method.

- [ ] **Step 2: Update completed job test to use `Http::fake()`**

In `laravel/tests/Feature/DispatchAgentRunJobTest.php`, add imports:

```php
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
```

At the top of the completed test, configure the fake:

```php
Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
Config::set('services.agent_runtime.internal_token', 'ci-token');

Http::fake([
    'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
        'status' => 'completed',
        'response' => [
            'type' => 'text',
            'content' => '[mock] Recebi 2 mensagem(ns).',
            'document_id' => null,
            'handoff_reason' => null,
            'confidence' => 1.0,
        ],
        'specialist_id' => null,
        'trace' => [
            [
                'step' => 1,
                'type' => 'runtime_mock',
                'input' => ['message_count' => 2],
                'output' => ['response_type' => 'text'],
                'tokens' => ['input' => 0, 'output' => 0],
                'latency_ms' => 0,
                'ts' => now()->toISOString(),
            ],
        ],
        'usage' => [
            'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
            'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
            'total_cost_cents' => 0,
        ],
    ]),
]);
```

Change the assertion:

```php
->and($freshRun?->output['content'] ?? null)->toContain('mock')
```

to:

```php
->and($freshRun?->output['response']['content'] ?? null)->toBe('[mock] Recebi 2 mensagem(ns).')
->and($freshRun?->output['trace'][0]['type'] ?? null)->toBe('runtime_mock')
```

Change the direct job call:

```php
(new DispatchAgentRunJob($run->id))->handle();
```

to:

```php
(new DispatchAgentRunJob($run->id))->handle(app(App\Services\AgentRuntime\AgentRuntimeClient::class));
```

Also change the terminal-state test direct call the same way. Because the run is already terminal, the client will not be used.

- [ ] **Step 3: Add waiting-human job test**

Append:

```php
it('marks agent_run waiting_human when runtime requests human handoff', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'waiting_human',
            'response' => [
                'type' => 'escalate',
                'content' => null,
                'document_id' => null,
                'handoff_reason' => 'confidence_below_threshold',
                'confidence' => 0.2,
            ],
            'specialist_id' => null,
            'trace' => [],
            'usage' => [
                'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                'total_cost_cents' => 0,
            ],
        ]),
    ]);

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->subSecond(),
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(App\Services\AgentRuntime\AgentRuntimeClient::class));

    $freshRun = $run->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::WaitingHuman)
        ->and($freshRun?->output['response']['type'] ?? null)->toBe('escalate');
});
```

- [ ] **Step 4: Add runtime failure job test**

Append:

```php
it('marks agent_run failed when runtime call fails', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response(['message' => 'boom'], 500),
    ]);

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->subSecond(),
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(App\Services\AgentRuntime\AgentRuntimeClient::class));
})->throws(Illuminate\Http\Client\RequestException::class);
```

Then assert the persisted failed state in a follow-up if Pest does not continue after `throws`; otherwise split into a manual try/catch:

```php
try {
    (new DispatchAgentRunJob($run->id))->handle(app(App\Services\AgentRuntime\AgentRuntimeClient::class));
} catch (Illuminate\Http\Client\RequestException $e) {
    expect($run->fresh()?->status)->toBe(AgentRunStatus::Failed);

    throw $e;
}
```

- [ ] **Step 5: Run job tests**

Run:

```bash
cd laravel && php artisan test --compact --filter=DispatchAgentRunJobTest
```

Expected: PASS.

## Task 5: Quality Gates

**Files:**
- All files changed in tasks 1-4.

- [ ] **Step 1: Format PHP**

Run:

```bash
cd laravel && ./vendor/bin/pint --dirty --format agent
```

Expected: no formatting errors.

- [ ] **Step 2: Run focused Laravel tests**

Run:

```bash
cd laravel && php artisan test --compact --filter=AgentRuntimeClientTest
cd laravel && php artisan test --compact --filter=DispatchAgentRunJobTest
```

Expected: PASS.

- [ ] **Step 3: Run focused Python tests**

Run:

```bash
cd agent-python && uv run pytest tests/test_chatwoot_messages.py tests/test_auth.py
```

Expected: PASS.

- [ ] **Step 4: Run Python quality checks**

Run:

```bash
cd agent-python && uv run ruff check .
cd agent-python && uv run mypy src/
```

Expected: PASS.

- [ ] **Step 5: Run broader tests if focused checks pass**

Run:

```bash
cd laravel && php artisan test --compact
cd agent-python && uv run pytest
```

Expected: PASS or only unrelated pre-existing failures documented in the final handoff.

## Completion Criteria

- `POST /internal/chatwoot/messages` exists and requires `X-Internal-Token`.
- Python validates request and response with Pydantic models.
- Laravel sends runtime calls to `AGENT_RUNTIME_URL/internal/chatwoot/messages`.
- Laravel includes `X-Internal-Token` from config and fails fast when missing.
- `DispatchAgentRunJob` no longer uses `mockRun()`.
- Runtime `completed` maps to `AgentRunStatus::Completed`.
- Runtime `waiting_human` maps to `AgentRunStatus::WaitingHuman`.
- Runtime HTTP/client failure maps to `AgentRunStatus::Failed` and logs context.
- `agent_runs.output` stores the full runtime response: `status`, `response`, `specialist_id`, `trace`, and `usage`.
- Focused Laravel and Python tests pass.

## Notes For Phase 6

- Keep `agent_mode` hard-coded to `single` until the `agents.mode` schema exists.
- Keep specialist fields nullable in the contract so phase 6 can add supervisor routing without another breaking contract.
- Do not let Python call Chatwoot, MinIO, Postgres business tables, or external tools directly. Tool execution remains a future Laravel gateway.
