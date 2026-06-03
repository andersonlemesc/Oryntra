<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\FinalizeAgentRunJob;
use App\Models\AgentRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.agent_runtime.internal_token' => 'super-secret']);
});

it('rejects requests without the internal runtime token', function () {
    $run = AgentRun::factory()->for(Workspace::factory())->create([
        'status' => AgentRunStatus::Running,
    ]);

    postJson(route('internal.agent-runs.result', $run), [
        'status' => 'completed',
    ])->assertForbidden();
});

it('validates the result payload', function () {
    $run = AgentRun::factory()->for(Workspace::factory())->create([
        'status' => AgentRunStatus::Running,
    ]);

    postJson(
        route('internal.agent-runs.result', $run),
        ['status' => 'bogus'],
        ['X-Internal-Token' => 'super-secret'],
    )->assertStatus(422)->assertJsonValidationErrors(['status']);
});

it('accepts a completed result and queues finalization', function () {
    Bus::fake();

    $run = AgentRun::factory()->for(Workspace::factory())->create([
        'status' => AgentRunStatus::Running,
    ]);

    postJson(
        route('internal.agent-runs.result', $run),
        [
            'status' => 'completed',
            'response' => ['type' => 'text', 'content' => 'Ola', 'confidence' => 1.0],
            'trace' => [],
            'usage' => [],
        ],
        ['X-Internal-Token' => 'super-secret'],
    )->assertStatus(202)->assertJson([
        'accepted' => true,
        'idempotent' => false,
        'run_id' => $run->id,
    ]);

    Bus::assertDispatched(FinalizeAgentRunJob::class, fn (FinalizeAgentRunJob $job): bool => $job->agentRunId === $run->id
        && ($job->runtimeResult['status'] ?? null) === 'completed');
});

it('is idempotent and does not re-queue for terminal runs', function () {
    Bus::fake();

    $run = AgentRun::factory()->for(Workspace::factory())->completed()->create();

    postJson(
        route('internal.agent-runs.result', $run),
        ['status' => 'completed', 'response' => ['type' => 'text', 'content' => 'x', 'confidence' => 1.0]],
        ['X-Internal-Token' => 'super-secret'],
    )->assertOk()->assertJson([
        'idempotent' => true,
        'status' => AgentRunStatus::Completed->value,
        'run_id' => $run->id,
    ]);

    Bus::assertNotDispatched(FinalizeAgentRunJob::class);
});
