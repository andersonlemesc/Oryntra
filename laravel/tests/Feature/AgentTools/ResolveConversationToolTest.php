<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ApplyResolveConversationToChatwootJob;
use App\Models\Agent;
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

it('rejects resolve tool requests without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/resolve-conversation', [])
        ->assertForbidden();
});

it('marks the agent run completed and appends resolution trace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Http::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create(['tools_allowlist' => ['resolve_conversation']]);
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

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Cliente confirmou que ficou claro.',
        'resolution_summary' => 'Cliente entendeu o procedimento de cancelamento.',
        'customer_message' => 'Otimo, fico feliz em ajudar. Encerrando entao.',
        'label_name' => 'resolved-by-ai',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk()
        ->assertJson([
            'status' => 'resolution_dispatched',
            'resolution_id' => $run->id,
            'message' => 'Conversation resolution dispatched to Chatwoot.',
        ]);

    $run->refresh();
    $output = $run->getAttribute('output');

    assert(is_array($output));

    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($output['resolution']['reason'])->toBe('Cliente confirmou que ficou claro.')
        ->and($output['resolution']['resolution_summary'])->toBe('Cliente entendeu o procedimento de cancelamento.')
        ->and($output['resolution']['customer_message'])->toBe('Otimo, fico feliz em ajudar. Encerrando entao.')
        ->and($output['resolution']['label_name'])->toBe('resolved-by-ai')
        ->and($output['resolution']['side_effects']['status'])->toBe('queued')
        ->and($output['resolution']['side_effects']['actions']['resolve'])->toBe('pending')
        ->and($output['trace'])->toHaveCount(3)
        ->and($output['trace'][1]['type'])->toBe('tool_call')
        ->and($output['trace'][1]['tool'])->toBe('resolve_conversation')
        ->and($output['trace'][2]['type'])->toBe('tool_result')
        ->and($output['trace'][2]['output']['status'])->toBe('resolution_dispatched');

    Queue::assertPushed(
        ApplyResolveConversationToChatwootJob::class,
        fn (ApplyResolveConversationToChatwootJob $job): bool => $job->agentRunId === $run->id,
    );
});

it('rejects resolve when reason is missing', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'resolution_summary' => 'Ok.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects resolve when resolution_summary is missing', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Running,
    ]);

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'reason' => 'ok',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('rejects resolve when specialist allowlist does not include the tool', function () {
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

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Need close.',
        'resolution_summary' => 'Resolved.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertUnprocessable();
});

it('falls back to resolution_config.customer_message when payload omits it', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Http::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()
        ->for($workspace)
        ->for($agent)
        ->create([
            'tools_allowlist' => ['resolve_conversation'],
            'resolution_config' => [
                'enabled' => true,
                'customer_message' => 'Fallback do specialist.',
                'label_name' => 'resolved-by-ai',
                'rules' => [],
            ],
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

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => $specialist->id,
        'reason' => 'Cliente confirmou.',
        'resolution_summary' => 'Resolvido.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk();

    $output = $run->fresh()?->getAttribute('output');

    assert(is_array($output));

    expect($output['resolution']['customer_message'])->toBe('Fallback do specialist.')
        ->and($output['resolution']['label_name'])->toBe('resolved-by-ai');

    Queue::assertPushed(ApplyResolveConversationToChatwootJob::class);
});

it('queues Chatwoot resolve side effects instead of calling Chatwoot synchronously', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
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

    postJson('/api/internal/agent-tools/resolve-conversation', [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'agent_run_id' => $run->id,
        'thread_id' => $run->thread_id,
        'conversation_id' => 99,
        'specialist_id' => null,
        'reason' => 'Resolved.',
        'resolution_summary' => 'Done.',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertOk();

    Http::assertNothingSent();
    Queue::assertPushed(
        ApplyResolveConversationToChatwootJob::class,
        fn (ApplyResolveConversationToChatwootJob $job): bool => $job->agentRunId === $run->id,
    );
});
