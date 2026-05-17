<?php

use App\Filament\Pages\Tenancy\RegisterWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('configures the admin panel with workspace tenancy', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getTenantModel())->toBe(Workspace::class)
        ->and($panel->getTenantRegistrationPage())->toBe(RegisterWorkspace::class);
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

it('renders the tenant registration page for authenticated users', function () {
    $user = User::factory()->create();

    actingAs($user);

    get('/admin/new')
        ->assertOk()
        ->assertSee('Criar workspace');
});

it('creates a workspace from tenant registration', function () {
    $user = User::factory()->create();

    actingAs($user);

    $workspace = invokeRegisterWorkspaceHandler([
        'name' => 'Acme Atendimento',
    ]);

    $freshUser = $user->fresh();
    expect($freshUser)->not->toBeNull();
    assert($freshUser instanceof User);

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($workspace->name)->toBe('Acme Atendimento')
        ->and($workspace->slug)->toBe('acme-atendimento')
        ->and($workspace->chatwoot_account_id)->toBeNull()
        ->and($freshUser->canAccessTenant($workspace))->toBeTrue()
        ->and(Auth::id())->toBe($user->id);

    assertDatabaseHas('workspace_members', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
});

/**
 * @param  array{name: string}  $data
 */
function invokeRegisterWorkspaceHandler(array $data): Workspace
{
    $page = new RegisterWorkspace();
    $method = new ReflectionMethod(RegisterWorkspace::class, 'handleRegistration');
    $method->setAccessible(true);

    /** @var Workspace $workspace */
    $workspace = $method->invoke($page, $data);

    return $workspace;
}
