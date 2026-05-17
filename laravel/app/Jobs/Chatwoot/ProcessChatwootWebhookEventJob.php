<?php

namespace App\Jobs\Chatwoot;

use App\Actions\Chatwoot\ClassifyChatwootWebhookEvent;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    /**
     * Create a new job instance.
     */
    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('chatwoot-webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(ClassifyChatwootWebhookEvent $classifyWebhookEvent): void
    {
        $event = ChatwootWebhookEvent::query()->findOrFail($this->webhookEventId);
        $lockKey = $event->conversation_id
            ? "chatwoot:conversation:{$event->chatwoot_connection_id}:{$event->conversation_id}"
            : "chatwoot:webhook-event:{$event->id}";

        Cache::lock($lockKey, 120)->block(30, function () use ($event, $classifyWebhookEvent): void {
            try {
                $event->forceFill([
                    'status' => 'processing',
                    'processing_started_at' => now(),
                ])->save();

                $classification = $classifyWebhookEvent->execute($event);
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

                // Agent execution will be connected in the next phase. At this
                // point the webhook is known to be an incoming contact message.
                $event->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
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
