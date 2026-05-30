<?php

declare(strict_types=1);

use App\Enums\AgentRunSource;
use App\Enums\AgentRunStatus;
use App\Enums\PlaygroundMessageStatus;
use App\Jobs\Playground\StreamPlaygroundRunJob;
use App\Models\AgentRun;
use App\Models\PlaygroundConversation;
use App\Models\PlaygroundMessage;
use App\Services\Playground\PlaygroundRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fakeStream(array $events): PlaygroundRuntimeClient
{
    $client = Mockery::mock(PlaygroundRuntimeClient::class);
    $client->shouldReceive('streamEvents')->andReturnUsing(function () use ($events): Generator {
        yield from $events;
    });

    return $client;
}

it('persists the final response, trace and usage from the stream', function (): void {
    $conversation = PlaygroundConversation::factory()->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $conversation->workspace_id,
        'agent_id' => $conversation->agent_id,
        'source' => AgentRunSource::Playground,
        'chatwoot_connection_id' => null,
        'contact_id' => null,
        'status' => AgentRunStatus::Running,
    ]);
    $assistant = PlaygroundMessage::factory()->assistant()->create([
        'playground_conversation_id' => $conversation->id,
        'agent_run_id' => $run->id,
    ]);

    $client = fakeStream([
        ['event' => 'routing', 'data' => ['specialist_id' => 5, 'confidence' => 0.9, 'reason' => 'kw']],
        ['event' => 'token', 'data' => ['delta' => 'Olá ']],
        ['event' => 'token', 'data' => ['delta' => 'mundo']],
        ['event' => 'final', 'data' => [
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'Olá mundo'],
            'specialist_id' => 5,
            'trace' => [['step' => 1, 'type' => 'runtime']],
            'usage' => ['total_cost_cents' => 2],
        ]],
    ]);

    (new StreamPlaygroundRunJob($assistant->id))->handle($client);

    $assistant->refresh();
    $run->refresh();

    expect($assistant->status)->toBe(PlaygroundMessageStatus::Completed);
    expect($assistant->content)->toBe('Olá mundo');
    expect($assistant->specialist_id)->toBe(5);
    expect($assistant->trace)->toBeArray()->toHaveCount(1);
    expect($assistant->usage['total_cost_cents'])->toBe(2);
    expect($run->status)->toBe(AgentRunStatus::Completed);
});

it('marks the message failed when the stream emits an error', function (): void {
    $conversation = PlaygroundConversation::factory()->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $conversation->workspace_id,
        'agent_id' => $conversation->agent_id,
        'source' => AgentRunSource::Playground,
        'chatwoot_connection_id' => null,
        'status' => AgentRunStatus::Running,
    ]);
    $assistant = PlaygroundMessage::factory()->assistant()->create([
        'playground_conversation_id' => $conversation->id,
        'agent_run_id' => $run->id,
    ]);

    $client = fakeStream([
        ['event' => 'token', 'data' => ['delta' => 'parc']],
        ['event' => 'error', 'data' => ['message' => 'boom']],
    ]);

    (new StreamPlaygroundRunJob($assistant->id))->handle($client);

    $assistant->refresh();

    expect($assistant->status)->toBe(PlaygroundMessageStatus::Failed);
    expect($assistant->error_message)->toBe('boom');
    expect($assistant->agentRun->status)->toBe(AgentRunStatus::Failed);
});
