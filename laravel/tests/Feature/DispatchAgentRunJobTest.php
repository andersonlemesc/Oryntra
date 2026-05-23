<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
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

it('marks agent_run completed with supervisor runtime output and transitions linked events to processed', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => '[mock] Suporte recebeu 2 mensagem(ns).',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 1.0,
            ],
            'specialist_id' => 5,
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
                [
                    'step' => 2,
                    'type' => 'supervisor_route',
                    'specialist_id' => 5,
                    'input' => ['specialists' => ['Suporte']],
                    'output' => ['specialist_id' => 5, 'confidence' => 1.0],
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
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
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
        'input' => ['messages' => [['content' => 'oi'], ['content' => 'olha']]],
    ]);

    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 99,
        'resolved_agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'status' => 'debouncing',
        'processed_at' => null,
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $freshRun = $run->fresh();
    $freshEvent = $event->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::Completed)
        ->and($freshRun?->output['response']['content'] ?? null)->toBe('[mock] Suporte recebeu 2 mensagem(ns).')
        ->and($freshRun?->output['specialist_id'] ?? null)->toBe(5)
        ->and($freshRun?->output['trace'][0]['type'] ?? null)->toBe('runtime_mock')
        ->and($freshRun?->output['trace'][1]['specialist_id'] ?? null)->toBe(5)
        ->and($freshRun?->output['usage']['total_cost_cents'] ?? null)->toBe(0)
        ->and($freshRun?->output['response_delivery']['status'] ?? null)->toBe('completed')
        ->and($freshRun?->started_at)->not->toBeNull()
        ->and($freshRun?->finished_at)->not->toBeNull()
        ->and($freshEvent?->status)->toBe('processed')
        ->and($freshEvent?->processed_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request['content'] === '[mock] Suporte recebeu 2 mensagem(ns).'
        && $request['message_type'] === 'outgoing'
        && $request['private'] === false);
});

it('does not send a completed runtime response twice when response delivery already completed', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => 'Resposta nova do runtime.',
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

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Queued,
        'output' => [
            'response_delivery' => [
                'status' => 'completed',
                'sent_at' => now()->subMinute()->toISOString(),
                'conversation_id' => 99,
                'response_type' => 'text',
            ],
        ],
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    expect($run->fresh()?->status)->toBe(AgentRunStatus::Completed)
        ->and($run->fresh()?->output['response_delivery']['status'] ?? null)->toBe('completed');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/conversations/'));
});

it('reschedules itself if debounce_until is still in the future', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 11,
        'chatwoot_account_id' => 5,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:11",
        'status' => AgentRunStatus::Debouncing,
        'debounce_until' => now()->addSeconds(30),
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $freshRun = $run->fresh();
    expect($freshRun?->status)->toBe(AgentRunStatus::Debouncing)
        ->and($freshRun?->started_at)->toBeNull();

    Bus::assertDispatched(DispatchAgentRunJob::class);
});

it('no-ops if agent_run already in terminal state', function () {
    $run = AgentRun::factory()->completed()->create();

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $fresh = $run->fresh();
    expect($fresh?->status)->toBe(AgentRunStatus::Completed)
        ->and($fresh?->output['content'] ?? null)->toBe('mock response');
});

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

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $freshRun = $run->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::WaitingHuman)
        ->and($freshRun?->output['response']['type'] ?? null)->toBe('escalate')
        ->and($freshRun?->output['response']['confidence'] ?? null)->toBe(0.2)
        ->and($freshRun?->output['response']['handoff_reason'] ?? null)->toBe('confidence_below_threshold');
});

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

    try {
        (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));
    } catch (RequestException $e) {
        expect($run->fresh()?->status)->toBe(AgentRunStatus::Failed)
            ->and($run->fresh()?->error_message)->toContain('HTTP request returned status code 500');

        throw $e;
    }
})->throws(RequestException::class);
