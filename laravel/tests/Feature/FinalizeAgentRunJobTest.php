<?php

declare(strict_types=1);

use App\Enums\AgentResponseMode;
use App\Enums\AgentRunStatus;
use App\Jobs\Agent\FinalizeAgentRunJob;
use App\Jobs\Agent\OpenConversationOnAgentFailureJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function finalizeRuntimeResult(array $overrides = []): array
{
    return array_replace([
        'status' => 'completed',
        'response' => [
            'type' => 'text',
            'content' => '[mock] resposta',
            'confidence' => 1.0,
        ],
        'specialist_id' => null,
        'trace' => [],
        'usage' => [
            'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
            'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
            'total_cost_cents' => 0,
        ],
    ], $overrides);
}

it('delivers the runtime response to Chatwoot and transitions linked events to processed', function () {
    Http::fake([
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
        'status' => AgentRunStatus::Running,
        'started_at' => now()->subSeconds(5),
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

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult([
        'response' => ['type' => 'text', 'content' => 'Ola!', 'confidence' => 1.0],
        'specialist_id' => 5,
    ])))->handle();

    $freshRun = $run->fresh();
    $freshEvent = $event->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::Completed)
        ->and($freshRun?->output['response']['content'] ?? null)->toBe('Ola!')
        ->and($freshRun?->output['specialist_id'] ?? null)->toBe(5)
        ->and($freshRun?->output['response_delivery']['status'] ?? null)->toBe('completed')
        ->and($freshRun?->finished_at)->not->toBeNull()
        ->and($freshEvent?->status)->toBe('processed')
        ->and($freshEvent?->processed_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request['content'] === 'Ola!');
});

it('delivers a copilot response as a private note and opens the conversation, never publicly', function () {
    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['status' => 'open']),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $agent = Agent::factory()->active()->for($workspace)->create([
        'response_mode' => AgentResponseMode::SuggestionOnly,
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 99,
        'chatwoot_account_id' => 5,
        'status' => AgentRunStatus::Running,
        'started_at' => now()->subSeconds(5),
    ]);

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult([
        'response' => ['type' => 'text', 'content' => 'Sugiro responder: bom dia!', 'confidence' => 1.0],
    ])))->handle();

    $freshRun = $run->fresh();

    expect($freshRun?->status)->toBe(AgentRunStatus::Completed)
        ->and($freshRun?->output['response_delivery']['status'] ?? null)->toBe('completed')
        ->and($freshRun?->output['response_delivery']['mode'] ?? null)->toBe('suggestion');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/conversations/99/toggle_status')
        && $request['status'] === 'open');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/conversations/99/messages')
        && $request['private'] === true
        && $request['content'] === 'Sugiro responder: bom dia!');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/conversations/99/messages')
        && ($request['private'] ?? null) === false);
});

it('does not deliver twice when response delivery already completed', function () {
    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
        'output' => [
            'response_delivery' => [
                'status' => 'completed',
                'sent_at' => now()->subMinute()->toISOString(),
                'conversation_id' => 99,
                'response_type' => 'text',
            ],
        ],
    ]);

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult([
        'response' => ['type' => 'text', 'content' => 'Resposta nova', 'confidence' => 1.0],
    ])))->handle();

    expect($run->fresh()?->status)->toBe(AgentRunStatus::Completed)
        ->and($run->fresh()?->output['response_delivery']['status'] ?? null)->toBe('completed');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/conversations/'));
});

it('marks the run failed and opens the conversation for a human when the runtime reports failure', function () {
    Bus::fake();

    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
        'conversation_id' => 99,
    ]);

    (new FinalizeAgentRunJob($run->id, [
        'status' => 'failed',
        'error' => 'runtime_timeout',
    ]))->handle();

    $freshRun = $run->fresh();
    expect($freshRun?->status)->toBe(AgentRunStatus::Failed)
        ->and($freshRun?->error_message)->toBe('runtime_timeout');

    Bus::assertDispatched(
        OpenConversationOnAgentFailureJob::class,
        fn (OpenConversationOnAgentFailureJob $job): bool => $job->agentRunId === $run->id
            && $job->reason === 'runtime_timeout',
    );
});

it('does not open a conversation on a completed run', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 1]),
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
    ]);

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult([
        'response' => ['type' => 'text', 'content' => 'Ola!', 'confidence' => 1.0],
    ])))->handle();

    Bus::assertNotDispatched(OpenConversationOnAgentFailureJob::class);
});

it('preserves the resolution payload written by resolve_conversation when merging', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
        'output' => [
            'resolution' => [
                'reason' => 'Cliente confirmou.',
                'customer_message' => 'Ja encaminhei o catalogo.',
                'side_effects' => ['status' => 'queued'],
            ],
        ],
    ]);

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult([
        'response' => ['type' => 'text', 'content' => 'Otimo, ate breve!', 'confidence' => 1.0],
        'specialist_id' => 6,
    ])))->handle();

    $output = $run->fresh()?->output;

    expect(is_array($output))->toBeTrue()
        ->and($output['resolution']['reason'] ?? null)->toBe('Cliente confirmou.')
        ->and($output['resolution']['customer_message'] ?? null)->toBe('Ja encaminhei o catalogo.');
});

it('is idempotent for runs already in a terminal state', function () {
    $run = AgentRun::factory()->completed()->create();

    (new FinalizeAgentRunJob($run->id, finalizeRuntimeResult()))->handle();

    expect($run->fresh()?->status)->toBe(AgentRunStatus::Completed);
    Http::assertNothingSent();
});
