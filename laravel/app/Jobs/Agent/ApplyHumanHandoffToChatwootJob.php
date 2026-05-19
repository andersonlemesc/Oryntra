<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Models\AgentRun;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use App\Support\AgentTools\HandoffPrivateNoteRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ApplyHumanHandoffToChatwootJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct(public int $agentRunId)
    {
        $this->onQueue('agent');
    }

    public function handle(HandoffPrivateNoteRenderer $privateNoteRenderer): void
    {
        $run = AgentRun::query()
            ->with('chatwootConnection.activeAgentBinding')
            ->findOrFail($this->agentRunId);

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            $this->markAllSkipped($run, 'missing_chatwoot_connection');

            return;
        }

        $binding = $connection->activeAgentBinding;
        $client = new ChatwootAgentBotClient($connection);

        $this->markSideEffectsStarted($run);

        $handoff = $this->handoff($run);
        $conversationId = (int) $run->conversation_id;
        $customerMessage = $this->stringValue($handoff['customer_message'] ?? null);

        if (filled($customerMessage)) {
            $this->runAction($run, 'customer_message', function () use ($client, $conversationId, $customerMessage): void {
                $client->sendConversationMessage($conversationId, $customerMessage);
            });
        } else {
            $this->markAction($run, 'customer_message', 'skipped');
        }

        if ($binding !== null) {
            $privateNote = $privateNoteRenderer->render(
                $binding->handoff_private_note_template,
                [
                    'reason' => $this->stringValue($handoff['reason'] ?? null),
                    'priority' => $this->stringValue($handoff['priority'] ?? null),
                    'specialist_id' => $this->integerValue($this->traceSpecialistId($run)),
                    'conversation_id' => $conversationId,
                    'customer_message' => $customerMessage,
                ],
            );
            $this->runAction($run, 'private_note', function () use ($client, $conversationId, $privateNote): void {
                $client->addPrivateNote($conversationId, $privateNote);
            });

            if (filled($binding->handoff_label_name)) {
                $this->runAction($run, 'label', function () use ($client, $conversationId, $binding): void {
                    $client->addConversationLabel($conversationId, (string) $binding->handoff_label_name);
                });
            } else {
                $this->markAction($run, 'label', 'skipped');
            }

            if (in_array($binding->handoff_assign_strategy, ['team', 'team_then_agent'], true) && $binding->handoff_team_id !== null) {
                $this->runAction($run, 'team_assignment', function () use ($client, $conversationId, $binding): void {
                    $client->assignTeam($conversationId, (int) $binding->handoff_team_id);
                });
            } else {
                $this->markAction($run, 'team_assignment', 'skipped');
            }

            if (in_array($binding->handoff_assign_strategy, ['agent', 'team_then_agent'], true) && $binding->handoff_agent_id !== null) {
                $this->runAction($run, 'agent_assignment', function () use ($client, $conversationId, $binding): void {
                    $client->assignAgent($conversationId, (int) $binding->handoff_agent_id);
                });
            } else {
                $this->markAction($run, 'agent_assignment', 'skipped');
            }
        } else {
            foreach (['private_note', 'label', 'team_assignment', 'agent_assignment'] as $action) {
                $this->markAction($run, $action, 'skipped');
            }
        }

        $this->markSideEffectsCompleted($run);
    }

    public function failed(Throwable $exception): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if (! $run instanceof AgentRun) {
            return;
        }

        $output = $this->output($run);
        $output['handoff']['side_effects']['status'] = 'failed';
        $output['handoff']['side_effects']['failed_at'] = Carbon::now()->toISOString();
        $output['handoff']['side_effects']['error'] = $exception->getMessage();

        $run->forceFill(['output' => $output])->save();
    }

    private function runAction(AgentRun $run, string $action, callable $callback): void
    {
        if ($this->actionStatus($run, $action) === 'completed') {
            return;
        }

        $callback();
        $this->markAction($run, $action, 'completed');
    }

    private function markSideEffectsStarted(AgentRun $run): void
    {
        $output = $this->output($run);
        $output['handoff']['side_effects']['status'] = 'running';
        $output['handoff']['side_effects']['attempted_at'] = Carbon::now()->toISOString();
        $output['handoff']['side_effects']['failed_at'] = null;
        $output['handoff']['side_effects']['error'] = null;

        $run->forceFill(['output' => $output])->save();
        $run->refresh();
    }

    private function markSideEffectsCompleted(AgentRun $run): void
    {
        $output = $this->output($run);
        $output['handoff']['side_effects']['status'] = 'completed';
        $output['handoff']['side_effects']['completed_at'] = Carbon::now()->toISOString();

        $run->forceFill(['output' => $output])->save();
    }

    private function markAllSkipped(AgentRun $run, string $reason): void
    {
        $output = $this->output($run);
        $output['handoff']['side_effects']['status'] = 'skipped';
        $output['handoff']['side_effects']['completed_at'] = Carbon::now()->toISOString();
        $output['handoff']['side_effects']['error'] = $reason;

        foreach (['customer_message', 'private_note', 'label', 'team_assignment', 'agent_assignment'] as $action) {
            $output['handoff']['side_effects']['actions'][$action] = 'skipped';
        }

        $run->forceFill(['output' => $output])->save();
    }

    private function markAction(AgentRun $run, string $action, string $status): void
    {
        $output = $this->output($run);
        $output['handoff']['side_effects']['actions'][$action] = $status;

        $run->forceFill(['output' => $output])->save();
        $run->refresh();
    }

    private function actionStatus(AgentRun $run, string $action): ?string
    {
        $output = $this->output($run);
        $status = $output['handoff']['side_effects']['actions'][$action] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function output(AgentRun $run): array
    {
        $output = $run->getAttribute('output');

        return is_array($output) ? $output : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function handoff(AgentRun $run): array
    {
        $output = $this->output($run);
        $handoff = $output['handoff'] ?? [];

        return is_array($handoff) ? $handoff : [];
    }

    private function traceSpecialistId(AgentRun $run): ?int
    {
        $output = $this->output($run);
        $trace = $output['trace'] ?? [];

        if (! is_array($trace)) {
            return null;
        }

        foreach (array_reverse($trace) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $specialistId = $step['specialist_id'] ?? null;

            if (is_int($specialistId)) {
                return $specialistId;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function integerValue(?int $value): ?int
    {
        return $value;
    }
}
