<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects query_documents without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/query-documents', [])
        ->assertForbidden();
});

it('returns only sendable documents scoped to the workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['query_documents']]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    $catalog = Document::factory()->for($workspace)->create([
        'category' => 'catalog',
        'title' => 'Catalogo 2026',
    ]);
    Document::factory()->for($workspace)->create([
        'category' => 'knowledge',
        'title' => 'Base interna da IA',
    ]);
    Document::factory()->for($otherWorkspace)->create([
        'category' => 'catalog',
        'title' => 'Catalogo de outro workspace',
    ]);

    postJson('/api/internal/agent-tools/query-documents', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('documents.0.id', $catalog->id)
        ->assertJsonPath('documents.0.category', 'catalog');
});

it('restricts results to the specialist allowed_categories when set', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'tools_allowlist' => ['query_documents'],
            'document_tools_config' => ['allowed_categories' => ['catalog']],
        ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    $catalog = Document::factory()->for($workspace)->create(['category' => 'catalog']);
    Document::factory()->for($workspace)->create(['category' => 'manual']);

    postJson('/api/internal/agent-tools/query-documents', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('documents.0.id', $catalog->id);
});

it('rejects the call when the specialist allowlist does not include query_documents', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => []]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    postJson('/api/internal/agent-tools/query-documents', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialist_id']);
});

it('rejects an agent run from another workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $otherAgent = Agent::factory()->active()->for($otherWorkspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'agent_id' => $otherAgent->id,
    ]);

    postJson('/api/internal/agent-tools/query-documents', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['agent_run_id']);
});
