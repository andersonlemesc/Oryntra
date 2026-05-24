<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.agent_runtime.base_url' => 'http://runtime.test',
        'services.agent_runtime.internal_token' => 'super-secret',
        'services.agent_runtime.timeout' => 5,
    ]);
});

it('rejects requests without the internal runtime token', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
    ]);

    postJson(route('internal.agent-runs.resume', $run), [
        'decision' => 'approved',
    ])->assertForbidden();
});

it('validates the decision payload', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
    ]);

    postJson(
        route('internal.agent-runs.resume', $run),
        [],
        ['X-Internal-Token' => 'super-secret'],
    )->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);
});

it('approves the run without calling the runtime back when triggered from the internal endpoint', function () {
    Http::fake();

    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'reply']],
    ]);

    postJson(
        route('internal.agent-runs.resume', $run),
        ['decision' => 'approved'],
        ['X-Internal-Token' => 'super-secret'],
    )->assertOk()
        ->assertJson([
            'idempotent' => false,
            'status' => AgentRunStatus::Completed->value,
            'run_id' => $run->id,
        ]);

    $fresh = $run->fresh();
    assert($fresh instanceof AgentRun);
    expect($fresh->status)->toBe(AgentRunStatus::Completed);
    Http::assertNothingSent();
});

it('rejects the run with the provided reason', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
    ]);

    postJson(
        route('internal.agent-runs.resume', $run),
        ['decision' => 'rejected', 'reason' => 'resposta errada'],
        ['X-Internal-Token' => 'super-secret'],
    )->assertOk()
        ->assertJson(['status' => AgentRunStatus::Failed->value]);

    $fresh = $run->fresh();
    assert($fresh instanceof AgentRun);
    expect($fresh->status)->toBe(AgentRunStatus::Failed);
    expect($fresh->error_message)->toBe('resposta errada');
});

it('edits the response content when decision is edited', function () {
    Http::fake();

    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->create([
        'status' => AgentRunStatus::WaitingHuman,
        'output' => ['response' => ['content' => 'original']],
    ]);

    postJson(
        route('internal.agent-runs.resume', $run),
        ['decision' => 'edited', 'response_content' => 'corrigido'],
        ['X-Internal-Token' => 'super-secret'],
    )->assertOk()
        ->assertJson(['status' => AgentRunStatus::Completed->value]);

    $fresh = $run->fresh();
    assert($fresh instanceof AgentRun);
    expect(data_get($fresh->output, 'response.content'))->toBe('corrigido');
    expect(data_get($fresh->output, 'hitl.original_content'))->toBe('original');
    Http::assertNothingSent();
});

it('is idempotent for runs already in a terminal state', function () {
    $workspace = Workspace::factory()->create();
    $run = AgentRun::factory()->for($workspace)->completed()->create();

    postJson(
        route('internal.agent-runs.resume', $run),
        ['decision' => 'approved'],
        ['X-Internal-Token' => 'super-secret'],
    )->assertOk()
        ->assertJson([
            'idempotent' => true,
            'status' => AgentRunStatus::Completed->value,
            'run_id' => $run->id,
        ]);
});
