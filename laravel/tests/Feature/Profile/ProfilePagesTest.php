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

it('enables two-factor authentication', function () {
    actingUserWithWorkspace();

    Livewire::test(SecurityPage::class)
        ->call('enableTwoFactor')
        ->assertOk();

    expect(User::query()->first()->two_factor_secret)->not->toBeNull();
});
