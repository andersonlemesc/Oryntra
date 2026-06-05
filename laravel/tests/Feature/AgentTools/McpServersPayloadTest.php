<?php

declare(strict_types=1);

use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\ExternalTool;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function fakeAgentRuntime(): void
{
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'ok', 'document_id' => null, 'handoff_reason' => null, 'confidence' => 1.0],
            'specialist_id' => null,
            'trace' => [],
            'usage' => [
                'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                'total_cost_cents' => 0,
            ],
        ]),
        'https://mcp.example.test/mcp' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-03-26']], 200, ['Mcp-Session-Id' => 'sess-run-1'])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [
                ['name' => 'get_order', 'description' => 'Get order', 'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['order_id' => ['type' => 'string', 'description' => 'Order ID']],
                    'required' => ['order_id'],
                ]],
            ]]]),
    ]);
}

beforeEach(function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.agent_runtime.timeout', 30);
});

it('includes mcp_servers in specialist payload when server is in allowlist', function () {
    fakeAgentRuntime();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'status' => AgentSpecialistStatus::Active->value,
        'tools_allowlist' => ['crm_n8n'],
    ]);

    ExternalTool::factory()->mcp()->for($workspace)->create([
        'slug' => 'crm_n8n',
        'description' => 'CRM via n8n MCP.',
        'enabled' => true,
        'config' => ['base_url' => 'https://mcp.example.test/mcp', 'auth_type' => 'none', 'auth_config' => [], 'timeout_seconds' => 10],
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'http://agent-python:8000/internal/chatwoot/messages') {
            return false;
        }

        $specialists = $request['specialists'] ?? [];
        $mcpServers = $specialists[0]['mcp_servers'] ?? null;

        if (! is_array($mcpServers) || count($mcpServers) !== 1) {
            return false;
        }

        $s = $mcpServers[0];

        return $s['server_slug'] === 'crm_n8n'
            && $s['session_id'] === 'sess-run-1'
            && count($s['tools']) === 1
            && ($s['tools'][0]['tool_name'] ?? '') === 'get_order'
            && isset($s['tools'][0]['param_schema']['properties']['order_id']);
    });
});

it('skips unreachable MCP server and delivers empty mcp_servers to Python', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'ok', 'document_id' => null, 'handoff_reason' => null, 'confidence' => 1.0],
            'specialist_id' => null, 'trace' => [],
            'usage' => ['supervisor' => ['input_tokens' => 0, 'output_tokens' => 0], 'specialist' => ['input_tokens' => 0, 'output_tokens' => 0], 'total_cost_cents' => 0],
        ]),
        'https://mcp.example.test/mcp' => Http::response('', 503),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'status' => AgentSpecialistStatus::Active->value,
        'tools_allowlist' => ['crm_n8n'],
    ]);

    ExternalTool::factory()->mcp()->for($workspace)->create([
        'slug' => 'crm_n8n',
        'enabled' => true,
        'config' => ['base_url' => 'https://mcp.example.test/mcp', 'auth_type' => 'none', 'auth_config' => [], 'timeout_seconds' => 10],
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'http://agent-python:8000/internal/chatwoot/messages') {
            return false;
        }

        $specialists = $request['specialists'] ?? [];
        $mcpServers = $specialists[0]['mcp_servers'] ?? null;

        return is_array($mcpServers) && count($mcpServers) === 0;
    });
});

it('excludes disabled MCP servers from payload even when slug is in allowlist', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'ok', 'document_id' => null, 'handoff_reason' => null, 'confidence' => 1.0],
            'specialist_id' => null, 'trace' => [],
            'usage' => ['supervisor' => ['input_tokens' => 0, 'output_tokens' => 0], 'specialist' => ['input_tokens' => 0, 'output_tokens' => 0], 'total_cost_cents' => 0],
        ]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'status' => AgentSpecialistStatus::Active->value,
        'tools_allowlist' => ['crm_n8n'],
    ]);

    ExternalTool::factory()->mcp()->for($workspace)->disabled()->create([
        'slug' => 'crm_n8n',
        'config' => ['base_url' => 'https://mcp.example.test/mcp', 'auth_type' => 'none', 'auth_config' => [], 'timeout_seconds' => 10],
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'http://agent-python:8000/internal/chatwoot/messages') {
            return false;
        }

        $specialists = $request['specialists'] ?? [];
        $mcpServers = $specialists[0]['mcp_servers'] ?? [];

        return is_array($mcpServers) && count($mcpServers) === 0;
    });
});

it('does not include http_connector rows in mcp_servers and correctly separates external_tools', function () {
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'ok', 'document_id' => null, 'handoff_reason' => null, 'confidence' => 1.0],
            'specialist_id' => null, 'trace' => [],
            'usage' => ['supervisor' => ['input_tokens' => 0, 'output_tokens' => 0], 'specialist' => ['input_tokens' => 0, 'output_tokens' => 0], 'total_cost_cents' => 0],
        ]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'status' => AgentSpecialistStatus::Active->value,
        'tools_allowlist' => ['query_orders'],
    ]);

    ExternalTool::factory()->for($workspace)->create(['slug' => 'query_orders', 'enabled' => true]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'http://agent-python:8000/internal/chatwoot/messages') {
            return false;
        }

        $specialists = $request['specialists'] ?? [];
        $mcpServers = $specialists[0]['mcp_servers'] ?? [];
        $httpTools = $specialists[0]['external_tools'] ?? [];

        return is_array($mcpServers) && count($mcpServers) === 0
            && is_array($httpTools) && count($httpTools) === 1;
    });
});
