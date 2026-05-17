<?php

declare(strict_types=1);

use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a new agent_run on the first message and dispatches DispatchAgentRunJob', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create([
        'debounce_config' => ['window_seconds' => 6],
    ]);
    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'chatwoot_message_id' => '100',
    ]);

    $normalized = [
        'content' => 'oi',
        'content_type' => 'text',
        'attachments' => [],
    ];

    $run = app(EnqueueAgentRunForEvent::class)->execute($event, $agent, $normalized);

    $input = $run->input;
    assert(is_array($input));

    expect($run->status)->toBe(AgentRunStatus::Debouncing)
        ->and($run->agent_id)->toBe($agent->id)
        ->and($run->workspace_id)->toBe($workspace->id)
        ->and($run->chatwoot_connection_id)->toBe($connection->id)
        ->and($run->conversation_id)->toBe(77)
        ->and($run->thread_id)->toBe("workspace:{$workspace->id}:account:5:conversation:77")
        ->and($input['messages'][0]['content'])->toBe('oi');

    Bus::assertDispatched(DispatchAgentRunJob::class, fn (DispatchAgentRunJob $job): bool => $job->agentRunId === $run->id);
});

it('appends to the in-flight run when called again within the window', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create([
        'debounce_config' => ['window_seconds' => 6],
    ]);

    $event1 = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'chatwoot_message_id' => '101',
    ]);

    $event2 = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'chatwoot_message_id' => '102',
    ]);

    $first = app(EnqueueAgentRunForEvent::class)->execute($event1, $agent, ['content' => 'oi', 'attachments' => []]);
    $second = app(EnqueueAgentRunForEvent::class)->execute($event2, $agent, ['content' => 'tudo bem?', 'attachments' => []]);

    $secondInput = $second->input;
    assert(is_array($secondInput));

    expect($second->id)->toBe($first->id);
    expect(AgentRun::query()->count())->toBe(1);
    $messages = $secondInput['messages'];
    assert(is_array($messages));
    expect($messages)->toHaveCount(2);
    expect($messages[1]['content'])->toBe('tudo bem?');
    expect($second->chatwoot_message_id)->toBe('102');

    Bus::assertDispatchedTimes(DispatchAgentRunJob::class, 1);
});

it('creates a separate run after the previous one finishes', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $eventA = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 88,
        'chatwoot_account_id' => 5,
    ]);
    $runA = app(EnqueueAgentRunForEvent::class)->execute($eventA, $agent, ['content' => 'a', 'attachments' => []]);
    $runA->forceFill(['status' => AgentRunStatus::Completed, 'finished_at' => now()])->save();

    $eventB = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 88,
        'chatwoot_account_id' => 5,
    ]);
    $runB = app(EnqueueAgentRunForEvent::class)->execute($eventB, $agent, ['content' => 'b', 'attachments' => []]);

    expect($runB->id)->not->toBe($runA->id)
        ->and(AgentRun::query()->count())->toBe(2);
});
