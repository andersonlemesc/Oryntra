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

it('injects only allowed enabled connectors into the specialist payload, without secrets', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.agent_runtime.timeout', 30);

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
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'status' => AgentSpecialistStatus::Active->value,
        'tools_allowlist' => ['query_orders', 'query_disabled', 'query_missing'],
    ]);

    ExternalTool::factory()->for($workspace)->create([
        'slug' => 'query_orders',
        'description' => 'Consulta status do pedido.',
        'enabled' => true,
        'credentials' => ['token' => 'super-secret'],
        'config' => array_replace_recursive(ExternalTool::factory()->definition()['config'], [
            'base_url' => 'https://erp.internal',
            'param_schema' => ['properties' => [
                'order_id' => ['type' => 'string', 'location' => 'query', 'required' => true],
            ]],
        ]),
    ]);
    ExternalTool::factory()->for($workspace)->disabled()->create(['slug' => 'query_disabled']);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        $connectors = $request['specialists'][0]['external_tools'] ?? null;

        if (! is_array($connectors) || count($connectors) !== 1) {
            return false;
        }

        $connector = $connectors[0];

        return $connector['slug'] === 'query_orders'
            && $connector['description'] === 'Consulta status do pedido.'
            && isset($connector['param_schema']['properties']['order_id'])
            && ! array_key_exists('credentials', $connector)
            && ! array_key_exists('base_url', $connector)
            && ! array_key_exists('config', $connector);
    });
});
