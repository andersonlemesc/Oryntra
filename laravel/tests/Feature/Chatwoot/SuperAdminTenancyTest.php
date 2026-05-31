<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

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

it('workspace admins can write only inside their own workspace', function () {
    $admin = User::factory()->create(['is_super_admin' => false]);
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $ownProduct = Product::factory()->for($workspace)->create();
    $otherProduct = Product::factory()->for($otherWorkspace)->create();

    $workspace->users()->attach($admin, ['role' => 'admin']);

    actingAs($admin);
    Filament::setTenant($workspace);

    expect(Gate::forUser($admin)->allows('view', $ownProduct))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Product::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $ownProduct))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $ownProduct))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $otherProduct))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $otherProduct))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $otherProduct))->toBeFalse();
});

it('chatwoot agent members are read only inside their workspace', function () {
    $agent = User::factory()->create(['is_super_admin' => false]);
    $workspace = Workspace::factory()->create();
    $product = Product::factory()->for($workspace)->create();

    $workspace->users()->attach($agent, ['role' => 'member', 'chatwoot_role' => 'agent']);

    actingAs($agent);
    Filament::setTenant($workspace);

    expect(Gate::forUser($agent)->allows('viewAny', Product::class))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('view', $product))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('create', Product::class))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('update', $product))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('delete', $product))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('deleteAny', Product::class))->toBeFalse();
});

it('super admins can manage records across workspaces', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $otherProduct = Product::factory()->for($otherWorkspace)->create();

    actingAs($superAdmin);
    Filament::setTenant($workspace);

    expect(Gate::forUser($superAdmin)->allows('create', Product::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('view', $otherProduct))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $otherProduct))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $otherProduct))->toBeTrue();
});
