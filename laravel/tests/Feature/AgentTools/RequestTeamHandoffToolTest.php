<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ApplyHumanHandoffToChatwootJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects team handoff requests without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/request-team-handoff', [])
        ->assertForbidden();
});

it('dispatches team handoff and marks run completed', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Http::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['request_team_handoff']]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/request-team-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Cliente quer falar com vendas.',
        'priority' => 'normal',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'handoff_dispatched',
            'handoff_id' => $run->id,
            'message' => 'Team handoff dispatched to Chatwoot.',
        ]);

    $run->refresh();
    $output = $run->getAttribute('output');

    assert(is_array($output));

    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($output['handoff']['target_type'])->toBe('team')
        ->and($output['handoff']['reason'])->toBe('Cliente quer falar com vendas.')
        ->and($output['handoff']['side_effects']['actions']['open_conversation'])->toBe('pending');

    Queue::assertPushed(
        ApplyHumanHandoffToChatwootJob::class,
        fn (ApplyHumanHandoffToChatwootJob $job): bool => $job->agentRunId === $run->id,
    );
});

it('rejects team handoff when tool not in allowlist', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['request_human_handoff']]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/request-team-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Cliente pediu time.',
        'priority' => 'normal',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();

    Queue::assertNothingPushed();
});
