<?php

declare(strict_types=1);

use App\Jobs\Chatwoot\SyncChatwootLabelsJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootLabel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('upserts labels and removes stale ones', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);

    ChatwootLabel::query()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_label_id' => 999,
        'title' => 'outdated-label',
        'show_on_sidebar' => true,
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/labels' => Http::response([
            'payload' => [
                ['id' => 1, 'title' => 'resolved-by-ia', 'description' => 'IA encerrou', 'color' => '#1F93FF', 'show_on_sidebar' => true],
                ['id' => 2, 'title' => 'vendas', 'description' => null, 'color' => '#FF0000', 'show_on_sidebar' => false],
            ],
        ]),
    ]);

    (new SyncChatwootLabelsJob($connection->id))->handle();

    expect(ChatwootLabel::query()->where('chatwoot_connection_id', $connection->id)->count())->toBe(2)
        ->and(ChatwootLabel::query()->where('title', 'outdated-label')->exists())->toBeFalse();

    $resolved = ChatwootLabel::query()->where('title', 'resolved-by-ia')->first();

    expect($resolved?->chatwoot_label_id)->toBe(1)
        ->and($resolved?->color)->toBe('#1F93FF')
        ->and($resolved?->show_on_sidebar)->toBeTrue()
        ->and($resolved?->workspace_id)->toBe($workspace->id);
});

it('skips labels with blank titles', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/labels' => Http::response([
            'payload' => [
                ['id' => 1, 'title' => 'vendas'],
                ['id' => 2, 'title' => ''],
                ['id' => 3, 'title' => null],
            ],
        ]),
    ]);

    (new SyncChatwootLabelsJob($connection->id))->handle();

    expect(ChatwootLabel::query()->where('chatwoot_connection_id', $connection->id)->count())->toBe(1)
        ->and(ChatwootLabel::query()->where('title', 'vendas')->exists())->toBeTrue();
});

it('is a no-op when connection is missing', function () {
    Http::fake();

    (new SyncChatwootLabelsJob(99999))->handle();

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

    (new SyncChatwootLabelsJob($connection->id))->handle();

    Http::assertNothingSent();
});
