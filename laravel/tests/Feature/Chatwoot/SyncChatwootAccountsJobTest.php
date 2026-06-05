<?php

declare(strict_types=1);

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
            ['id' => 1, 'user_id' => 10, 'account_id' => 1, 'role' => 'administrator'],
            ['id' => 2, 'user_id' => 11, 'account_id' => 1, 'role' => 'agent'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/2/account_users' => Http::response([
            ['id' => 3, 'user_id' => 20, 'account_id' => 2, 'role' => 'administrator'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/10' => Http::response([
            'id' => 10, 'email' => 'ada@empresa-a.com', 'name' => 'Ada Lovelace',
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/11' => Http::response([
            'id' => 11, 'email' => 'bob@empresa-a.com', 'name' => 'Bob',
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/20' => Http::response([
            'id' => 20, 'email' => 'carol@empresa-b.com', 'name' => 'Carol',
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

    expect(User::where('email', 'ada@empresa-a.com')->first()?->isSuperAdmin())->toBeFalse()
        ->and(User::where('email', 'bob@empresa-a.com')->first()?->isSuperAdmin())->toBeFalse()
        ->and(User::where('email', 'carol@empresa-b.com')->first()?->isSuperAdmin())->toBeFalse();

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

it('does not overwrite name/is_super_admin of existing user matched by email', function () {
    $existing = User::create([
        'name' => 'Anderson Customized',
        'email' => 'anderson@oryntra.test',
        'password' => bcrypt('secret-original'),
        'is_super_admin' => true,
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 7, 'name' => 'Account 7'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/7/account_users' => Http::response([
            ['id' => 1, 'user_id' => 70, 'account_id' => 7, 'role' => 'administrator'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/70' => Http::response([
            'id' => 70, 'email' => 'anderson@oryntra.test', 'name' => 'Anderson Lemes',
        ], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();

    $fresh = $existing->fresh();
    assert($fresh instanceof User);

    expect(User::where('email', 'anderson@oryntra.test')->count())->toBe(1)
        ->and($fresh->name)->toBe('Anderson Customized')
        ->and($fresh->isSuperAdmin())->toBeTrue();

    $workspace = Workspace::where('chatwoot_account_id', 7)->first();
    assert($workspace instanceof Workspace);

    expect(DB::table('workspace_members')->where([
        'workspace_id' => $workspace->id,
        'user_id' => $existing->id,
        'role' => 'admin',
    ])->exists())->toBeTrue();
});

it('skips users that are not permissible (HTTP 403 on getUser)', function () {
    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 1, 'name' => 'Mixed Account'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/1/account_users' => Http::response([
            ['id' => 1, 'user_id' => 100, 'account_id' => 1, 'role' => 'administrator'],
            ['id' => 2, 'user_id' => 200, 'account_id' => 1, 'role' => 'agent'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/100' => Http::response(
            ['error' => 'Non permissible resource'], 403
        ),
        'https://chatwoot.test/platform/api/v1/users/200' => Http::response([
            'id' => 200, 'email' => 'visible@test.com', 'name' => 'Visible User',
        ], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();

    $workspace = Workspace::where('chatwoot_account_id', 1)->first();
    assert($workspace instanceof Workspace);

    expect(User::where('email', 'visible@test.com')->exists())->toBeTrue()
        ->and($workspace->users()->count())->toBe(1);

    $connection = ChatwootPlatformConnection::current();
    expect($connection->last_sync_summary['users_upserted'] ?? null)->toBe(1)
        ->and($connection->last_sync_summary['users_skipped'] ?? null)->toBe(1);
});
