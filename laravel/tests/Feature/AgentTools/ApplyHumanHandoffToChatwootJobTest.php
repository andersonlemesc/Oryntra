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
use App\Support\AgentTools\HandoffPrivateNoteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies configured human handoff side effects to Chatwoot', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'handoff_label_name' => 'human_handoff',
        'handoff_assign_strategy' => 'team_then_agent',
        'handoff_team_id' => 12,
        'handoff_agent_id' => 34,
        'handoff_private_note_template' => 'Motivo: {reason}; Prioridade: {priority}; Conversa: {conversation_id}',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => humanHandoffOutputForJobTest(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::sequence()
            ->push(['id' => 123])
            ->push(['id' => 124]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['human_handoff']]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments' => Http::response(['id' => 99]),
    ]);

    (new ApplyHumanHandoffToChatwootJob($run->id))->handle(app(HandoffPrivateNoteRenderer::class));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status'
        && ($request['status'] ?? null) === 'open');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request['content'] === 'Vou transferir voce para um atendente.'
        && $request['private'] === false);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request['content'] === 'Motivo: Cliente pediu humano.; Prioridade: normal; Conversa: 99'
        && $request['private'] === true);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request->method() === 'POST'
        && $request['labels'] === ['human_handoff']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments'
        && ($request['team_id'] ?? null) === 12);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments'
        && ($request['assignee_id'] ?? null) === 34);

    $output = $run->fresh()?->output;

    assert(is_array($output));

    expect($output['handoff']['side_effects']['status'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['open_conversation'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['customer_message'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['private_note'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['label'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['team_assignment'])->toBe('completed')
        ->and($output['handoff']['side_effects']['actions']['agent_assignment'])->toBe('completed');
});

it('skips already completed actions when retrying handoff side effects', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'handoff_label_name' => 'human_handoff',
    ]);
    $output = humanHandoffOutputForJobTest();
    $output['handoff']['side_effects']['actions']['customer_message'] = 'completed';
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => $output,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 124]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['human_handoff']]),
    ]);

    (new ApplyHumanHandoffToChatwootJob($run->id))->handle(app(HandoffPrivateNoteRenderer::class));

    Http::assertNotSent(fn (Request $request): bool => ($request['content'] ?? null) === 'Vou transferir voce para um atendente.'
        && ($request['private'] ?? null) === false);
});

it('uses the specialist label_name and private_note_template when set, overriding the binding', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'handoff_label_name' => 'binding_label',
        'handoff_assign_strategy' => 'none',
        'handoff_private_note_template' => 'BINDING fallback {reason}',
    ]);
    $specialist = AgentSpecialist::factory()->for($agent)->for($workspace)->create([
        'tools_allowlist' => ['request_human_handoff'],
        'handoff_config' => [
            'enabled' => true,
            'label_name' => 'vendas',
            'private_note_template' => 'SPECIALIST {reason} / {priority}',
        ],
    ]);
    $output = humanHandoffOutputForJobTest();
    $output['trace'][0]['specialist_id'] = $specialist->id;
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => $output,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['vendas']]),
    ]);

    (new ApplyHumanHandoffToChatwootJob($run->id))->handle(app(HandoffPrivateNoteRenderer::class));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request->method() === 'POST'
        && $request['labels'] === ['vendas']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && str_starts_with((string) $request['content'], 'SPECIALIST ')
        && $request['private'] === true);
});

it('falls back to the binding label and template when the specialist leaves them blank', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
        'admin_api_token' => 'admin-token',
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'handoff_label_name' => 'binding_label',
        'handoff_assign_strategy' => 'none',
        'handoff_private_note_template' => 'BINDING fallback {reason}',
    ]);
    $specialist = AgentSpecialist::factory()->for($agent)->for($workspace)->create([
        'tools_allowlist' => ['request_human_handoff'],
        'handoff_config' => [
            'enabled' => true,
            'label_name' => null,
            'private_note_template' => null,
        ],
    ]);
    $output = humanHandoffOutputForJobTest();
    $output['trace'][0]['specialist_id'] = $specialist->id;
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => $output,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 124]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['binding_label']]),
    ]);

    (new ApplyHumanHandoffToChatwootJob($run->id))->handle(app(HandoffPrivateNoteRenderer::class));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request->method() === 'POST'
        && $request['labels'] === ['binding_label']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && str_starts_with((string) $request['content'], 'BINDING fallback ')
        && $request['private'] === true);
});

it('stores failed side effect state when the job fails permanently', function () {
    $run = AgentRun::factory()->create([
        'status' => AgentRunStatus::Completed,
        'output' => humanHandoffOutputForJobTest(),
    ]);

    (new ApplyHumanHandoffToChatwootJob($run->id))->failed(new RuntimeException('chatwoot down'));

    $output = $run->fresh()?->output;

    assert(is_array($output));

    expect($output['handoff']['side_effects']['status'])->toBe('failed')
        ->and($output['handoff']['side_effects']['error'])->toBe('chatwoot down')
        ->and($output['handoff']['side_effects']['failed_at'])->not->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function humanHandoffOutputForJobTest(): array
{
    return [
        'handoff' => [
            'reason' => 'Cliente pediu humano.',
            'priority' => 'normal',
            'suggested_team' => 'suporte',
            'customer_message' => 'Vou transferir voce para um atendente.',
            'private_note' => null,
            'requested_at' => now()->toISOString(),
            'side_effects' => [
                'status' => 'queued',
                'job_id' => null,
                'attempted_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error' => null,
                'actions' => [
                    'customer_message' => 'pending',
                    'private_note' => 'pending',
                    'label' => 'pending',
                    'team_assignment' => 'pending',
                    'agent_assignment' => 'pending',
                ],
            ],
        ],
        'trace' => [
            [
                'step' => 3,
                'type' => 'tool_call',
                'specialist_id' => 5,
                'tool' => 'request_human_handoff',
            ],
        ],
    ];
}
