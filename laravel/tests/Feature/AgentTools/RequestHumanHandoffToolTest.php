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

it('rejects handoff tool requests without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/request-human-handoff', [])
        ->assertForbidden();
});

it('marks the agent run waiting human and appends handoff trace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Http::fake();
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
        'output' => [
            'trace' => [
                [
                    'step' => 1,
                    'type' => 'runtime_mock',
                    'input' => ['message_count' => 1],
                    'output' => ['response_type' => 'text'],
                ],
            ],
        ],
    ]);

    postJson('/api/internal/agent-tools/request-human-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Cliente pediu cancelamento e reembolso.',
        'priority' => 'normal',
        'suggested_team' => 'suporte',
        'customer_message' => 'Vou transferir voce para um atendente.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'handoff_dispatched',
            'handoff_id' => $run->id,
            'message' => 'Human handoff dispatched to Chatwoot.',
        ]);

    $run->refresh();
    $output = $run->getAttribute('output');

    assert(is_array($output));

    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($output['handoff']['reason'])->toBe('Cliente pediu cancelamento e reembolso.')
        ->and($output['handoff']['side_effects']['status'])->toBe('queued')
        ->and($output['handoff']['side_effects']['actions']['open_conversation'])->toBe('pending')
        ->and($output['trace'])->toHaveCount(3)
        ->and($output['trace'][1]['type'])->toBe('tool_call')
        ->and($output['trace'][1]['tool'])->toBe('request_human_handoff')
        ->and($output['trace'][2]['type'])->toBe('tool_result')
        ->and($output['trace'][2]['output']['status'])->toBe('handoff_dispatched');

    Queue::assertPushed(
        ApplyHumanHandoffToChatwootJob::class,
        fn (ApplyHumanHandoffToChatwootJob $job): bool => $job->agentRunId === $run->id,
    );
});

it('rejects handoff when the run belongs to another workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$otherWorkspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/request-human-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => null,
        'reason' => 'Tenancy check.',
        'priority' => 'normal',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects handoff when specialist allowlist does not include the tool', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => []]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/request-human-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Need human.',
        'priority' => 'normal',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('queues Chatwoot handoff side effects instead of calling Chatwoot synchronously', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'handoff_label_name' => 'human_handoff',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    Http::fake();

    postJson('/api/internal/agent-tools/request-human-handoff', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => null,
        'reason' => 'Cliente pediu humano.',
        'priority' => 'normal',
        'customer_message' => 'Vou transferir voce para um atendente.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk();

    Http::assertNothingSent();
    Queue::assertPushed(
        ApplyHumanHandoffToChatwootJob::class,
        fn (ApplyHumanHandoffToChatwootJob $job): bool => $job->agentRunId === $run->id,
    );
});
