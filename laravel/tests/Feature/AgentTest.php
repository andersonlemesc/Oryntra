<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates agent associated with workspace with FK', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $agentWorkspace = $agent->workspace;

    assert($agentWorkspace instanceof Workspace);

    expect($agentWorkspace->is($workspace))->toBeTrue();

    $foreignKeys = collect(Schema::getForeignKeys('agents'));

    expect($foreignKeys->contains(fn (array $fk): bool => in_array('workspace_id', $fk['columns'], true)
        && $fk['foreign_table'] === 'workspaces'))->toBeTrue();
});

it('casts status, response_mode and llm_provider as enums', function () {
    $agent = Agent::factory()->active()->create([
        'response_mode' => AgentResponseMode::HumanApproval,
        'llm_provider' => AgentLlmProvider::Anthropic,
    ]);

    expect($agent->status)->toBe(AgentStatus::Active)
        ->and($agent->response_mode)->toBe(AgentResponseMode::HumanApproval)
        ->and($agent->llm_provider)->toBe(AgentLlmProvider::Anthropic);
});

it('casts jsonb configs as arrays', function () {
    $agent = Agent::factory()->create();
    $debounce = $agent->debounce_config;

    assert(is_array($debounce));

    expect($agent->debounce_config)->toBeArray();
    expect($agent->media_policy)->toBeArray();
    expect($agent->guard_config)->toBeArray();
    expect($agent->rag_config)->toBeArray();
    expect($agent->runtime_config)->toBeArray();
    expect((int) $debounce['window_seconds'])->toBe(8);
});

it('enforces unique name per workspace', function () {
    $workspace = Workspace::factory()->create();
    Agent::factory()->for($workspace)->create(['name' => 'support']);

    expect(fn () => Agent::factory()->for($workspace)->create(['name' => 'support']))
        ->toThrow(QueryException::class);
});

it('allows same agent name across different workspaces', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Agent::factory()->for($a)->create(['name' => 'support']);
    $second = Agent::factory()->for($b)->create(['name' => 'support']);

    expect($second->exists)->toBeTrue();
});

it('cascades agent deletion when workspace is deleted', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();

    $workspace->delete();

    expect(Agent::find($agent->id))->toBeNull();
});
