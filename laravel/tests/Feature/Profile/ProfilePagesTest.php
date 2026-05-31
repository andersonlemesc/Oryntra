<?php

declare(strict_types=1);

use App\Filament\Pages\Profile\ApiTokensPage;
use App\Filament\Pages\Profile\ProfilePage;
use App\Filament\Pages\Profile\SecurityPage;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function actingUserWithWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);
    actingAs($user);
    Filament::setTenant($workspace);

    return [$user, $workspace];
}

it('renders the profile page', function () {
    actingUserWithWorkspace();

    Livewire::test(ProfilePage::class)->assertOk();
});

it('shows current workspace and chatwoot membership on the profile page', function () {
    $user = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);
    $workspace = Workspace::factory()->create([
        'name' => 'Acme Support',
        'chatwoot_account_id' => 42,
    ]);
    $workspace->users()->attach($user, [
        'role' => 'admin',
        'chatwoot_user_id' => 321,
        'chatwoot_availability' => 'online',
        'chatwoot_confirmed' => true,
        'chatwoot_role' => 'administrator',
    ]);

    actingAs($user);
    Filament::setTenant($workspace);

    Livewire::test(ProfilePage::class)
        ->assertSet('data.name', 'Ada Lovelace')
        ->assertSet('data.email', 'ada@example.com')
        ->assertSet('data.workspace_name', 'Acme Support')
        ->assertSet('data.workspace_role', 'Admin')
        ->assertSet('data.chatwoot_account_id', '42')
        ->assertSet('data.chatwoot_user_id', '321')
        ->assertSet('data.chatwoot_role', 'administrator')
        ->assertSet('data.chatwoot_availability', 'Online')
        ->assertSet('data.chatwoot_confirmed', 'Sim');
});

it('does not update name or email from the read only profile page', function () {
    [$user] = actingUserWithWorkspace();

    Livewire::test(ProfilePage::class)
        ->set('data.name', 'Changed Name')
        ->set('data.email', 'changed@example.com')
        ->call('save')
        ->assertOk();

    $user->refresh();

    expect($user->name)->not->toBe('Changed Name')
        ->and($user->email)->not->toBe('changed@example.com');
});

it('renders the security page', function () {
    actingUserWithWorkspace();

    Livewire::test(SecurityPage::class)->assertOk();
});

it('generates and revokes an API token from the page', function () {
    [$user, $workspace] = actingUserWithWorkspace();

    $component = Livewire::test(ApiTokensPage::class);

    $component->call('createToken', [
        'name' => 'My MCP',
        'workspace_id' => $workspace->id,
        'abilities' => ['agent:read', 'agent:write'],
    ]);

    $token = ApiToken::query()->where('name', 'My MCP')->first();

    expect($token)->not->toBeNull()
        ->and($token->workspace_id)->toBe($workspace->id)
        ->and($token->abilities)->toBe(['agent:read', 'agent:write']);

    $component->call('revokeToken', $token->id);

    expect(ApiToken::query()->whereKey($token->id)->exists())->toBeFalse();
});

it('does not let read only workspace members create write api tokens', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'member', 'chatwoot_role' => 'agent']);
    actingAs($user);
    Filament::setTenant($workspace);

    Livewire::test(ApiTokensPage::class)
        ->call('createToken', [
            'name' => 'Read only MCP',
            'workspace_id' => $workspace->id,
            'abilities' => ['agent:read', 'agent:write'],
        ])
        ->assertOk();

    expect(ApiToken::query()->where('name', 'Read only MCP')->exists())->toBeFalse();
});

it('lets read only workspace members create read api tokens', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'member', 'chatwoot_role' => 'agent']);
    actingAs($user);
    Filament::setTenant($workspace);

    Livewire::test(ApiTokensPage::class)
        ->call('createToken', [
            'name' => 'Read MCP',
            'workspace_id' => $workspace->id,
            'abilities' => ['agent:read'],
        ])
        ->assertOk();

    $token = ApiToken::query()->where('name', 'Read MCP')->first();

    expect($token)->not->toBeNull()
        ->and($token?->abilities)->toBe(['agent:read']);
});

it('enables two-factor authentication', function () {
    actingUserWithWorkspace();

    Livewire::test(SecurityPage::class)
        ->call('enableTwoFactor')
        ->assertOk();

    expect(User::query()->first()->two_factor_secret)->not->toBeNull();
});
