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

    // Keep this as a temporary so PendingCommand runs the command immediately on
    // destruct (before the Bus assertions below); holding it in a variable would
    // defer execution to end of test. phpstan can't narrow the PendingCommand|int
    // union from the artisan() helper, so silence it here.
    artisan('agent:reap-stuck-runs')->assertSuccessful(); // @phpstan-ignore method.nonObject

    Bus::assertDispatched(FinalizeAgentRunJob::class, fn (FinalizeAgentRunJob $job): bool => $job->agentRunId === $stuck->id
        && ($job->runtimeResult['status'] ?? null) === AgentRunStatus::Failed->value
        && ($job->runtimeResult['error'] ?? null) === 'runtime_timeout');

    Bus::assertNotDispatched(FinalizeAgentRunJob::class, fn (FinalizeAgentRunJob $job): bool => $job->agentRunId === $fresh->id);
});

it('does nothing when there are no stuck runs', function () {
    Bus::fake();

    // Keep this as a temporary so PendingCommand runs the command immediately on
    // destruct (before the Bus assertions below); holding it in a variable would
    // defer execution to end of test. phpstan can't narrow the PendingCommand|int
    // union from the artisan() helper, so silence it here.
    artisan('agent:reap-stuck-runs')->assertSuccessful(); // @phpstan-ignore method.nonObject

    Bus::assertNotDispatched(FinalizeAgentRunJob::class);
});
