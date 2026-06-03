<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Jobs\Agent\Middleware\ThrottleAgentRunsPerWorkspace;
use App\Models\AgentRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('lets the job through when the workspace is under the cap', function () {
    config(['services.agent_runtime.max_concurrency_per_account' => 2]);

    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Queued,
    ]);

    $passed = false;
    (new ThrottleAgentRunsPerWorkspace)->handle(
        new DispatchAgentRunJob($run->id),
        function () use (&$passed): void {
            $passed = true;
        },
    );

    expect($passed)->toBeTrue();
});

it('throttles the job when the workspace is at the cap', function () {
    config(['services.agent_runtime.max_concurrency_per_account' => 2]);

    $workspace = Workspace::factory()->create();

    AgentRun::factory()->count(2)->for($workspace)->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
    ]);

    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Queued,
    ]);

    $passed = false;
    (new ThrottleAgentRunsPerWorkspace)->handle(
        new DispatchAgentRunJob($run->id),
        function () use (&$passed): void {
            $passed = true;
        },
    );

    expect($passed)->toBeFalse();
});

it('does not throttle other workspaces', function () {
    config(['services.agent_runtime.max_concurrency_per_account' => 1]);

    $busy = Workspace::factory()->create();
    AgentRun::factory()->for($busy)->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
    ]);

    $other = Workspace::factory()->create();
    $run = AgentRun::factory()->for($other)->create([
        'status' => AgentRunStatus::Queued,
    ]);

    $passed = false;
    (new ThrottleAgentRunsPerWorkspace)->handle(
        new DispatchAgentRunJob($run->id),
        function () use (&$passed): void {
            $passed = true;
        },
    );

    expect($passed)->toBeTrue();
});

it('is disabled when the cap is zero', function () {
    config(['services.agent_runtime.max_concurrency_per_account' => 0]);

    $workspace = Workspace::factory()->create();
    AgentRun::factory()->count(5)->for($workspace)->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
    ]);

    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::Queued,
    ]);

    $passed = false;
    (new ThrottleAgentRunsPerWorkspace)->handle(
        new DispatchAgentRunJob($run->id),
        function () use (&$passed): void {
            $passed = true;
        },
    );

    expect($passed)->toBeTrue();
});
