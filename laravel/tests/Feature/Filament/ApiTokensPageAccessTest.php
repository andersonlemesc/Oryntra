<?php

declare(strict_types=1);

use App\Filament\Pages\Profile\ApiTokensPage;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bootTenantAs(string $role): void
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => $role]);

    actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}

it('lets workspace admins access the API tokens page', function () {
    bootTenantAs('admin');

    expect(ApiTokensPage::canAccess())->toBeTrue();
});

it('hides the API tokens page from agents', function () {
    bootTenantAs('member');

    expect(ApiTokensPage::canAccess())->toBeFalse();
});
