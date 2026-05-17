<?php

declare(strict_types=1);

use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
use App\Models\AgentSpecialist;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates specialist scoped to workspace and agent', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->supervisor()->for($workspace)->create();

    $specialist = AgentSpecialist::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    assert($specialist->workspace instanceof Workspace);
    assert($specialist->agent instanceof Agent);

    expect($specialist->workspace->is($workspace))->toBeTrue()
        ->and($specialist->agent->is($agent))->toBeTrue();

    $foreignKeys = collect(Schema::getForeignKeys('agent_specialists'));

    expect($foreignKeys->contains(fn (array $fk): bool => in_array('workspace_id', $fk['columns'], true)
        && $fk['foreign_table'] === 'workspaces'))->toBeTrue()
        ->and($foreignKeys->contains(fn (array $fk): bool => in_array('agent_id', $fk['columns'], true)
            && $fk['foreign_table'] === 'agents'))->toBeTrue();
});

it('casts agent mode and specialist fields', function () {
    $agent = Agent::factory()->supervisor()->create();
    $specialist = AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $agent->workspace_id,
        'intent_keywords' => ['vendas', 'preco'],
        'tools_allowlist' => ['search_kb'],
        'status' => AgentSpecialistStatus::Inactive,
    ]);

    expect($agent->mode)->toBe(AgentMode::Supervisor)
        ->and($specialist->status)->toBe(AgentSpecialistStatus::Inactive)
        ->and($specialist->intent_keywords)->toBe(['vendas', 'preco'])
        ->and($specialist->tools_allowlist)->toBe(['search_kb']);
});

it('enforces unique specialist name per agent', function () {
    $agent = Agent::factory()->supervisor()->create();

    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $agent->workspace_id,
        'name' => 'Suporte',
    ]);

    expect(fn () => AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $agent->workspace_id,
        'name' => 'Suporte',
    ]))->toThrow(QueryException::class);
});
