<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ExternalTool;
use App\Models\ExternalToolCallLog;
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

function seedMcpScenario(array $allowlist = ['crm_n8n'], bool $enabled = true): array
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
    $server = ExternalTool::factory()->mcp()->for($workspace)->create([
        'slug' => 'crm_n8n',
        'enabled' => $enabled,
        'config' => [
            'base_url' => 'https://mcp.example.test/mcp',
            'auth_type' => 'none',
            'auth_config' => [],
            'timeout_seconds' => 10,
        ],
    ]);

    return compact('workspace', 'agent', 'specialist', 'run', 'server');
}

it('rejects the call without the internal runtime token', function () {
    postJson('/api/internal/agent-tools/call-mcp-tool', [])
        ->assertForbidden();
});

it('executes a tool call on an enabled MCP server', function () {
    Http::fake([
        'https://mcp.example.test/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => 'Order 123: shipped']]],
        ]),
    ]);

    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedMcpScenario();

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => ['order_id' => '123'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('result', 'Order 123: shipped');
});

it('rejects a disabled MCP server', function () {
    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedMcpScenario(enabled: false);

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => [],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects when server slug is not in the specialist allowlist', function () {
    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedMcpScenario(allowlist: ['other_tool']);

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => [],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects when agent run does not match workspace and agent', function () {
    ['workspace' => $w, 'agent' => $a, 'specialist' => $s] = seedMcpScenario();
    $other = AgentRun::factory()->create();

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $other->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => [],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('creates an audit log row after successful tool call', function () {
    Http::fake([
        'https://mcp.example.test/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => 'ok']]],
        ]),
    ]);

    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r, 'server' => $server] = seedMcpScenario();

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => ['order_id' => '99'],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk();

    expect(ExternalToolCallLog::query()->where('external_tool_id', $server->id)->count())->toBe(1);

    $log = ExternalToolCallLog::query()->where('external_tool_id', $server->id)->first();
    expect($log->tool_slug)->toBe('crm_n8n__get_order')
        ->and($log->success)->toBeTrue()
        ->and($log->agent_run_id)->toBe($r->id);
});

it('returns error result and logs failure when MCP server returns JSON-RPC error', function () {
    Http::fake([
        'https://mcp.example.test/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32603, 'message' => 'tool timed out'],
        ]),
    ]);

    ['workspace' => $w, 'agent' => $a, 'specialist' => $s, 'run' => $r] = seedMcpScenario();

    postJson('/api/internal/agent-tools/call-mcp-tool', [
        'workspace_id' => $w->id,
        'agent_id' => $a->id,
        'agent_run_id' => $r->id,
        'specialist_id' => $s->id,
        'server_slug' => 'crm_n8n',
        'tool_name' => 'get_order',
        'args' => [],
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'tool timed out');
});
