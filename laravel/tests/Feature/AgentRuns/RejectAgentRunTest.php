<?php

declare(strict_types=1);

use App\Actions\AgentRuns\RejectAgentRun;
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

it('marks the run as failed with a rejection reason and does not call the runtime', function () {
    Http::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'reply']],
    ]);

    $rejected = app(RejectAgentRun::class)->execute($run, $user->id, 'resposta incorreta');

    expect($rejected->status)->toBe(AgentRunStatus::Failed);
    expect($rejected->error_message)->toBe('resposta incorreta');
    expect(data_get($rejected->output, 'hitl.decision'))->toBe('rejected');
    expect(data_get($rejected->output, 'hitl.reason'))->toBe('resposta incorreta');
    expect(data_get($rejected->output, 'hitl.actor_id'))->toBe($user->id);

    Http::assertNothingSent();
});

it('requires a non-empty rejection reason', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
    ]);

    expect(fn () => app(RejectAgentRun::class)->execute($run, null, '   '))
        ->toThrow(ValidationException::class);
});

it('refuses to reject a run that is not waiting for human review', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->completed()->create();

    expect(fn () => app(RejectAgentRun::class)->execute($run, null, 'motivo'))
        ->toThrow(ValidationException::class);
});
