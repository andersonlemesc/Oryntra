<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('sends the runtime payload with internal token', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.agent_runtime.timeout', 30);

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => '[mock] Recebi 1 mensagem(ns).',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 1.0,
            ],
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
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => [
            'messages' => [['id' => '123', 'content' => 'oi']],
            'contact' => ['id' => 7],
            'inbox' => ['id' => 3],
        ],
    ]);

    $result = app(AgentRuntimeClient::class)->run($run);

    expect($result['status'])->toBe('completed');

    Http::assertSent(function (Request $request) use ($workspace, $agent, $run) {
        return $request->url() === 'http://agent-python:8000/internal/chatwoot/messages'
            && $request->hasHeader('X-Internal-Token', 'ci-token')
            && $request['workspace_id'] === $workspace->id
            && $request['agent_id'] === $agent->id
            && $request['agent_mode'] === 'single'
            && $request['messages'][0]['content'] === 'oi'
            && $request['contact']['id'] === 7
            && $request['inbox']['id'] === 3
            && $request['runtime_config']['agent_run_id'] === $run->id
            && $request['runtime_config']['conversation_id'] === 99;
    });
});

it('rejects runtime responses that do not match the contract', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response(['status' => 'completed']),
    ]);

    $run = AgentRun::factory()->create();

    app(AgentRuntimeClient::class)->run($run);
})->throws(RuntimeException::class, 'Agent runtime response failed contract validation.');

it('fails fast when the internal token is missing', function () {
    Config::set('services.agent_runtime.internal_token', null);

    app(AgentRuntimeClient::class)->run(AgentRun::factory()->create());
})->throws(RuntimeException::class, 'Agent runtime internal token is not configured.');
