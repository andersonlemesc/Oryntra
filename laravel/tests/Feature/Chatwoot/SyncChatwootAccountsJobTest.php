<?php

use App\Jobs\Chatwoot\SyncChatwootAccountsJob;
use App\Models\ChatwootPlatformConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);
});

it('skips execution when platform connection is not configured', function () {
    DB::table('chatwoot_platform_connections')->truncate();

    Http::fake();

    (new SyncChatwootAccountsJob)->handle();

    expect(Workspace::count())->toBe(0);
    Http::assertNothingSent();
});

it('upserts workspaces and users from platform API responses', function () {
    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 1, 'name' => 'Empresa A'],
            ['id' => 2, 'name' => 'Empresa B'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/1/account_users' => Http::response([
            ['user_id' => 10, 'role' => 'administrator', 'user' => ['email' => 'ada@empresa-a.com', 'name' => 'Ada Lovelace']],
            ['user_id' => 11, 'role' => 'agent', 'user' => ['email' => 'bob@empresa-a.com', 'name' => 'Bob']],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/2/account_users' => Http::response([
            ['user_id' => 20, 'role' => 'administrator', 'user' => ['email' => 'carol@empresa-b.com', 'name' => 'Carol']],
        ], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();

    $workspaceA = Workspace::where('chatwoot_account_id', 1)->first();
    $workspaceB = Workspace::where('chatwoot_account_id', 2)->first();

    assert($workspaceA instanceof Workspace);
    assert($workspaceB instanceof Workspace);

    expect($workspaceA->name)->toBe('Empresa A')
        ->and($workspaceB->name)->toBe('Empresa B');

    expect(User::where('email', 'ada@empresa-a.com')->exists())->toBeTrue()
        ->and(User::where('email', 'bob@empresa-a.com')->exists())->toBeTrue()
        ->and(User::where('email', 'carol@empresa-b.com')->exists())->toBeTrue();

    $adaId = User::where('email', 'ada@empresa-a.com')->value('id');
    $bobId = User::where('email', 'bob@empresa-a.com')->value('id');

    expect(DB::table('workspace_members')->where([
        'workspace_id' => $workspaceA->id,
        'user_id' => $adaId,
        'role' => 'admin',
    ])->exists())->toBeTrue();

    expect(DB::table('workspace_members')->where([
        'workspace_id' => $workspaceA->id,
        'user_id' => $bobId,
        'role' => 'member',
    ])->exists())->toBeTrue();

    $connection = ChatwootPlatformConnection::current();
    expect($connection->last_sync_status)->toBe('success')
        ->and($connection->last_sync_summary)->toMatchArray([
            'accounts_seen' => 2,
            'workspaces_upserted' => 2,
            'users_upserted' => 3,
            'members_upserted' => 3,
        ]);
});

it('records error status when API call fails', function () {
    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response('forbidden', 403),
    ]);

    expect(fn () => (new SyncChatwootAccountsJob)->handle())
        ->toThrow(RuntimeException::class);

    $connection = ChatwootPlatformConnection::current();
    expect($connection->last_sync_status)->toBe('error')
        ->and($connection->last_sync_error)->toContain('HTTP 403');
});

it('does not duplicate workspaces on re-sync', function () {
    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 1, 'name' => 'Empresa A'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/1/account_users' => Http::response([], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();
    (new SyncChatwootAccountsJob)->handle();

    expect(Workspace::where('chatwoot_account_id', 1)->count())->toBe(1);
});
