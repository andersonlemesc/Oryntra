<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\ContactMemory;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects update_contact_memory without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/update-contact-memory', [])
        ->assertForbidden();
});

it('persists a memory with source=tool and links it to the agent run', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['update_contact_memory']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'conversation_id' => 42,
    ]);

    $response = postJson('/api/internal/agent-tools/update-contact-memory', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
        'type' => 'preference',
        'content' => 'Prefere bike eletrica urbana com autonomia de 50km',
        'confidence' => 0.85,
    ], ['X-Internal-Token' => 'ci-token']);

    $response->assertOk()->assertJson(['status' => 'ok']);

    $memory = ContactMemory::query()->latest('id')->first();
    expect($memory)->not->toBeNull()
        ->and($memory?->type->value)->toBe('preference')
        ->and($memory?->source->value)->toBe('tool')
        ->and($memory?->content)->toBe('Prefere bike eletrica urbana com autonomia de 50km')
        ->and((float) $memory?->confidence)->toBe(0.85)
        ->and($memory?->conversation_id)->toBe(42)
        ->and($memory?->agent_run_id)->toBe($run->id);
});

it('rejects the call when the specialist allowlist does not include update_contact_memory', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => []]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    postJson('/api/internal/agent-tools/update-contact-memory', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
        'type' => 'fact',
        'content' => 'algo',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['specialist_id']);
});

it('rejects invalid memory type', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    postJson('/api/internal/agent-tools/update-contact-memory', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'contact_id' => $contact->id,
        'type' => 'invalid_type',
        'content' => 'algo',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

it('rejects contact from a different workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspaceA)->create();
    $connection = ChatwootConnection::factory()->for($workspaceA)->create();
    $contactB = Contact::factory()->create([
        'workspace_id' => $workspaceB->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspaceA->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    postJson('/api/internal/agent-tools/update-contact-memory', [
        'workspace_id' => $workspaceA->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'contact_id' => $contactB->id,
        'type' => 'fact',
        'content' => 'cross-tenant attempt',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contact_id']);
});
