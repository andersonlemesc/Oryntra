<?php

declare(strict_types=1);

use App\Actions\AgentRuns\EditAgentRunResponse;
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

it('replaces the response content, preserves the original, and approves the run', function () {
    Http::fake([
        'runtime.test/v1/runs/*/resume' => Http::response([], 204),
    ]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'original reply']],
    ]);

    $edited = app(EditAgentRunResponse::class)->execute($run, $user->id, 'edited reply');

    expect($edited->status)->toBe(AgentRunStatus::Completed);
    expect(data_get($edited->output, 'response.content'))->toBe('edited reply');
    expect(data_get($edited->output, 'hitl.decision'))->toBe('edited');
    expect(data_get($edited->output, 'hitl.original_content'))->toBe('original reply');

    Http::assertSent(fn ($request): bool => $request['decision'] === 'edited'
        && $request['response_content'] === 'edited reply');
});

it('refuses to edit when the new content is empty', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'reply']],
    ]);

    expect(fn () => app(EditAgentRunResponse::class)->execute($run, null, '   '))
        ->toThrow(ValidationException::class);
});

it('refuses to edit when the run is not waiting for human review', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->completed()->create();

    expect(fn () => app(EditAgentRunResponse::class)->execute($run, null, 'new'))
        ->toThrow(ValidationException::class);
});
