<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
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
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
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
        'contact_id' => 42,
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

it('rejects update when no whitelisted field is provided', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['chatwoot_update_contact']]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
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
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
    ]);

    postJson('/api/internal/agent-tools/chatwoot-update-contact', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'specialist_id' => $specialist->id,
        'contact_id' => 42,
        'name' => 'Joao',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});
