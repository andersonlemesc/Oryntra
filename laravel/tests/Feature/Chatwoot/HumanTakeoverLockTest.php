<?php

declare(strict_types=1);

use App\Actions\Chatwoot\ApplyConversationStateFromWebhook;
use App\Actions\Chatwoot\ClassifyChatwootWebhookEvent;
use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Actions\Chatwoot\ResolveAgentForChatwootEvent;
use App\Enums\AgentChatwootBindingStatus;
use App\Enums\AgentResponseMode;
use App\Jobs\Chatwoot\ProcessChatwootWebhookEventJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\ChatwootConversationState;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @param array<string, mixed> $payload
 */
function takeoverEvent(Workspace $workspace, ChatwootConnection $connection, string $eventName, array $payload, int $conversationId = 700): ChatwootWebhookEvent
{
    return ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'event_name' => $eventName,
        'conversation_id' => $conversationId,
        'chatwoot_message_id' => (string) fake()->unique()->numberBetween(1000, 9999),
        'payload' => $payload,
        'status' => 'queued',
    ]);
}

function runTakeoverJob(int $eventId): void
{
    (new ProcessChatwootWebhookEventJob($eventId))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ApplyConversationStateFromWebhook::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );
}

/**
 * @return array<string, mixed>
 */
function outgoingPayload(string $senderType, bool $private = false): array
{
    return [
        'event' => 'message_created',
        'message_type' => 'outgoing',
        'private' => $private,
        'sender' => ['type' => $senderType],
        'content' => 'mensagem do atendente',
        'conversation' => ['id' => 700, 'inbox_id' => 1],
        'inbox' => ['id' => 1],
    ];
}

/**
 * @return array<string, mixed>
 */
function incomingPayload(): array
{
    return [
        'event' => 'message_created',
        'message_type' => 'incoming',
        'private' => false,
        'sender' => ['type' => 'Contact'],
        'content' => 'oi de novo',
        'conversation' => ['id' => 700, 'inbox_id' => 1],
        'inbox' => ['id' => 1],
    ];
}

function seedActiveBinding(Workspace $workspace, ChatwootConnection $connection, AgentResponseMode $mode): Agent
{
    $agent = Agent::factory()->active()->for($workspace)->create([
        'response_mode' => $mode,
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    return $agent;
}

it('records a human takeover when an agent replies publicly', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $event = takeoverEvent($workspace, $connection, 'message_created', outgoingPayload('user'));

    runTakeoverJob($event->id);

    expect($event->fresh()?->ignored_reason)->toBe('human_takeover_recorded')
        ->and(ChatwootConversationState::hasHumanTakeover($connection->id, 700))->toBeTrue();
});

it('does not record a takeover for the bot own outgoing message', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $event = takeoverEvent($workspace, $connection, 'message_created', outgoingPayload('agent_bot'));

    runTakeoverJob($event->id);

    expect($event->fresh()?->ignored_reason)->toBe('not_incoming_message')
        ->and(ChatwootConversationState::hasHumanTakeover($connection->id, 700))->toBeFalse();
});

it('does not record a takeover for a human private note', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $event = takeoverEvent($workspace, $connection, 'message_created', outgoingPayload('user', private: true));

    runTakeoverJob($event->id);

    expect($event->fresh()?->ignored_reason)->toBe('private_message')
        ->and(ChatwootConversationState::hasHumanTakeover($connection->id, 700))->toBeFalse();
});

it('blocks an automatic agent from answering once a human took over', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = seedActiveBinding($workspace, $connection, AgentResponseMode::Automatic);
    ChatwootConversationState::markHumanTakeover($workspace->id, $connection->id, 700);

    $event = takeoverEvent($workspace, $connection, 'message_created', incomingPayload());

    runTakeoverJob($event->id);

    $fresh = $event->fresh();
    expect($fresh?->status)->toBe('ignored')
        ->and($fresh?->ignored_reason)->toBe('human_takeover_active')
        ->and($fresh?->resolved_agent_id)->toBe($agent->id)
        ->and($fresh?->agent_run_id)->toBeNull();
});

it('still lets a copilot agent suggest after a human took over', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    seedActiveBinding($workspace, $connection, AgentResponseMode::SuggestionOnly);
    ChatwootConversationState::markHumanTakeover($workspace->id, $connection->id, 700);

    $event = takeoverEvent($workspace, $connection, 'message_created', incomingPayload());

    runTakeoverJob($event->id);

    $fresh = $event->fresh();
    expect($fresh?->status)->toBe('debouncing')
        ->and($fresh?->agent_run_id)->not->toBeNull();
});

it('clears the takeover lock when the conversation is resolved', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    ChatwootConversationState::markHumanTakeover($workspace->id, $connection->id, 700);

    $event = takeoverEvent($workspace, $connection, 'conversation_status_changed', [
        'event' => 'conversation_status_changed',
        'status' => 'resolved',
        'id' => 700,
    ]);

    runTakeoverJob($event->id);

    expect($event->fresh()?->ignored_reason)->toBe('conversation_resolved_unlock')
        ->and(ChatwootConversationState::hasHumanTakeover($connection->id, 700))->toBeFalse();
});
