<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\FinalizeAgentRunJob;
use App\Models\AgentRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\artisan;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.agent_runtime.run_timeout' => 180]);
});

it('reaps runs stuck in Running past the timeout', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();

    $stuck = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now()->subSeconds(600),
    ]);

    $fresh = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now()->subSeconds(10),
    ]);

    artisan('agent:reap-stuck-runs')->assertSuccessful();

    Bus::assertDispatched(FinalizeAgentRunJob::class, fn (FinalizeAgentRunJob $job): bool => $job->agentRunId === $stuck->id
        && ($job->runtimeResult['status'] ?? null) === AgentRunStatus::Failed->value
        && ($job->runtimeResult['error'] ?? null) === 'runtime_timeout');

    Bus::assertNotDispatched(FinalizeAgentRunJob::class, fn (FinalizeAgentRunJob $job): bool => $job->agentRunId === $fresh->id);
});

it('does nothing when there are no stuck runs', function () {
    Bus::fake();

    artisan('agent:reap-stuck-runs')->assertSuccessful();

    Bus::assertNotDispatched(FinalizeAgentRunJob::class);
});
