<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\OpenConversationOnAgentFailureJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\ChatwootConversationState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: Workspace, 1: ChatwootConnection, 2: Agent, 3: AgentRun}
 */
function failureHandoffFixtures(string $strategy = 'team_then_agent'): array
{
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
        'handoff_assign_strategy' => $strategy,
        'handoff_team_id' => 7,
        'handoff_agent_id' => 3,
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'status' => AgentRunStatus::Failed,
        'started_at' => now()->subSeconds(5),
    ]);

    return [$workspace, $connection, $agent, $run];
}

it('opens the conversation, locks the bot out and assigns the configured handoff destination', function () {
    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/*' => Http::response([], 200),
    ]);

    [$workspace, $connection, , $run] = failureHandoffFixtures();

    (new OpenConversationOnAgentFailureJob($run->id, 'runtime_failed'))->handle();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/toggle_status')
        && ($r->data()['status'] ?? null) === 'open');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/messages')
        && ($r->data()['private'] ?? null) === true);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/assignments')
        && ($r->data()['team_id'] ?? null) === 7);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/assignments')
        && ($r->data()['assignee_id'] ?? null) === 3);

    expect(ChatwootConversationState::hasHumanTakeover((int) $connection->id, 99))->toBeTrue();

    $output = $run->fresh()?->output;
    expect($output['failure_handoff']['status'] ?? null)->toBe('completed')
        ->and($output['failure_handoff']['actions']['open_conversation'] ?? null)->toBe('completed')
        ->and($output['failure_handoff']['actions']['private_note'] ?? null)->toBe('completed')
        ->and($output['failure_handoff']['actions']['team_assignment'] ?? null)->toBe('completed')
        ->and($output['failure_handoff']['actions']['agent_assignment'] ?? null)->toBe('completed');
});

it('skips when a human already took the conversation over', function () {
    Http::fake();

    [$workspace, $connection, , $run] = failureHandoffFixtures();

    ChatwootConversationState::markHumanTakeover((int) $workspace->id, (int) $connection->id, 99);

    (new OpenConversationOnAgentFailureJob($run->id, 'runtime_failed'))->handle();

    Http::assertNothingSent();
    expect($run->fresh()?->output['failure_handoff']['status'] ?? null)->toBe('skipped')
        ->and($run->fresh()?->output['failure_handoff']['reason'] ?? null)->toBe('human_takeover_active');
});

it('is idempotent once the failure handoff already completed', function () {
    Http::fake();

    [, , , $run] = failureHandoffFixtures();
    $run->forceFill(['output' => ['failure_handoff' => ['status' => 'completed']]])->save();

    (new OpenConversationOnAgentFailureJob($run->id, 'runtime_failed'))->handle();

    Http::assertNothingSent();
});

it('records a skip when the run has no conversation', function () {
    Http::fake();

    $run = AgentRun::factory()->create([
        'conversation_id' => null,
        'status' => AgentRunStatus::Failed,
    ]);

    (new OpenConversationOnAgentFailureJob($run->id, 'runtime_failed'))->handle();

    Http::assertNothingSent();
    expect($run->fresh()?->output['failure_handoff']['reason'] ?? null)->toBe('missing_conversation');
});

it('only opens the conversation when no handoff destination is configured', function () {
    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/*' => Http::response([], 200),
    ]);

    [, , , $run] = failureHandoffFixtures(strategy: 'none');

    (new OpenConversationOnAgentFailureJob($run->id, 'runtime_failed'))->handle();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/toggle_status'));
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/conversations/99/assignments'));

    expect($run->fresh()?->output['failure_handoff']['status'] ?? null)->toBe('completed');
});
