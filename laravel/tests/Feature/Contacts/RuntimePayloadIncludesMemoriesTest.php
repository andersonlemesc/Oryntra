<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\ContactMemory;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('includes contact memories in the runtime payload when any specialist enables injection', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->for($workspace)->create();
    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'llm_key_id' => $llmKey->id,
        'llm_model' => 'gpt-4.1-mini',
        'memory_config' => [
            'injection_enabled' => true,
            'injection_limit' => 3,
        ],
    ]);

    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    foreach (['memoria mais antiga', 'memoria do meio', 'memoria mais nova'] as $i => $content) {
        ContactMemory::factory()->create([
            'contact_id' => $contact->id,
            'workspace_id' => $workspace->id,
            'content' => $content,
            'type' => 'fact',
            'source' => 'agent_extracted',
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'status' => AgentRunStatus::Queued,
        'input' => ['messages' => [['content' => 'oi']]],
    ]);

    $captured = null;
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => function ($request) use (&$captured) {
            $captured = $request->data();

            return Http::response([
                'status' => 'completed',
                'response' => ['type' => 'text', 'content' => 'ok', 'confidence' => 1.0],
                'specialist_id' => null,
                'trace' => [],
                'usage' => [
                    'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'total_cost_cents' => 0,
                ],
            ]);
        },
    ]);

    app(AgentRuntimeClient::class)->run($run);

    assert(is_array($captured));

    expect($captured['contact'])->toBeArray()
        ->and($captured['contact']['memories'])->toHaveCount(3)
        ->and($captured['contact']['memories'][0]['content'])->toBe('memoria mais nova')
        ->and($captured['contact']['memories'][2]['content'])->toBe('memoria mais antiga')
        ->and($captured['specialists'][0]['memory_config']['injection_enabled'])->toBeTrue()
        ->and($captured['specialists'][0]['memory_config']['injection_limit'])->toBe(3);
});

it('omits memories when no specialist enables injection', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->for($workspace)->create();
    AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'llm_key_id' => $llmKey->id,
        'memory_config' => ['injection_enabled' => false],
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    ContactMemory::factory()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'content' => 'segredo',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'input' => ['messages' => [['content' => 'oi']]],
    ]);

    $captured = null;
    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => function ($request) use (&$captured) {
            $captured = $request->data();

            return Http::response([
                'status' => 'completed',
                'response' => ['type' => 'text', 'content' => 'ok', 'confidence' => 1.0],
                'specialist_id' => null,
                'trace' => [],
                'usage' => [
                    'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'total_cost_cents' => 0,
                ],
            ]);
        },
    ]);

    app(AgentRuntimeClient::class)->run($run);

    assert(is_array($captured));
    expect($captured['contact']['memories'] ?? [])->toBe([]);
});
