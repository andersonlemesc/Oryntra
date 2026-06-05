<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('configures the admin panel with workspace tenancy', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getTenantModel())->toBe(Workspace::class)
        ->and($panel->getTenantRegistrationPage())->toBeNull();
});

it('allows users to access only their workspaces', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();

    $workspace->users()->attach($user, ['role' => 'owner']);
    $freshUser = $user->fresh();
    expect($freshUser)->not->toBeNull();
    assert($freshUser instanceof User);

    $tenants = $freshUser->getTenants(Filament::getPanel('admin'));

    expect($tenants)
        ->toHaveCount(1)
        ->and($tenants->first()?->id)->toBe($workspace->id)
        ->and($freshUser->canAccessTenant($workspace))->toBeTrue()
        ->and($user->canAccessTenant($otherWorkspace))->toBeFalse();
});

it('does not expose the tenant registration route', function () {
    actingAs(User::factory()->create());

    get('/admin/new')->assertNotFound();
});
