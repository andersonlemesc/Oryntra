<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EnqueueAgentRunForEvent
{
    /**
     * Append the webhook event to an in-flight agent_run (debounce window)
     * or start a new one. Schedules DispatchAgentRunJob to run after the
     * window closes.
     *
     * @param array<string, mixed> $normalized Output of ClassifyChatwootWebhookEvent
     */
    public function execute(ChatwootWebhookEvent $event, Agent $agent, array $normalized): AgentRun
    {
        return DB::transaction(function () use ($event, $agent, $normalized): AgentRun {
            $debounceConfig = $this->normalizeDebounceConfig($agent->debounce_config);
            $windowSeconds = (int) ($debounceConfig['window_seconds'] ?? 8);

            $run = AgentRun::query()
                ->where('chatwoot_connection_id', $event->chatwoot_connection_id)
                ->where('conversation_id', $event->conversation_id)
                ->whereIn('status', [
                    AgentRunStatus::Debouncing->value,
                    AgentRunStatus::Queued->value,
                    AgentRunStatus::Running->value,
                ])
                ->lockForUpdate()
                ->first();

            $message = $this->buildMessageEntry($event, $normalized);
            $now = Carbon::now();
            $newWindow = $now->copy()->addSeconds($windowSeconds);

            if ($run === null) {
                $run = new AgentRun([
                    'workspace_id' => $event->workspace_id,
                    'agent_id' => $agent->id,
                    'chatwoot_connection_id' => $event->chatwoot_connection_id,
                    'chatwoot_webhook_event_id' => $event->id,
                    'chatwoot_account_id' => $event->chatwoot_account_id,
                    'conversation_id' => (int) $event->conversation_id,
                    'chatwoot_message_id' => $event->chatwoot_message_id,
                    'thread_id' => $this->buildThreadId($event),
                    'status' => AgentRunStatus::Debouncing,
                    'input' => ['messages' => [$message]],
                    'debounce_started_at' => $now,
                    'debounce_until' => $newWindow,
                ]);
                $run->save();

                DispatchAgentRunJob::dispatch($run->id)
                    ->onQueue('agent')
                    ->delay($windowSeconds);

                return $run;
            }

            /** @var array<string, mixed> $input */
            $input = $run->input ?? [];
            $existingMessages = $input['messages'] ?? [];
            $messages = is_array($existingMessages) ? $existingMessages : [];
            $messages[] = $message;

            $run->forceFill([
                'input' => ['messages' => $messages],
                'chatwoot_message_id' => $event->chatwoot_message_id,
                'debounce_until' => $newWindow,
            ])->save();

            return $run;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDebounceConfig(mixed $config): array
    {
        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function buildMessageEntry(ChatwootWebhookEvent $event, array $normalized): array
    {
        return [
            'webhook_event_id' => $event->id,
            'chatwoot_message_id' => $event->chatwoot_message_id,
            'content' => $normalized['content'] ?? null,
            'content_type' => $normalized['content_type'] ?? null,
            'attachments' => $normalized['attachments'] ?? [],
            'received_at' => optional($event->received_at)->toIso8601String(),
        ];
    }

    private function buildThreadId(ChatwootWebhookEvent $event): string
    {
        return sprintf(
            'workspace:%d:account:%d:conversation:%d',
            $event->workspace_id,
            $event->chatwoot_account_id,
            $event->conversation_id,
        );
    }
}
