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

it('sends supervisor config and active specialists to runtime', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => '[mock] Suporte recebeu 1 mensagem(ns).',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 1.0,
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
    $supervisorLlmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'api_key' => 'sk-supervisor-test',
    ]);
    $specialistLlmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'api_key' => 'sk-specialist-test',
    ]);
    $agent = Agent::factory()->supervisor()->for($workspace)->create([
        'supervisor_prompt' => 'Route to the best specialist.',
        'supervisor_llm_key_id' => $supervisorLlmKey->id,
        'supervisor_llm_model' => 'gpt-4.1-nano',
    ]);

    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $workspace->id,
        'name' => 'Suporte',
        'intent_keywords' => ['ajuda'],
        'llm_key_id' => $specialistLlmKey->id,
        'llm_model' => 'gpt-4.1-mini',
        'tools_allowlist' => ['request_human_handoff'],
        'handoff_config' => [
            'enabled' => true,
            'default_priority' => 'high',
            'customer_message' => 'Vou transferir voce para um atendente.',
            'rules' => [
                [
                    'name' => 'Pedido humano',
                    'enabled' => true,
                    'keywords' => ['humano', 'atendente'],
                    'priority' => 'high',
                    'reason' => 'Cliente pediu atendimento humano.',
                ],
            ],
        ],
        'priority' => 10,
    ]);

    AgentSpecialist::factory()->inactive()->for($agent)->create([
        'workspace_id' => $workspace->id,
        'name' => 'Inativo',
        'priority' => 1,
    ]);

    $otherWorkspace = Workspace::factory()->create();

    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $otherWorkspace->id,
        'name' => 'Outro Workspace',
        'priority' => 1,
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => ['messages' => [['id' => '123', 'content' => 'preciso de ajuda']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request) {
        return $request['agent_mode'] === 'supervisor'
            && $request['supervisor']['prompt'] === 'Route to the best specialist.'
            && $request['supervisor']['llm_key_id'] !== null
            && $request['supervisor']['llm_provider'] === 'openai'
            && $request['supervisor']['llm_model'] === 'gpt-4.1-nano'
            && $request['supervisor']['llm_api_key'] === 'sk-supervisor-test'
            && count($request['specialists']) === 1
            && $request['specialists'][0]['name'] === 'Suporte'
            && $request['specialists'][0]['llm_key_id'] !== null
            && $request['specialists'][0]['llm_provider'] === 'openai'
            && $request['specialists'][0]['llm_model'] === 'gpt-4.1-mini'
            && $request['specialists'][0]['llm_api_key'] === 'sk-specialist-test'
            && $request['specialists'][0]['intent_keywords'] === ['ajuda']
            && $request['specialists'][0]['tools'] === ['request_human_handoff']
            && $request['specialists'][0]['handoff_config']['enabled'] === true
            && $request['specialists'][0]['handoff_config']['rules'][0]['keywords'] === ['humano', 'atendente'];
    });
});
