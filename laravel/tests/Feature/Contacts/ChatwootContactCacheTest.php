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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('reads chatwoot contact from local cache when fresh', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'admin_api_token' => 'admin-token',
    ]);
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'tools_allowlist' => ['chatwoot_get_contact'],
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
        'name' => 'Cached Anderson',
        'synced_at' => Carbon::now()->subSeconds(60),
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    Http::fake();

    postJson('/api/internal/agent-tools/chatwoot-get-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'source' => 'cache',
            'contact' => ['id' => 42, 'name' => 'Cached Anderson'],
        ]);

    Http::assertNothingSent();
});

it('fetches chatwoot contact from API when cache is stale and updates local row', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'admin_api_token' => 'admin-token',
    ]);
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'tools_allowlist' => ['chatwoot_get_contact'],
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
        'name' => 'Old Anderson',
        'synced_at' => Carbon::now()->subHour(),
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/contacts/42' => Http::response([
            'payload' => [
                'id' => 42,
                'name' => 'Anderson Atualizado',
                'email' => 'anderson@new.com',
            ],
        ]),
    ]);

    postJson('/api/internal/agent-tools/chatwoot-get-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson(['source' => 'chatwoot']);

    $contact->refresh();

    expect($contact->name)->toBe('Anderson Atualizado')
        ->and($contact->email)->toBe('anderson@new.com')
        ->and($contact->synced_at)->not->toBeNull();
});

it('updates local contact row when chatwoot_update_contact succeeds', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'admin_api_token' => 'admin-token',
    ]);
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'tools_allowlist' => ['chatwoot_update_contact'],
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
        'name' => 'Antigo',
        'email' => 'old@example.com',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/contacts/42' => Http::response([
            'payload' => [
                'id' => 42,
                'name' => 'Maria',
                'email' => 'maria@example.com',
                'additional_attributes' => ['city' => 'Floripa'],
            ],
        ]),
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
        'name' => 'Maria',
        'email' => 'maria@example.com',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    $contact->refresh();

    expect($contact->name)->toBe('Maria')
        ->and($contact->email)->toBe('maria@example.com')
        ->and($contact->additional_attributes)->toBe(['city' => 'Floripa'])
        ->and($contact->synced_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://chatwoot.test/api/v1/accounts/5/contacts/42');
});

it('serves stale cache when admin token is missing and Chatwoot cannot be reached', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'admin_api_token' => null,
    ]);
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create([
        'tools_allowlist' => ['chatwoot_get_contact'],
    ]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
        'name' => 'Cliente offline',
        'synced_at' => Carbon::now()->subHour(),
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'contact_id' => $contact->id,
    ]);

    postJson('/api/internal/agent-tools/chatwoot-get-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson(['source' => 'cache_stale']);
});
