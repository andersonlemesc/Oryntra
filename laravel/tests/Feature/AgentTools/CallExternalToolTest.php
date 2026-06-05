<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ExternalTool;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
});

function seedConnectorScenario(array $allowlist = ['query_orders'], bool $enabled = true): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'tools_allowlist' => $allowlist,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);
    $tool = ExternalTool::factory()->for($workspace)->create([
        'slug' => 'query_orders',
        'enabled' => $enabled,
        'config' => array_replace_recursive(ExternalTool::factory()->definition()['config'], [
            'param_schema' => ['properties' => [
                'order_id' => ['type' => 'string', 'location' => 'query', 'required' => true],
            ]],
            'response_extraction' => ['mode' => 'jsonpath', 'expression' => '$.status', 'max_length' => 2000],
        ]),
    ]);

    return compact('workspace', 'agent', 'specialist', 'run', 'tool');
}

it('rejects the call without the internal runtime token', function () {
    postJson('/api/internal/agent-tools/call-external-tool', [])
        ->assertForbidden();
});

it('executes an enabled connector scoped to the workspace and allowlist', function () {
    Http::fake(['*' => Http::response(['status' => 'shipped'], 200)]);

    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedConnectorScenario();

    postJson('/api/internal/agent-tools/call-external-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'external_tool_slug' => 'query_orders',
        'args' => ['order_id' => 'A1'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('result', 'shipped');
});

it('rejects a disabled connector', function () {
    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedConnectorScenario(enabled: false);

    postJson('/api/internal/agent-tools/call-external-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'external_tool_slug' => 'query_orders',
        'args' => ['order_id' => 'A1'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['external_tool_slug']);
});

it('rejects when the specialist allowlist excludes the connector slug', function () {
    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedConnectorScenario(allowlist: []);

    postJson('/api/internal/agent-tools/call-external-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'external_tool_slug' => 'query_orders',
        'args' => ['order_id' => 'A1'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialist_id']);
});

it('rejects a connector slug from another workspace', function () {
    Http::fake(['*' => Http::response(['status' => 'x'], 200)]);

    ['agent' => $a, 'run' => $r] = seedConnectorScenario();
    $otherWorkspace = Workspace::factory()->create();

    postJson('/api/internal/agent-tools/call-external-tool', [
        'workspace_id' => $otherWorkspace->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'external_tool_slug' => 'query_orders',
        'args' => ['order_id' => 'A1'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422);
});
