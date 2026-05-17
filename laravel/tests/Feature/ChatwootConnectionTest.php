<?php

declare(strict_types=1);

use App\Enums\ChatwootConnectionStatus;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates connections associated with a workspace and database foreign key', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $connectionWorkspace = $connection->workspace;

    assert($connectionWorkspace instanceof Workspace);

    expect($connectionWorkspace)->not->toBeNull()
        ->and($connectionWorkspace->is($workspace))->toBeTrue();

    $foreignKeys = collect(Schema::getForeignKeys('chatwoot_connections'));

    expect($foreignKeys->contains(function (array $foreignKey): bool {
        return in_array('workspace_id', $foreignKey['columns'], true)
            && $foreignKey['foreign_table'] === 'workspaces'
            && in_array('id', $foreignKey['foreign_columns'], true);
    }))->toBeTrue();
});

it('encrypts Chatwoot tokens in storage and decrypts them on the model', function () {
    $connection = ChatwootConnection::factory()->create([
        'api_access_token' => 'plain-api-access-token',
        'webhook_secret' => 'plain-webhook-secret',
    ]);

    $stored = DB::table('chatwoot_connections')
        ->where('id', $connection->id)
        ->first(['api_access_token', 'webhook_secret']);

    expect($stored)->not->toBeNull();
    assert($stored instanceof stdClass);

    expect($stored->api_access_token)
        ->not->toBe('plain-api-access-token')
        ->and($stored->webhook_secret)
        ->not->toBe('plain-webhook-secret')
        ->and($connection->fresh()?->api_access_token)
        ->toBe('plain-api-access-token')
        ->and($connection->fresh()?->webhook_secret)
        ->toBe('plain-webhook-secret');
});

it('creates valid factory records with default active status and public uuid', function () {
    $connection = ChatwootConnection::factory()->create();

    expect($connection->exists)->toBeTrue()
        ->and($connection->connection_uuid)->toBeString()
        ->and($connection->status)->toBe(ChatwootConnectionStatus::Active);
});

it('returns Chatwoot authentication headers', function () {
    $connection = ChatwootConnection::factory()->create([
        'api_access_token' => 'chatwoot-token',
    ]);

    expect($connection->chatwootHeaders())->toBe([
        'api_access_token' => 'chatwoot-token',
    ]);
});
