<?php

declare(strict_types=1);

use App\Jobs\Chatwoot\SyncChatwootTeamsJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootTeam;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('upserts teams and removes stale ones', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);

    ChatwootTeam::query()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_team_id' => 99,
        'name' => 'Outdated team',
        'allow_auto_assign' => false,
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/teams' => Http::response([
            ['id' => 1, 'name' => 'Vendas', 'description' => 'Time vendas', 'allow_auto_assign' => true],
            ['id' => 2, 'name' => 'Suporte', 'description' => null, 'allow_auto_assign' => false],
        ]),
    ]);

    (new SyncChatwootTeamsJob($connection->id))->handle();

    expect(ChatwootTeam::query()->where('chatwoot_connection_id', $connection->id)->count())->toBe(2)
        ->and(ChatwootTeam::query()->where('chatwoot_team_id', 99)->exists())->toBeFalse();

    $vendas = ChatwootTeam::query()->where('chatwoot_team_id', 1)->first();

    expect($vendas?->name)->toBe('Vendas')
        ->and($vendas?->allow_auto_assign)->toBeTrue()
        ->and($vendas?->workspace_id)->toBe($workspace->id);
});

it('is a no-op when connection is missing', function () {
    Http::fake();

    (new SyncChatwootTeamsJob(99999))->handle();

    Http::assertNothingSent();
});

it('is a no-op when connection has no admin_api_token', function () {
    Http::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => null,
    ]);

    (new SyncChatwootTeamsJob($connection->id))->handle();

    Http::assertNothingSent();
});
