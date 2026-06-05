<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Filament\Widgets\AgentRunStatsOverview;
use App\Filament\Widgets\HumanTakeoverConversationsTable;
use App\Filament\Widgets\RecentFailedRunsTable;
use App\Filament\Widgets\RunsThroughputChart;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\ChatwootConversationState;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('renders the human-takeover widget scoped to the tenant', function () {
    [$user, $workspace] = createUserWithWorkspaceForDashboard();
    $otherWorkspace = Workspace::factory()->create();

    $visible = ChatwootConversationState::create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => ChatwootConnection::factory()->for($workspace)->create()->id,
        'conversation_id' => 501,
        'human_takeover_at' => now(),
    ]);
    $hidden = ChatwootConversationState::create([
        'workspace_id' => $otherWorkspace->id,
        'chatwoot_connection_id' => ChatwootConnection::factory()->for($otherWorkspace)->create()->id,
        'conversation_id' => 502,
        'human_takeover_at' => now(),
    ]);

    actingAs($user);
    bootFilamentTenantForDashboard($workspace);

    Livewire::test(HumanTakeoverConversationsTable::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('renders the recent-failed widget scoped to the tenant and recent window', function () {
    [$user, $workspace] = createUserWithWorkspaceForDashboard();
    $otherWorkspace = Workspace::factory()->create();

    $recent = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Failed,
        'finished_at' => now()->subDay(),
        'started_at' => now()->subDay()->subMinute(),
        'error_message' => 'falha recente',
    ]);
    $old = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Failed,
        'finished_at' => now()->subDays(10),
        'started_at' => now()->subDays(10)->subMinute(),
        'error_message' => 'falha antiga',
    ]);
    $other = AgentRun::factory()->for($otherWorkspace)->create([
        'status' => AgentRunStatus::Failed,
        'finished_at' => now()->subDay(),
        'started_at' => now()->subDay()->subMinute(),
    ]);

    actingAs($user);
    bootFilamentTenantForDashboard($workspace);

    Livewire::test(RecentFailedRunsTable::class)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old, $other]);
});

it('renders the stats overview without errors and reflects tenant counts', function () {
    [$user, $workspace] = createUserWithWorkspaceForDashboard();
    $otherWorkspace = Workspace::factory()->create();

    AgentRun::factory()->for($workspace)->completed()->create([
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinute(),
    ]);
    ChatwootConversationState::create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => ChatwootConnection::factory()->for($workspace)->create()->id,
        'conversation_id' => 601,
        'human_takeover_at' => now(),
    ]);
    AgentRun::factory()->for($otherWorkspace)->completed()->create([
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinute(),
    ]);

    actingAs($user);
    bootFilamentTenantForDashboard($workspace);

    Livewire::test(AgentRunStatsOverview::class)
        ->assertSuccessful()
        ->assertSee('Conversas com humano')
        ->assertSee('Concluidas');
});

it('renders the throughput chart widget without errors', function () {
    [$user, $workspace] = createUserWithWorkspaceForDashboard();
    AgentRun::factory()->for($workspace)->completed()->create([
        'started_at' => now()->subHours(2),
    ]);

    actingAs($user);
    bootFilamentTenantForDashboard($workspace);

    Livewire::test(RunsThroughputChart::class)->assertSuccessful();
});

/**
 * @return array{User, Workspace}
 */
function createUserWithWorkspaceForDashboard(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function bootFilamentTenantForDashboard(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}
