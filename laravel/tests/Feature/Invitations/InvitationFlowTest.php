<?php

declare(strict_types=1);

use App\Actions\Invitations\SendUserInvitation;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('redirects the root path to the login page for guests when users exist', function () {
    User::factory()->create();

    get('/')
        ->assertRedirect(route('login'));
});

it('redirects the root path to /register when no users exist', function () {
    get('/')
        ->assertRedirect('/register');
});

it('redirects the root path to admin for authenticated users', function () {
    $user = User::factory()->create();

    actingAs($user);

    get('/')
        ->assertRedirect('/admin');
});

it('creates an invitation row, marks last_invitation_sent_at and dispatches notification', function () {
    Notification::fake();
    $user = User::factory()->create();

    $invitation = app(SendUserInvitation::class)->execute($user, source: 'manual');

    expect($invitation)->toBeInstanceOf(UserInvitation::class)
        ->and($invitation->source)->toBe('manual')
        ->and($invitation->user_id)->toBe($user->id)
        ->and(mb_strlen($invitation->token))->toBe(64)
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($user->fresh()?->last_invitation_sent_at)->not->toBeNull();

    Notification::assertSentTo(
        $user,
        UserInvitationNotification::class,
        fn (UserInvitationNotification $notification): bool => $notification->queue === 'emails'
    );
});

it('GET /accept-invitation/{token} shows form for usable invitation', function () {
    $user = User::factory()->create();
    $invitation = app(SendUserInvitation::class)->execute($user);

    get("/accept-invitation/{$invitation->token}")
        ->assertOk()
        ->assertSee('Ativar conta');
});

it('GET /accept-invitation/{token} shows form even when another user is authenticated', function () {
    $authenticatedUser = User::factory()->create();
    $invitedUser = User::factory()->create();
    $invitation = app(SendUserInvitation::class)->execute($invitedUser);

    actingAs($authenticatedUser);

    get("/accept-invitation/{$invitation->token}")
        ->assertOk()
        ->assertSee('Ativar conta');
});

it('GET /accept-invitation/{token} redirects to login on invalid token', function () {
    get('/accept-invitation/' . str_repeat('x', 64))
        ->assertRedirect(route('login'));
});

it('POST /accept-invitation/{token} sets password, marks accepted and logs user in', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => bcrypt('untouched')]);
    $invitation = app(SendUserInvitation::class)->execute($user);

    post("/accept-invitation/{$invitation->token}", [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'NewSecret123!',
    ])->assertRedirect('/admin');

    $invitation->refresh();
    $userFresh = $user->fresh();

    assert($userFresh instanceof User);

    expect($invitation->isAccepted())->toBeTrue()
        ->and(Hash::check('NewSecret123!', $userFresh->password))->toBeTrue()
        ->and($userFresh->email_verified_at)->not->toBeNull()
        ->and(Auth::id())->toBe($user->id);
});

it('rejects accept when invitation already accepted', function () {
    $user = User::factory()->create();
    $invitation = app(SendUserInvitation::class)->execute($user);
    $invitation->markAccepted();

    post("/accept-invitation/{$invitation->token}", [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'NewSecret123!',
    ])->assertRedirect(route('login'));
});

it('rejects accept when invitation expired', function () {
    $user = User::factory()->create();
    $invitation = app(SendUserInvitation::class)->execute($user);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    post("/accept-invitation/{$invitation->token}", [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'NewSecret123!',
    ])->assertRedirect(route('login'));
});

it('validates password rules and confirmation', function () {
    $user = User::factory()->create();
    $invitation = app(SendUserInvitation::class)->execute($user);

    post("/accept-invitation/{$invitation->token}", [
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertSessionHasErrors('password');

    expect($invitation->fresh()?->isAccepted())->toBeFalse();
});
