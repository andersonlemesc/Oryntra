<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('returns contact payload from chatwoot', function () {
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
        ->create(['tools_allowlist' => ['chatwoot_get_contact']]);
    $contact = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 42,
        'synced_at' => null,
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
            'payload' => ['id' => 42, 'name' => 'Joao', 'email' => 'joao@example.com'],
        ]),
    ]);

    postJson('/api/internal/agent-tools/chatwoot-get-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => $contact->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'contact' => ['id' => 42, 'name' => 'Joao'],
        ]);
});
