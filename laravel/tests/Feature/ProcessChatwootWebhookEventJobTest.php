<?php

declare(strict_types=1);

use App\Actions\Chatwoot\ClassifyChatwootWebhookEvent;
use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Actions\Chatwoot\ResolveAgentForChatwootEvent;
use App\Enums\AgentChatwootBindingStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Jobs\Chatwoot\ProcessChatwootWebhookEventJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makeIncomingEvent(Workspace $workspace, ChatwootConnection $connection): ChatwootWebhookEvent
{
    return ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'event_name' => 'message_created',
        'conversation_id' => 42,
        'chatwoot_message_id' => (string) fake()->unique()->numberBetween(1000, 9999),
        'payload' => [
            'event' => 'message_created',
            'message_type' => 'incoming',
            'private' => false,
            'sender' => ['type' => 'Contact'],
            'inbox' => ['id' => 1],
        ],
        'status' => 'queued',
    ]);
}

function runProcessJob(int $eventId): void
{
    (new ProcessChatwootWebhookEventJob($eventId))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );
}

it('marks event ignored:no_active_binding when no agent binding exists', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $event = makeIncomingEvent($workspace, $connection);

    runProcessJob($event->id);

    $fresh = $event->fresh();
    expect($fresh?->status)->toBe('ignored')
        ->and($fresh?->ignored_reason)->toBe('no_active_binding')
        ->and($fresh?->resolved_agent_id)->toBeNull()
        ->and($fresh?->agent_run_id)->toBeNull();
});

it('starts debounce when active binding exists', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    $event = makeIncomingEvent($workspace, $connection);

    runProcessJob($event->id);

    $fresh = $event->fresh();
    expect($fresh?->status)->toBe('debouncing')
        ->and($fresh?->resolved_agent_id)->toBe($agent->id)
        ->and($fresh?->agent_run_id)->not->toBeNull()
        ->and($fresh?->processed_at)->toBeNull()
        ->and($fresh?->ignored_reason)->toBeNull();

    Bus::assertDispatched(DispatchAgentRunJob::class);
});

it('still marks event ignored when classification fails before resolver runs', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'event_name' => 'message_created',
        'payload' => [
            'event' => 'message_created',
            'message_type' => 'outgoing',
            'sender' => ['type' => 'User'],
        ],
        'status' => 'queued',
    ]);

    runProcessJob($event->id);

    $fresh = $event->fresh();
    expect($fresh?->status)->toBe('ignored')
        ->and($fresh?->ignored_reason)->toBe('not_incoming_message')
        ->and($fresh?->resolved_agent_id)->toBeNull()
        ->and($fresh?->agent_run_id)->toBeNull();
});
