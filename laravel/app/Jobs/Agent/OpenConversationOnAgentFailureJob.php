<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Models\AgentChatwootBinding;
use App\Models\AgentRun;
use App\Models\ChatwootConversationState;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net so a failed agent run never leaves the customer without a response.
 *
 * When a run reaches a terminal Failed status (runtime error, delivery error or
 * the stuck-run reaper) the bot cannot reply, so this job opens the Chatwoot
 * conversation for a human, locks the bot out (human takeover) and — when the
 * connection's active binding configures a handoff destination — assigns the
 * team/agent and applies the handoff label. A private note tells the operator
 * what happened. The customer receives no automated error message; a human just
 * picks the conversation up.
 *
 * Every Chatwoot call is best-effort: one failing step never aborts the others,
 * and the job does not re-throw, so a flaky API does not retry-storm. It is
 * idempotent — once it records a completed failure handoff, or a human already
 * took the conversation over, it is a no-op.
 */
class OpenConversationOnAgentFailureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30];

    public function __construct(public int $agentRunId, public string $reason)
    {
        $this->onQueue('agent');
    }

    public function handle(): void
    {
        $run = AgentRun::query()
            ->with(['chatwootConnection.activeAgentBinding', 'agent'])
            ->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        if ($this->status($run) === 'completed') {
            return;
        }

        $conversationId = (int) $run->conversation_id;

        if ($conversationId <= 0) {
            $this->record($run, 'skipped', 'missing_conversation');

            return;
        }

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            $this->record($run, 'skipped', 'missing_chatwoot_connection');

            return;
        }

        // A human (or a real handoff) already owns the conversation — the bot is
        // locked out and a person is on it, so there is nothing to recover.
        if (ChatwootConversationState::hasHumanTakeover((int) $run->chatwoot_connection_id, $conversationId)) {
            $this->record($run, 'skipped', 'human_takeover_active');

            return;
        }

        $binding = $connection->activeAgentBinding;
        $client = new ChatwootAgentBotClient($connection);

        $this->attempt('open_conversation', function () use ($client, $conversationId): void {
            $client->toggleConversationStatus($conversationId, 'open');
        });

        // Lock the bot out so it stops trying to answer a conversation a human now owns.
        $this->attempt('human_takeover', function () use ($run, $conversationId): void {
            ChatwootConversationState::markHumanTakeover(
                (int) $run->workspace_id,
                (int) $run->chatwoot_connection_id,
                $conversationId,
            );
        });

        $this->attempt('private_note', function () use ($client, $conversationId, $run, $binding): void {
            $client->addPrivateNote($conversationId, $this->privateNote($run, $binding));
        });

        [$teamId, $agentId] = $this->resolveDestination($binding);

        if ($teamId !== null) {
            $this->attempt('team_assignment', function () use ($client, $conversationId, $teamId): void {
                $client->assignTeam($conversationId, $teamId);
            });
        }

        if ($agentId !== null) {
            $this->attempt('agent_assignment', function () use ($client, $conversationId, $agentId): void {
                $client->assignAgent($conversationId, $agentId);
            });
        }

        $labelName = $this->labelName($binding);

        if ($labelName !== null && $connection->hasAdminApiToken()) {
            $this->attempt('label', function () use ($connection, $conversationId, $labelName): void {
                (new ChatwootAdminApiClient($connection))->addConversationLabel($conversationId, $labelName);
            });
        }

        $this->record($run, 'completed', null);
    }

    /**
     * Runs a single Chatwoot side effect, recording its per-action status and
     * swallowing failures so one broken call does not abort the recovery.
     */
    private function attempt(string $action, callable $callback): void
    {
        try {
            $callback();
            $this->markAction($action, 'completed');
        } catch (Throwable $exception) {
            $this->markAction($action, 'failed');
            Log::warning('agent failure handoff action failed', [
                'agent_run_id' => $this->agentRunId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveDestination(?AgentChatwootBinding $binding): array
    {
        if ($binding === null) {
            return [null, null];
        }

        $strategy = $binding->handoff_assign_strategy;

        $teamId = in_array($strategy, ['team', 'team_then_agent'], true) && $binding->handoff_team_id !== null
            ? (int) $binding->handoff_team_id
            : null;

        $agentId = in_array($strategy, ['agent', 'team_then_agent'], true) && $binding->handoff_agent_id !== null
            ? (int) $binding->handoff_agent_id
            : null;

        return [$teamId, $agentId];
    }

    private function labelName(?AgentChatwootBinding $binding): ?string
    {
        $label = $binding?->handoff_label_name;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    private function privateNote(AgentRun $run, ?AgentChatwootBinding $binding): string
    {
        $agentName = (string) ($run->agent->name ?? '');

        $lines = ['Atendimento automatico indisponivel — conversa aberta para atendimento humano.', ''];

        if ($agentName !== '') {
            $lines[] = "Agente: {$agentName}";
        }

        if (trim($this->reason) !== '') {
            $lines[] = "Falha: {$this->reason}";
        }

        return implode("\n", $lines);
    }

    private function markAction(string $action, string $status): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $output = is_array($run->output) ? $run->output : [];
        $output['failure_handoff']['actions'][$action] = $status;

        $run->forceFill(['output' => $output])->save();
    }

    private function record(AgentRun $run, string $status, ?string $reason): void
    {
        $output = is_array($run->output) ? $run->output : [];
        $output['failure_handoff']['status'] = $status;
        $output['failure_handoff']['recorded_at'] = Carbon::now()->toISOString();

        if ($reason !== null) {
            $output['failure_handoff']['reason'] = $reason;
        }

        $run->forceFill(['output' => $output])->save();
    }

    private function status(AgentRun $run): ?string
    {
        $output = is_array($run->output) ? $run->output : [];
        $status = $output['failure_handoff']['status'] ?? null;

        return is_string($status) ? $status : null;
    }
}
