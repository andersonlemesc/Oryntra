<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects contact updates without internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [])
        ->assertForbidden();
});

it('updates whitelisted fields and ignores extras', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['chatwoot_update_contact']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'contact_id' => $contact->id,
        'conversation_id' => 99,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/contacts/42' => Http::response([
            'payload' => ['id' => 42, 'name' => 'Maria', 'email' => 'maria@example.com'],
        ]),
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
        'name' => 'Maria',
        'email' => 'maria@example.com',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'contact' => ['id' => 42, 'name' => 'Maria'],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://chatwoot.test/api/v1/accounts/5/contacts/42'
        && $request['name'] === 'Maria'
        && $request['email'] === 'maria@example.com'
        && ! isset($request['custom_attributes']));
});

it('updates local delivery address without calling Chatwoot', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => null,
    ]);
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['chatwoot_update_contact']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'contact_id' => $contact->id,
        'conversation_id' => 99,
    ]);

    Http::fake();

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
        'address_postal_code' => '01001-000',
        'address_street' => 'Praca da Se',
        'address_number' => '100',
        'address_neighborhood' => 'Se',
        'address_city' => 'Sao Paulo',
        'address_state' => 'SP',
        'address_country' => 'Brasil',
        'address_reference' => 'Portaria azul',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJsonPath('contact.address_city', 'Sao Paulo')
        ->assertJsonPath('contact.address_reference', 'Portaria azul');

    Http::assertNothingSent();

    $contact->refresh();

    expect($contact->address_postal_code)->toBe('01001-000')
        ->and($contact->address_street)->toBe('Praca da Se')
        ->and($contact->address_number)->toBe('100')
        ->and($contact->address_city)->toBe('Sao Paulo')
        ->and($contact->address_state)->toBe('SP');
});

it('rejects update when no whitelisted field is provided', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['chatwoot_update_contact']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects when specialist lacks update tool in allowlist', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['request_human_handoff']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
        'name' => 'Joao',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});
