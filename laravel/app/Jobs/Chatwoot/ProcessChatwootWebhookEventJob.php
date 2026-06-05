<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Actions\Chatwoot\ApplyConversationStateFromWebhook;
use App\Actions\Chatwoot\ClassifyChatwootWebhookEvent;
use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Actions\Chatwoot\ResolveAgentForChatwootEvent;
use App\Enums\AgentResponseMode;
use App\Models\ChatwootConversationState;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessChatwootWebhookEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('chatwoot-webhooks');
    }

    public function handle(
        ClassifyChatwootWebhookEvent $classifyWebhookEvent,
        ApplyConversationStateFromWebhook $applyConversationState,
        ResolveAgentForChatwootEvent $resolveAgent,
        EnqueueAgentRunForEvent $enqueueAgentRun,
    ): void {
        $event = ChatwootWebhookEvent::query()->findOrFail($this->webhookEventId);
        $lockKey = $event->conversation_id
            ? "chatwoot:conversation:{$event->chatwoot_connection_id}:{$event->conversation_id}"
            : "chatwoot:webhook-event:{$event->id}";

        Cache::lock($lockKey, 120)->block(30, function () use ($event, $classifyWebhookEvent, $applyConversationState, $resolveAgent, $enqueueAgentRun): void {
            try {
                $event->forceFill([
                    'status' => 'processing',
                    'processing_started_at' => now(),
                ])->save();

                $classification = $classifyWebhookEvent->execute($event);

                $stateTransition = $applyConversationState->execute($event, $classification['normalized']);
                if ($stateTransition['handled']) {
                    $event->forceFill([
                        'status' => 'ignored',
                        'ignored_reason' => $stateTransition['reason'],
                        'processed_at' => now(),
                        'failed_reason' => null,
                        'failure_reason' => null,
                    ])->save();

                    return;
                }

                if (! $classification['should_process']) {
                    $event->forceFill([
                        'status' => 'ignored',
                        'ignored_reason' => $classification['ignored_reason'],
                        'processed_at' => now(),
                        'failed_reason' => null,
                        'failure_reason' => null,
                    ])->save();

                    return;
                }

                $resolution = $resolveAgent->execute($event, $classification['normalized']);

                if ($resolution['agent'] === null) {
                    $event->forceFill([
                        'status' => 'ignored',
                        'ignored_reason' => $resolution['ignored_reason'],
                        'resolved_agent_id' => null,
                        'processed_at' => now(),
                        'failed_reason' => null,
                        'failure_reason' => null,
                    ])->save();

                    return;
                }

                if (
                    $resolution['agent']->response_mode === AgentResponseMode::Automatic
                    && $event->conversation_id !== null
                    && ChatwootConversationState::hasHumanTakeover(
                        (int) $event->chatwoot_connection_id,
                        (int) $event->conversation_id,
                    )
                ) {
                    $event->forceFill([
                        'status' => 'ignored',
                        'ignored_reason' => 'human_takeover_active',
                        'resolved_agent_id' => $resolution['agent']->id,
                        'processed_at' => now(),
                        'failed_reason' => null,
                        'failure_reason' => null,
                    ])->save();

                    return;
                }

                $run = $enqueueAgentRun->execute($event, $resolution['agent'], $classification['normalized']);

                $event->forceFill([
                    'status' => 'debouncing',
                    'resolved_agent_id' => $resolution['agent']->id,
                    'agent_run_id' => $run->id,
                    'processed_at' => null,
                    'ignored_reason' => null,
                    'failed_reason' => null,
                    'failure_reason' => null,
                ])->save();
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failed_reason' => $exception->getMessage(),
                    'failure_reason' => $exception->getMessage(),
                ])->save();

                throw $exception;
            }
        });
    }
}
