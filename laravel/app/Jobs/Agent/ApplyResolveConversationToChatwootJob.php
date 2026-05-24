<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Models\AgentRun;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ApplyResolveConversationToChatwootJob implements ShouldQueue
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

    public function handle(): void
    {
        $run = AgentRun::query()
            ->with(['chatwootConnection'])
            ->findOrFail($this->agentRunId);

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            $this->markAllSkipped($run, 'missing_chatwoot_connection');

            return;
        }

        $client = new ChatwootAgentBotClient($connection);

        $this->markSideEffectsStarted($run);

        $resolution = $this->resolution($run);
        $conversationId = (int) $run->conversation_id;
        $customerMessage = $this->stringValue($resolution['customer_message'] ?? null);
        $labelName = $this->stringValue($resolution['label_name'] ?? null);

        $currentStatus = $this->fetchCurrentStatus($client, $conversationId);

        if ($currentStatus === 'resolved') {
            $this->markAlreadyResolved($run);

            return;
        }

        if (filled($customerMessage)) {
            $this->runAction($run, 'customer_message', function () use ($client, $conversationId, $customerMessage): void {
                $client->sendConversationMessage($conversationId, $customerMessage);
            });
        } else {
            $this->markAction($run, 'customer_message', 'skipped');
        }

        if (filled($labelName)) {
            $this->runAction($run, 'label', function () use ($client, $conversationId, $labelName): void {
                $client->addConversationLabel($conversationId, $labelName);
            });
        } else {
            $this->markAction($run, 'label', 'skipped');
        }

        $this->runAction($run, 'resolve', function () use ($client, $conversationId): void {
            $client->toggleConversationStatus($conversationId, 'resolved');
        });

        $this->markSideEffectsCompleted($run);
    }

    public function failed(Throwable $exception): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if (! $run instanceof AgentRun) {
            return;
        }

        $output = $this->output($run);
        $output['resolution']['side_effects']['status'] = 'failed';
        $output['resolution']['side_effects']['failed_at'] = Carbon::now()->toISOString();
        $output['resolution']['side_effects']['error'] = $exception->getMessage();

        $run->forceFill(['output' => $output])->save();
    }

    private function fetchCurrentStatus(ChatwootAgentBotClient $client, int $conversationId): ?string
    {
        try {
            return $client->getConversationStatus($conversationId);
        } catch (Throwable) {
            return null;
        }
    }

    private function runAction(AgentRun $run, string $action, callable $callback): void
    {
        if ($this->actionStatus($run, $action) === 'completed') {
            return;
        }

        try {
            $callback();
            $this->markAction($run, $action, 'completed');
        } catch (Throwable $exception) {
            $this->markAction($run, $action, 'failed');
            $this->appendActionError($run, $action, $exception->getMessage());
        }
    }

    private function markSideEffectsStarted(AgentRun $run): void
    {
        $output = $this->output($run);
        $output['resolution']['side_effects']['status'] = 'running';
        $output['resolution']['side_effects']['attempted_at'] = Carbon::now()->toISOString();
        $output['resolution']['side_effects']['failed_at'] = null;
        $output['resolution']['side_effects']['error'] = null;

        $run->forceFill(['output' => $output])->save();
        $run->refresh();
    }

    private function markSideEffectsCompleted(AgentRun $run): void
    {
        $output = $this->output($run);
        $output['resolution']['side_effects']['status'] = 'completed';
        $output['resolution']['side_effects']['completed_at'] = Carbon::now()->toISOString();

        $run->forceFill(['output' => $output])->save();
    }

    private function markAlreadyResolved(AgentRun $run): void
    {
        $output = $this->output($run);
        $output['resolution']['side_effects']['status'] = 'already_resolved';
        $output['resolution']['side_effects']['completed_at'] = Carbon::now()->toISOString();

        foreach (['customer_message', 'label', 'resolve'] as $action) {
            $output['resolution']['side_effects']['actions'][$action] = 'skipped';
        }

        $run->forceFill(['output' => $output])->save();
    }

    private function markAllSkipped(AgentRun $run, string $reason): void
    {
        $output = $this->output($run);
        $output['resolution']['side_effects']['status'] = 'skipped';
        $output['resolution']['side_effects']['completed_at'] = Carbon::now()->toISOString();
        $output['resolution']['side_effects']['error'] = $reason;

        foreach (['customer_message', 'label', 'resolve'] as $action) {
            $output['resolution']['side_effects']['actions'][$action] = 'skipped';
        }

        $run->forceFill(['output' => $output])->save();
    }

    private function markAction(AgentRun $run, string $action, string $status): void
    {
        $output = $this->output($run);
        $output['resolution']['side_effects']['actions'][$action] = $status;

        $run->forceFill(['output' => $output])->save();
        $run->refresh();
    }

    private function appendActionError(AgentRun $run, string $action, string $message): void
    {
        $output = $this->output($run);
        $errors = $output['resolution']['side_effects']['action_errors'] ?? [];

        if (! is_array($errors)) {
            $errors = [];
        }

        $errors[$action] = $message;
        $output['resolution']['side_effects']['action_errors'] = $errors;

        $run->forceFill(['output' => $output])->save();
        $run->refresh();
    }

    private function actionStatus(AgentRun $run, string $action): ?string
    {
        $output = $this->output($run);
        $status = $output['resolution']['side_effects']['actions'][$action] ?? null;

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
    private function resolution(AgentRun $run): array
    {
        $output = $this->output($run);
        $resolution = $output['resolution'] ?? [];

        return is_array($resolution) ? $resolution : [];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
