<?php

declare(strict_types=1);

use App\Actions\AgentTools\RequestHumanHandoff;
use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('preserves full handoff payload after DispatchAgentRunJob merges runtime response', function () {
    Bus::fake();
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $agent = Agent::factory()->active()->for($workspace)->create();
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
    ]);
    $specialist = AgentSpecialist::factory()->for($agent)->for($workspace)->create([
        'tools_allowlist' => ['request_human_handoff'],
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 99,
        'chatwoot_account_id' => 5,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Queued,
        'input' => ['messages' => [['content' => 'quero falar com humano']]],
    ]);

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages/dispatch' => function () use ($run, $workspace, $agent, $specialist) {
            app(RequestHumanHandoff::class)->execute([
                'workspace_id' => $workspace->id,
                'agent_id' => $agent->id,
                'agent_run_id' => $run->id,
                'thread_id' => (string) $run->thread_id,
                'conversation_id' => 99,
                'specialist_id' => $specialist->id,
                'reason' => 'Cliente pediu humano',
                'priority' => 'high',
                'customer_message' => 'Vou transferir voce.',
                'conversation_summary' => 'Cliente quer cancelar pedido.',
                'key_fact' => 'Pedido #123 com problema.',
            ]);

            return Http::response([
                'status' => 'completed',
                'response' => [
                    'type' => 'escalate',
                    'content' => 'Vou transferir voce.',
                    'document_id' => null,
                    'handoff_reason' => 'Cliente pediu humano',
                    'confidence' => 1.0,
                ],
                'specialist_id' => $specialist->id,
                'trace' => [
                    [
                        'step' => 1,
                        'type' => 'tool_call',
                        'tool' => 'request_human_handoff',
                        'specialist_id' => $specialist->id,
                        'input' => [],
                        'output' => ['status' => 'handoff_dispatched'],
                        'tokens' => ['input' => 0, 'output' => 0],
                        'latency_ms' => 0,
                        'ts' => now()->toISOString(),
                    ],
                ],
                'usage' => [
                    'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'total_cost_cents' => 0,
                ],
            ]);
        },
    ]);

    (new DispatchAgentRunJob($run->id))->handle(app(AgentRuntimeClient::class));

    $fresh = $run->fresh();
    expect($fresh?->output['handoff']['customer_message'] ?? null)->toBe('Vou transferir voce.')
        ->and($fresh?->output['handoff']['conversation_summary'] ?? null)->toBe('Cliente quer cancelar pedido.')
        ->and($fresh?->output['handoff']['key_fact'] ?? null)->toBe('Pedido #123 com problema.')
        ->and($fresh?->output['handoff']['reason'] ?? null)->toBe('Cliente pediu humano')
        ->and($fresh?->output['handoff']['priority'] ?? null)->toBe('high');
});
