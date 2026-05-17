<?php

namespace App\Jobs\Chatwoot;

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
    public function handle(): void
    {
        $event = ChatwootWebhookEvent::query()->findOrFail($this->webhookEventId);
        $lockKey = $event->conversation_id
            ? "chatwoot:conversation:{$event->chatwoot_connection_id}:{$event->conversation_id}"
            : "chatwoot:webhook-event:{$event->id}";

        Cache::lock($lockKey, 120)->block(30, function () use ($event): void {
            try {
                $event->forceFill([
                    'status' => 'processing',
                    'processing_started_at' => now(),
                ])->save();

                // Agent execution will be connected in the next phase. This job
                // currently proves queueing and per-conversation serialization.
                $event->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'failure_reason' => null,
                ])->save();
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => $exception->getMessage(),
                ])->save();

                throw $exception;
            }
        });
    }
}
