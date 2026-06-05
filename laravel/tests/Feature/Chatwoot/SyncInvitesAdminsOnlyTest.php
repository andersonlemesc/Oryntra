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

uses(TestCase::class, RefreshDatabase::class);

function fakeChatwootPlatform(): void
{
    ChatwootPlatformConnection::query()->create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'secret-token',
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/accounts/7001/account_users' => Http::response([
            ['user_id' => 501, 'role' => 'administrator'],
            ['user_id' => 502, 'role' => 'agent'],
        ]),
        'https://chatwoot.test/platform/api/v1/accounts' => Http::response([
            ['id' => 7001, 'name' => 'Acme'],
        ]),
        'https://chatwoot.test/platform/api/v1/users/501' => Http::response([
            'id' => 501, 'email' => 'admin@acme.test', 'name' => 'Acme Admin',
            'confirmed' => true, 'availability_status' => 'online',
        ]),
        'https://chatwoot.test/platform/api/v1/users/502' => Http::response([
            'id' => 502, 'email' => 'agent@acme.test', 'name' => 'Acme Agent',
            'confirmed' => true, 'availability_status' => 'online',
        ]),
    ]);
}

it('auto-invites only admins on sync, never agents', function (): void {
    Notification::fake();
    fakeChatwootPlatform();

    (new SyncChatwootAccountsJob)->handle();

    $admin = User::query()->where('email', 'admin@acme.test')->firstOrFail();
    $agent = User::query()->where('email', 'agent@acme.test')->firstOrFail();

    expect(UserInvitation::query()->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(UserInvitation::query()->where('user_id', $agent->id)->exists())->toBeFalse();

    Notification::assertSentTo($admin, UserInvitationNotification::class);
    Notification::assertNotSentTo($agent, UserInvitationNotification::class);
});

it('counts agents pending invite in the sync summary', function (): void {
    Notification::fake();
    fakeChatwootPlatform();

    (new SyncChatwootAccountsJob)->handle();

    $summary = ChatwootPlatformConnection::current()->last_sync_summary;

    expect($summary['invites_sent'])->toBe(1)
        ->and($summary['agents_pending_invite'])->toBe(1);
});
