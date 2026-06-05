<?php

declare(strict_types=1);

use App\Filament\Resources\ChatwootConnections\ChatwootConnectionResource;
use App\Filament\Resources\ChatwootConnections\Pages\ListChatwootConnections;
use App\Models\ChatwootConnection;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('lists only Chatwoot connections for the current Filament tenant', function () {
    [$user, $workspace] = createUserWithWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $visibleConnection = ChatwootConnection::factory()->for($workspace)->create();
    $hiddenConnection = ChatwootConnection::factory()->for($otherWorkspace)->create();

    actingAs($user);
    bootFilamentTenant($workspace);

    Livewire::test(ListChatwootConnections::class)
        ->assertCanSeeTableRecords([$visibleConnection])
        ->assertCanNotSeeTableRecords([$hiddenConnection]);
});

it('does not expose another workspace connection through the tenant scoped resource query', function () {
    [$user, $workspace] = createUserWithWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $visibleConnection = ChatwootConnection::factory()->for($workspace)->create();
    $hiddenConnection = ChatwootConnection::factory()->for($otherWorkspace)->create();

    actingAs($user);
    bootFilamentTenant($workspace);

    $connectionIds = ChatwootConnectionResource::getEloquentQuery()
        ->pluck('id')
        ->all();

    expect($connectionIds)->toContain($visibleConnection->id);
    expect($connectionIds)->not->toContain($hiddenConnection->id);
});

it('prevents a user from another workspace from seeing this tenant connection', function () {
    $workspace = Workspace::factory()->create();
    $protectedConnection = ChatwootConnection::factory()->for($workspace)->create();
    [$otherUser, $otherWorkspace] = createUserWithWorkspace();

    actingAs($otherUser);
    bootFilamentTenant($otherWorkspace);

    $connectionIds = ChatwootConnectionResource::getEloquentQuery()
        ->pluck('id')
        ->all();

    expect($otherUser->canAccessTenant($workspace))->toBeFalse();
    expect($connectionIds)->not->toContain($protectedConnection->id);
});

/**
 * @return array{User, Workspace}
 */
function createUserWithWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function bootFilamentTenant(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}
