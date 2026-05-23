<?php

declare(strict_types=1);

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ExtractContactMemoryJob;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\ContactMemory;
use App\Models\Workspace;
use App\Services\AgentRuntime\MemoryExtractionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('persists extracted memories with source agent_extracted', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->for($workspace)->create([
        'provider' => AgentLlmProvider::OpenAI,
        'status' => AgentLlmKeyStatus::Active,
        'api_key' => 'sk-test',
    ]);
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'llm_key_id' => $llmKey->id,
            'llm_model' => 'gpt-4.1-mini',
            'memory_config' => [
                'extraction_enabled' => true,
                'extraction_types' => ['preference', 'fact'],
            ],
        ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'conversation_id' => 99,
        'status' => AgentRunStatus::Completed,
        'input' => ['messages' => [
            ['content' => 'Procuro uma bike eletrica urbana'],
            ['content' => 'Tenho 1,72 e peso 80kg'],
        ]],
        'output' => [
            'response' => ['content' => 'Anotei suas preferencias.'],
            'trace' => [
                ['type' => 'supervisor_route', 'specialist_id' => $specialist->id],
            ],
        ],
    ]);

    $this->app->instance(MemoryExtractionClient::class, new class extends MemoryExtractionClient
    {
        public function extract(array $payload): array
        {
            return [
                'status' => 'ok',
                'memories' => [
                    ['type' => 'preference', 'content' => 'Quer bike eletrica urbana', 'confidence' => 0.9],
                    ['type' => 'fact', 'content' => 'Altura 1,72m, peso 80kg', 'confidence' => 0.95],
                ],
                'reason' => null,
            ];
        }
    });

    (new ExtractContactMemoryJob($run->id))->handle(app(MemoryExtractionClient::class));

    $memories = ContactMemory::query()->where('contact_id', $contact->id)->orderBy('id')->get();

    expect($memories)->toHaveCount(2)
        ->and($memories[0]->type->value)->toBe('preference')
        ->and($memories[0]->source->value)->toBe('agent_extracted')
        ->and($memories[0]->conversation_id)->toBe(99)
        ->and($memories[0]->agent_run_id)->toBe($run->id)
        ->and($memories[1]->content)->toBe('Altura 1,72m, peso 80kg');
});

it('skips when specialist memory extraction is disabled', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'memory_config' => ['extraction_enabled' => false],
        ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'output' => [
            'trace' => [['type' => 'supervisor_route', 'specialist_id' => $specialist->id]],
        ],
    ]);

    $client = new class extends MemoryExtractionClient
    {
        public bool $called = false;

        public function extract(array $payload): array
        {
            $this->called = true;

            return ['status' => 'ok', 'memories' => []];
        }
    };

    (new ExtractContactMemoryJob($run->id))->handle($client);

    expect($client->called)->toBeFalse()
        ->and(ContactMemory::query()->count())->toBe(0);
});

it('skips when the run has no linked contact', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => null,
    ]);

    $client = new class extends MemoryExtractionClient
    {
        public bool $called = false;

        public function extract(array $payload): array
        {
            $this->called = true;

            return ['status' => 'ok', 'memories' => []];
        }
    };

    (new ExtractContactMemoryJob($run->id))->handle($client);

    expect($client->called)->toBeFalse();
});
