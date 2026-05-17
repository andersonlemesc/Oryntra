<?php

use App\Actions\Chatwoot\ProvisionChatwootAgentBot;
use App\Jobs\Chatwoot\ProvisionChatwootAgentBotJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootPlatformConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a Chatwoot agent bot through the Platform API and stores the returned token', function () {
    config(['chatwoot.webhook_base_url' => 'http://host.docker.internal:8080']);
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);
    $workspace = Workspace::factory()->create(['chatwoot_account_id' => 123]);
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'name' => 'Empresa A',
        'base_url' => 'https://chatwoot.test',
        'account_id' => 123,
        'agent_bot_id' => null,
        'agent_bot_outgoing_url' => null,
        'api_access_token' => null,
        'webhook_secret' => null,
        'provisioned_at' => null,
    ]);
    $expectedOutgoingUrl = "http://host.docker.internal:8080/api/webhooks/chatwoot/{$connection->connection_uuid}";

    Http::fake([
        'https://chatwoot.test/platform/api/v1/agent_bots' => Http::response([
            'id' => 456,
            'name' => 'Oryntra - Empresa A',
            'account_id' => 123,
            'outgoing_url' => $expectedOutgoingUrl,
            'access_token' => 'agent-bot-token',
            'webhook_secret' => 'webhook-secret',
        ]),
    ]);

    app(ProvisionChatwootAgentBot::class)->execute($connection);

    $connection->refresh();

    expect($connection->agent_bot_id)->toBe(456)
        ->and($connection->agent_bot_outgoing_url)->toBe($expectedOutgoingUrl)
        ->and($connection->api_access_token)->toBe('agent-bot-token')
        ->and($connection->webhook_secret)->toBe('webhook-secret')
        ->and($connection->provisioned_at)->not->toBeNull()
        ->and($connection->provisioning_error)->toBeNull();

    $rawToken = DB::table('chatwoot_connections')->where('id', $connection->id)->value('api_access_token');
    $rawSecret = DB::table('chatwoot_connections')->where('id', $connection->id)->value('webhook_secret');
    expect($rawToken)->not->toBe('agent-bot-token');
    expect($rawSecret)->not->toBe('webhook-secret');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://chatwoot.test/platform/api/v1/agent_bots'
        && $request->hasHeader('api_access_token', 'platform-token')
        && $request['account_id'] === 123
        && $request['name'] === 'Oryntra - Empresa A'
        && $request['outgoing_url'] === $expectedOutgoingUrl);
});

it('does not create another Chatwoot agent bot for an already provisioned connection', function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);
    $connection = ChatwootConnection::factory()->create([
        'agent_bot_id' => 456,
        'api_access_token' => 'agent-bot-token',
    ]);

    Http::fake();

    app(ProvisionChatwootAgentBot::class)->execute($connection);

    Http::assertNothingSent();
});

it('records the provisioning error when Chatwoot agent bot creation fails', function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);
    $connection = ChatwootConnection::factory()->create([
        'base_url' => 'https://chatwoot.test',
        'agent_bot_id' => null,
        'api_access_token' => null,
        'provisioning_error' => null,
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/agent_bots' => Http::response(['message' => 'boom'], 500),
    ]);

    expect(fn () => (new ProvisionChatwootAgentBotJob($connection->id))
        ->handle(app(ProvisionChatwootAgentBot::class)))
        ->toThrow(RuntimeException::class);

    $connection->refresh();

    expect($connection->provisioning_started_at)->not->toBeNull()
        ->and($connection->provisioning_error)->toContain('HTTP 500');
});
