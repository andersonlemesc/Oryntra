<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.agent_runtime.max_concurrency_per_account', 0);
});

it('dispatches the run to the runtime and marks it running without blocking', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages/dispatch' => Http::response([
            'accepted' => true,
            'agent_run_id' => 1,
        ], 202),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 99,
        'chatwoot_account_id' => 5,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Debouncing,
        'debounce_started_at' => now()->subSeconds(10),
        'debounce_until' => now()->subSecond(),
        'input' => ['messages' => [['content' => 'oi']]],
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $freshRun = $run->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::Running)
        ->and($freshRun?->started_at)->not->toBeNull()
        ->and($freshRun?->finished_at)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://agent-python:8000/internal/chatwoot/messages/dispatch'
        && $request['agent_run_id'] === $run->id);
});

it('reschedules itself if debounce_until is still in the future', function () {
    Bus::fake();

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->addSeconds(30),
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $freshRun = $run->fresh();
    expect($freshRun?->status)->toBe(AgentRunStatus::Debouncing)
        ->and($freshRun?->started_at)->toBeNull();

    Bus::assertDispatched(DispatchAgentRunJob::class);
    Http::assertNothingSent();
});

it('no-ops if agent_run already in terminal state', function () {
    $run = AgentRun::factory()->completed()->create();

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    expect($run->fresh()?->status)->toBe(AgentRunStatus::Completed);
    Http::assertNothingSent();
});

it('throws when the runtime rejects the dispatch and keeps the run in flight for retry', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages/dispatch' => Http::response(['message' => 'boom'], 500),
    ]);

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->subSecond(),
    ]);

    expect(fn () => (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class)))
        ->toThrow(RequestException::class);

    // Status stays in-flight (not Running) so a retry can re-dispatch cleanly.
    expect($run->fresh()?->status)->toBe(AgentRunStatus::Debouncing);
});

it('releases without marking running when the runtime is at capacity (503)', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages/dispatch' => Http::response(['detail' => 'runtime at capacity'], 503),
    ]);

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->subSecond(),
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    // Stayed in-flight (released back to the queue), not Running.
    expect($run->fresh()?->status)->toBe(AgentRunStatus::Debouncing)
        ->and($run->fresh()?->started_at)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/messages/dispatch'));
});

it('marks the run failed once dispatch retries are exhausted', function () {
    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
    ]);

    (new DispatchAgentRunJob($run->id))->failed(new RuntimeException('agent-python down'));

    $fresh = $run->fresh();
    expect($fresh?->status)->toBe(AgentRunStatus::Failed)
        ->and($fresh?->error_message)->toBe('agent-python down')
        ->and($fresh?->finished_at)->not->toBeNull();
});
