<?php

declare(strict_types=1);

use App\Actions\AgentRuns\ApproveAgentRun;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.agent_runtime.base_url' => 'http://runtime.test',
        'services.agent_runtime.internal_token' => 'test-token',
        'services.agent_runtime.timeout' => 5,
    ]);
});

it('transitions a waiting_human run to completed and calls the runtime resume endpoint', function () {
    Http::fake([
        'runtime.test/v1/runs/*/resume' => Http::response([], 204),
    ]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'reply']],
        'started_at' => now()->subMinute(),
    ]);

    $approved = app(ApproveAgentRun::class)->execute($run, $user->id);

    expect($approved->status)->toBe(AgentRunStatus::Completed);
    expect(data_get($approved->output, 'hitl.decision'))->toBe('approved');
    expect(data_get($approved->output, 'hitl.actor_id'))->toBe($user->id);
    expect($approved->finished_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), "/v1/runs/{$run->id}/resume")
        && $request['decision'] === 'approved');
});

it('refuses to approve a run that is not waiting for human review', function () {
    Http::fake();

    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->completed()->create();

    expect(fn () => app(ApproveAgentRun::class)->execute($run, null))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('persists the local state even when the runtime resume call fails', function () {
    Http::fake([
        'runtime.test/v1/runs/*/resume' => Http::response(['error' => 'down'], 500),
    ]);

    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'reply']],
    ]);

    $approved = app(ApproveAgentRun::class)->execute($run, null);

    expect($approved->status)->toBe(AgentRunStatus::Completed);
});
