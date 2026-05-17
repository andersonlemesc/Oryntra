<?php

declare(strict_types=1);

use App\Actions\Chatwoot\ResolveAgentForChatwootEvent;
use App\Enums\AgentChatwootBindingStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>                                                                                                                  $bindingOverrides
 * @param  array<string, mixed>|null                                                                                                             $eventPayload
 * @return array{workspace: Workspace, connection: ChatwootConnection, agent: Agent, binding: AgentChatwootBinding, event: ChatwootWebhookEvent}
 */
function makeEventWithAgent(array $bindingOverrides = [], ?array $eventPayload = null): array
{
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $binding = AgentChatwootBinding::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ], $bindingOverrides));

    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'payload' => $eventPayload ?? [
            'event' => 'message_created',
            'message_type' => 'incoming',
            'private' => false,
            'inbox' => ['id' => 7],
        ],
    ]);

    return compact('workspace', 'connection', 'agent', 'binding', 'event');
}

it('returns the active agent bound to a connection', function () {
    $ctx = makeEventWithAgent();

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($ctx['event'], []);

    expect($resolution['agent']?->is($ctx['agent']))->toBeTrue()
        ->and($resolution['binding']?->is($ctx['binding']))->toBeTrue()
        ->and($resolution['ignored_reason'])->toBeNull();
});

it('ignores when no binding exists for the connection', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($event, []);

    expect($resolution['agent'])->toBeNull()
        ->and($resolution['ignored_reason'])->toBe('no_active_binding');
});

it('ignores when binding is inactive', function () {
    $ctx = makeEventWithAgent(['status' => AgentChatwootBindingStatus::Inactive]);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($ctx['event'], []);

    expect($resolution['agent'])->toBeNull()
        ->and($resolution['ignored_reason'])->toBe('no_active_binding');
});

it('ignores when binding active but agent itself is inactive', function () {
    $ctx = makeEventWithAgent();
    $ctx['agent']->update(['status' => AgentStatus::Inactive]);

    $event = $ctx['event']->fresh();
    assert($event instanceof ChatwootWebhookEvent);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($event, []);

    expect($resolution['agent'])->toBeNull()
        ->and($resolution['ignored_reason'])->toBe('agent_inactive');
});

it('respects inbox_ids filter — matches when payload inbox is allowed', function () {
    $ctx = makeEventWithAgent([
        'inbox_ids' => [7, 12],
    ], [
        'event' => 'message_created',
        'inbox' => ['id' => 7],
    ]);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($ctx['event'], []);

    expect($resolution['agent']?->is($ctx['agent']))->toBeTrue();
});

it('respects inbox_ids filter — ignores when payload inbox not in list', function () {
    $ctx = makeEventWithAgent([
        'inbox_ids' => [7, 12],
    ], [
        'event' => 'message_created',
        'inbox' => ['id' => 99],
    ]);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($ctx['event'], []);

    expect($resolution['agent'])->toBeNull()
        ->and($resolution['ignored_reason'])->toBe('inbox_not_enabled');
});

it('treats empty inbox_ids as "all inboxes"', function () {
    $ctx = makeEventWithAgent([
        'inbox_ids' => [],
    ], [
        'event' => 'message_created',
        'inbox' => ['id' => 42],
    ]);

    $resolution = app(ResolveAgentForChatwootEvent::class)
        ->execute($ctx['event'], []);

    expect($resolution['agent']?->is($ctx['agent']))->toBeTrue();
});
