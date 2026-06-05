<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
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

it('forwards the key base_url to the runtime for supervisor and specialists', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => 'Ok.',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 0.9,
            ],
            'specialist_id' => 5,
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
    $key = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'api_key' => 'sk-compat',
        'base_url' => 'https://api.groq.com/openai/v1',
    ]);
    $agent = Agent::factory()->active()->supervisor()->for($workspace)->create([
        'supervisor_llm_key_id' => $key->id,
        'supervisor_llm_model' => 'llama-3.3-70b',
    ]);
    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $workspace->id,
        'llm_key_id' => $key->id,
        'llm_model' => 'llama-3.3-70b',
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body());

        return $body->supervisor->llm_base_url === 'https://api.groq.com/openai/v1'
            && $body->specialists[0]->llm_base_url === 'https://api.groq.com/openai/v1';
    });
});

it('sends null base_url when the key uses the provider default', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'Ok.', 'document_id' => null, 'handoff_reason' => null, 'confidence' => 0.9],
            'specialist_id' => null,
            'trace' => [],
            'usage' => ['supervisor' => ['input_tokens' => 0, 'output_tokens' => 0], 'specialist' => ['input_tokens' => 0, 'output_tokens' => 0], 'total_cost_cents' => 0],
        ]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $key = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'base_url' => null,
    ]);
    $agent = Agent::factory()->active()->supervisor()->for($workspace)->create([
        'supervisor_llm_key_id' => $key->id,
        'supervisor_llm_model' => 'gpt-4.1-nano',
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => ['messages' => [['id' => '1', 'content' => 'oi']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body());

        return $body->supervisor->llm_base_url === null;
    });
});
