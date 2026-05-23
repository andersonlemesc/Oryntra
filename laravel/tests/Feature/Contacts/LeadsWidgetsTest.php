<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentContactsTable;
use App\Filament\Widgets\RecentLeadsStatsOverview;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('counts new leads from the last 24 hours per workspace', function () {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    Contact::factory()->count(3)->create([
        'workspace_id' => $workspaceA->id,
        'first_seen_at' => now()->subHours(5),
    ]);
    Contact::factory()->count(2)->create([
        'workspace_id' => $workspaceA->id,
        'first_seen_at' => now()->subDays(2),
    ]);
    Contact::factory()->count(1)->create([
        'workspace_id' => $workspaceA->id,
        'lead_status' => 'qualified',
        'first_seen_at' => now()->subWeek(),
    ]);
    Contact::factory()->count(10)->create([
        'workspace_id' => $workspaceB->id,
        'first_seen_at' => now()->subHours(2),
    ]);

    $user = User::factory()->create();
    $user->workspaces()->attach($workspaceA->id, ['role' => 'owner']);

    actingAs($user);
    Filament::setTenant($workspaceA);

    $stats = invadeProtected(new RecentLeadsStatsOverview, 'getStats');

    $values = array_map(fn ($stat) => $stat->getValue(), $stats);

    expect($values)->toContain('3')
        ->and($values)->toContain('1');
});

it('lists only contacts of the current tenant in RecentContactsTable', function () {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    Contact::factory()->count(2)->create([
        'workspace_id' => $workspaceA->id,
        'last_message_at' => now()->subHours(1),
    ]);
    Contact::factory()->count(5)->create([
        'workspace_id' => $workspaceB->id,
        'last_message_at' => now()->subHours(1),
    ]);

    $user = User::factory()->create();
    $user->workspaces()->attach($workspaceA->id, ['role' => 'owner']);

    actingAs($user);
    Filament::setTenant($workspaceA);

    $query = invadeProtected(new RecentContactsTable, 'getQuery');

    expect($query->count())->toBe(2);
});

function invadeProtected(object $object, string $method): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object);
}
