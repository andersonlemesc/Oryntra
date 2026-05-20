<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Filament\Resources\AgentRuns\AgentRunResource;
use App\Filament\Resources\AgentRuns\Pages\ListAgentRuns;
use App\Filament\Resources\AgentRuns\Pages\ViewAgentRun;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('lists only agent runs for the current Filament tenant', function () {
    [$user, $workspace] = createUserWithWorkspaceForAgentRuns();
    $otherWorkspace = Workspace::factory()->create();
    $visibleRun = AgentRun::factory()->for($workspace)->create();
    $hiddenRun = AgentRun::factory()->for($otherWorkspace)->create();

    actingAs($user);
    bootFilamentTenantForAgentRuns($workspace);

    Livewire::test(ListAgentRuns::class)
        ->assertCanSeeTableRecords([$visibleRun])
        ->assertCanNotSeeTableRecords([$hiddenRun]);
});

it('filters runs by the waiting_human toggle filter', function () {
    [$user, $workspace] = createUserWithWorkspaceForAgentRuns();
    $waitingRun = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'started_at' => now(),
    ]);
    $completedRun = AgentRun::factory()->for($workspace)->completed()->create();

    actingAs($user);
    bootFilamentTenantForAgentRuns($workspace);

    Livewire::test(ListAgentRuns::class)
        ->filterTable('waiting_human')
        ->assertCanSeeTableRecords([$waitingRun])
        ->assertCanNotSeeTableRecords([$completedRun]);
});

it('renders the view page with handoff payload and side-effect badges', function () {
    [$user, $workspace] = createUserWithWorkspaceForAgentRuns();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'started_at' => now()->subMinute(),
        'output' => [
            'handoff' => [
                'reason' => 'Cliente pediu cancelamento.',
                'priority' => 'high',
                'suggested_team' => 'suporte',
                'customer_message' => 'Vou transferir voce para um atendente.',
                'private_note' => 'Resumo interno do handoff.',
                'side_effects' => [
                    'status' => 'queued',
                    'job_id' => 'job-123',
                    'attempted_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'error' => null,
                    'actions' => [
                        'customer_message' => 'completed',
                        'private_note' => 'pending',
                        'label' => 'completed',
                        'team_assignment' => 'pending',
                        'agent_assignment' => 'skipped',
                    ],
                ],
            ],
        ],
    ]);

    actingAs($user);
    bootFilamentTenantForAgentRuns($workspace);

    Livewire::test(ViewAgentRun::class, ['record' => $run->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Cliente pediu cancelamento.')
        ->assertSee('Vou transferir voce para um atendente.')
        ->assertSee('Resumo interno do handoff.');
});

it('scopes the resource query to the active tenant', function () {
    [$user, $workspace] = createUserWithWorkspaceForAgentRuns();
    $otherWorkspace = Workspace::factory()->create();
    $visibleRun = AgentRun::factory()->for($workspace)->create();
    $hiddenRun = AgentRun::factory()->for($otherWorkspace)->create();

    actingAs($user);
    bootFilamentTenantForAgentRuns($workspace);

    $ids = AgentRunResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($visibleRun->id);
    expect($ids)->not->toContain($hiddenRun->id);
});

/**
 * @return array{User, Workspace}
 */
function createUserWithWorkspaceForAgentRuns(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function bootFilamentTenantForAgentRuns(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}
