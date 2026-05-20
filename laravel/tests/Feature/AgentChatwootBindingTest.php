<?php

declare(strict_types=1);

use App\Enums\AgentChatwootBindingStatus;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates binding tying agent to chatwoot connection within workspace', function () {
    $binding = AgentChatwootBinding::factory()->create();
    $agent = $binding->agent;
    $connection = $binding->chatwootConnection;

    assert($agent instanceof Agent);
    assert($connection instanceof ChatwootConnection);

    expect($binding->workspace)->not->toBeNull()
        ->and($agent->workspace_id)->toBe($binding->workspace_id)
        ->and($connection->workspace_id)->toBe($binding->workspace_id);
});

it('casts status, inbox_ids, ignore_label_names', function () {
    $binding = AgentChatwootBinding::factory()->create([
        'inbox_ids' => [1, 2, 3],
        'ignore_label_names' => ['spam', 'lost'],
        'handoff_team_id' => 12,
        'handoff_agent_id' => 34,
        'handoff_team_name' => 'Suporte',
        'handoff_agent_name' => 'Ada',
        'handoff_private_note_template' => 'Motivo: {reason}',
        'handoff_assign_strategy' => 'team_then_agent',
    ]);

    expect($binding->status)->toBe(AgentChatwootBindingStatus::Active)
        ->and($binding->inbox_ids)->toBe([1, 2, 3])
        ->and($binding->ignore_label_names)->toBe(['spam', 'lost'])
        ->and($binding->ignore_assigned_conversations)->toBeFalse()
        ->and($binding->handoff_team_id)->toBe(12)
        ->and($binding->handoff_agent_id)->toBe(34)
        ->and($binding->handoff_team_name)->toBe('Suporte')
        ->and($binding->handoff_agent_name)->toBe('Ada')
        ->and($binding->handoff_private_note_template)->toBe('Motivo: {reason}')
        ->and($binding->handoff_assign_strategy)->toBe('team_then_agent');
});

it('allows only one active binding per chatwoot connection', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agentA = Agent::factory()->for($workspace)->create();
    $agentB = Agent::factory()->for($workspace)->create();

    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agentA->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    expect(fn () => AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agentB->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]))->toThrow(QueryException::class);
});

it('allows multiple inactive bindings per connection alongside one active', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();

    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => Agent::factory()->for($workspace)->create()->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    $inactiveOne = AgentChatwootBinding::factory()->inactive()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => Agent::factory()->for($workspace)->create()->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $inactiveTwo = AgentChatwootBinding::factory()->inactive()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => Agent::factory()->for($workspace)->create()->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    expect($inactiveOne->exists)->toBeTrue()
        ->and($inactiveTwo->exists)->toBeTrue();
});

it('exposes activeAgentBinding on ChatwootConnection', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->for($workspace)->create();

    $binding = AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    expect($connection->fresh()?->activeAgentBinding?->is($binding))->toBeTrue();
});

it('cascades binding when agent deleted', function () {
    $binding = AgentChatwootBinding::factory()->create();
    $agentId = $binding->agent_id;
    $agent = $binding->agent;

    assert($agent instanceof Agent);

    $agent->delete();

    expect(AgentChatwootBinding::where('agent_id', $agentId)->count())->toBe(0);
});
