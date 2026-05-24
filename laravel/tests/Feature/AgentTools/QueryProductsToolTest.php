<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Category;
use App\Models\ChatwootConnection;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects query_products without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/query-products', [])
        ->assertForbidden();
});

it('returns active products scoped to the workspace and specialist allowlist', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['query_products']]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $category = Category::factory()->for($workspace)->create([
        'name' => 'Bikes',
        'slug' => 'bikes',
    ]);
    $otherCategory = Category::factory()->for($otherWorkspace)->create([
        'name' => 'Bikes',
        'slug' => 'bikes',
    ]);

    $matchingProduct = Product::factory()->forCategory($category)->create([
        'name' => 'Bike Eletrica Urbana',
        'sku' => 'BIKE-001',
        'description' => 'Autonomia de 50km.',
        'price' => 3499.90,
    ]);
    Product::factory()->inactive()->forCategory($category)->create([
        'name' => 'Bike Eletrica Antiga',
    ]);
    Product::factory()->forCategory($otherCategory)->create([
        'name' => 'Bike Eletrica Outro Workspace',
    ]);

    postJson('/api/internal/agent-tools/query-products', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'query' => 'eletrica',
        'category' => 'Bikes',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('products.0.id', $matchingProduct->id)
        ->assertJsonPath('products.0.name', 'Bike Eletrica Urbana')
        ->assertJsonPath('products.0.sku', 'BIKE-001')
        ->assertJsonPath('products.0.category', 'Bikes');
});

it('rejects the call when the specialist allowlist does not include query_products', function () {
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

    postJson('/api/internal/agent-tools/query-products', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'query' => 'bike',
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

    postJson('/api/internal/agent-tools/query-products', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'query' => 'bike',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['agent_run_id']);
});
