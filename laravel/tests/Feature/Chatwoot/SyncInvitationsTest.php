<?php

declare(strict_types=1);

use App\Jobs\Chatwoot\SyncChatwootAccountsJob;
use App\Models\ChatwootPlatformConnection;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);
});

it('dispatches invitation only for newly created admin users on sync', function () {
    Notification::fake();

    // existing user — should NOT receive invitation
    $existing = User::create([
        'name' => 'Existing',
        'email' => 'existing@oryntra.test',
        'password' => bcrypt('secret'),
        'is_super_admin' => true,
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 1, 'name' => 'Acc 1'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/1/account_users' => Http::response([
            ['id' => 1, 'user_id' => 10, 'account_id' => 1, 'role' => 'administrator'],
            ['id' => 2, 'user_id' => 11, 'account_id' => 1, 'role' => 'administrator'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/10' => Http::response([
            'id' => 10, 'email' => 'existing@oryntra.test', 'name' => 'Existing',
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/11' => Http::response([
            'id' => 11, 'email' => 'new@oryntra.test', 'name' => 'New Person',
        ], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();

    $newUser = User::where('email', 'new@oryntra.test')->first();
    assert($newUser instanceof User);

    Notification::assertSentTo($newUser, UserInvitationNotification::class);
    Notification::assertNotSentTo($existing, UserInvitationNotification::class);

    expect(UserInvitation::where('user_id', $newUser->id)->count())->toBe(1)
        ->and(UserInvitation::where('user_id', $existing->id)->count())->toBe(0);

    $connection = ChatwootPlatformConnection::current();
    expect($connection->last_sync_summary['invites_sent'] ?? null)->toBe(1);
});

it('skips invitations when send_on_sync is disabled', function () {
    config(['invitations.send_on_sync' => false]);
    Notification::fake();

    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 1, 'name' => 'Acc 1'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/accounts/1/account_users' => Http::response([
            ['id' => 1, 'user_id' => 10, 'account_id' => 1, 'role' => 'administrator'],
        ], 200),
        'https://chatwoot.test/platform/api/v1/users/10' => Http::response([
            'id' => 10, 'email' => 'new2@oryntra.test', 'name' => 'New 2',
        ], 200),
    ]);

    (new SyncChatwootAccountsJob)->handle();

    Notification::assertNothingSent();
    expect(UserInvitation::count())->toBe(0);
});
