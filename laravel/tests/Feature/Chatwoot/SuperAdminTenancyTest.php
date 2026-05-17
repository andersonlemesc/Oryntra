<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('super_admin sees all workspaces via getTenants', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);

    Workspace::factory()->count(3)->create();

    $tenants = $admin->getTenants(Filament::getPanel('admin'));

    expect($tenants)->toHaveCount(3);
});

it('super_admin can access any workspace', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $workspace = Workspace::factory()->create();

    expect($admin->canAccessTenant($workspace))->toBeTrue();
});

it('non-super_admin only sees its own workspaces', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $own = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    $own->users()->attach($user, ['role' => 'admin']);

    $fresh = $user->fresh();
    assert($fresh instanceof User);
    $tenants = $fresh->getTenants(Filament::getPanel('admin'));

    expect($tenants)->toHaveCount(1)
        ->and($tenants->first()?->id)->toBe($own->id)
        ->and($user->canAccessTenant($other))->toBeFalse();
});

it('first registered user becomes super_admin', function () {
    $action = app(CreateNewUser::class);

    $first = $action->create([
        'name' => 'Anderson',
        'email' => 'anderson@oryntra.test',
        'password' => 'Sup3rSecure!',
        'password_confirmation' => 'Sup3rSecure!',
    ]);

    $second = $action->create([
        'name' => 'Other',
        'email' => 'other@oryntra.test',
        'password' => 'Sup3rSecure!',
        'password_confirmation' => 'Sup3rSecure!',
    ]);

    expect($first->isSuperAdmin())->toBeTrue()
        ->and($second->isSuperAdmin())->toBeFalse();
});
