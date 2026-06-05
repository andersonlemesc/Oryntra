<?php

declare(strict_types=1);

use App\Filament\Pages\WorkspaceUsers;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function bootUsersTenant(string $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => $role]);

    actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();

    return [$user, $workspace];
}

it('grants access to workspace admins', function (): void {
    bootUsersTenant('admin');

    expect(WorkspaceUsers::canAccess())->toBeTrue();
});

it('denies access to members', function (): void {
    bootUsersTenant('member');

    expect(WorkspaceUsers::canAccess())->toBeFalse();
});

it('lists workspace members and resends an invitation', function (): void {
    Notification::fake();
    [, $workspace] = bootUsersTenant('admin');

    $member = User::factory()->unverified()->create(['name' => 'Maria Agente']);
    $workspace->users()->attach($member, ['role' => 'member']);

    Livewire::test(WorkspaceUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$member]) // @phpstan-ignore method.notFound
        ->callAction(TestAction::make('resendInvitation')->table($member));

    expect(UserInvitation::query()->where('user_id', $member->id)->exists())->toBeTrue();
});

it('hides resend invitation for already active members', function (): void {
    [, $workspace] = bootUsersTenant('admin');

    $active = User::factory()->create(['name' => 'João Ativo']);
    $workspace->users()->attach($active, ['role' => 'member']);

    Livewire::test(WorkspaceUsers::class)
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('resendInvitation')->table($active)); // @phpstan-ignore method.notFound
});

it('marks a super admin as active even without email verification', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true, 'email_verified_at' => null]);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($admin, ['role' => 'admin']);

    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();

    Livewire::test(WorkspaceUsers::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('active', 'Sim', record: $admin); // @phpstan-ignore method.notFound
});
