<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Services\AgentRuntime\AgentRuntimeClient;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchAgentRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $agentRunId)
    {
        $this->onQueue('agent');
    }

    public function handle(AgentRuntimeClient $runtime): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $lockKey = "agent-run:{$run->chatwoot_connection_id}:{$run->conversation_id}";

        Cache::lock($lockKey, 120)->block(30, function () use ($run, $runtime): void {
            $run->refresh();
            $status = $run->status;

            if (! $status instanceof AgentRunStatus || ! $status->isInFlight()) {
                return;
            }

            if ($status === AgentRunStatus::Debouncing && $run->debounce_until !== null) {
                $now = Carbon::now();
                $debounceUntil = Carbon::parse($run->debounce_until);

                if ($debounceUntil->isFuture()) {
                    self::dispatch($run->id)
                        ->onQueue('agent')
                        ->delay((int) $debounceUntil->diffInSeconds($now) + 1);

                    return;
                }
            }

            try {
                $run->forceFill([
                    'status' => AgentRunStatus::Running,
                    'started_at' => Carbon::now(),
                ])->save();

                $output = $runtime->run($run);
                $output = $this->deliverResponseToChatwoot($run, $output);

                DB::transaction(function () use ($run, $output): void {
                    $run->forceFill([
                        'status' => $output['status'] === 'waiting_human'
                            ? AgentRunStatus::WaitingHuman
                            : AgentRunStatus::Completed,
                        'output' => $output,
                        'finished_at' => Carbon::now(),
                    ])->save();

                    $run->webhookEvents()
                        ->where('status', 'debouncing')
                        ->update([
                            'status' => 'processed',
                            'processed_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                });

                Log::info('agent_run completed by runtime', [
                    'agent_run_id' => $run->id,
                    'agent_id' => $run->agent_id,
                    'conversation_id' => $run->conversation_id,
                    'messages' => count($run->input['messages'] ?? []),
                    'runtime_status' => $output['status'],
                ]);
            } catch (Throwable $e) {
                $run->forceFill([
                    'status' => AgentRunStatus::Failed,
                    'error_message' => $e->getMessage(),
                    'finished_at' => Carbon::now(),
                ])->save();

                Log::error('agent_run failed', [
                    'agent_run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function deliverResponseToChatwoot(AgentRun $run, array $output): array
    {
        if ($this->responseDeliveryCompleted($run)) {
            $output['response_delivery'] = $this->arrayValue($run->output['response_delivery'] ?? []);

            return $output;
        }

        if (($output['status'] ?? null) !== AgentRunStatus::Completed->value) {
            $output['response_delivery'] = $this->skippedResponseDelivery('runtime_status_not_completed');

            return $output;
        }

        $response = $this->arrayValue($output['response'] ?? []);
        $responseType = $this->stringValue($response['type'] ?? null);
        $content = $this->stringValue($response['content'] ?? null);

        if (! in_array($responseType, ['text', 'clarify'], true)) {
            $output['response_delivery'] = $this->skippedResponseDelivery('unsupported_response_type');

            return $output;
        }

        if ($content === '') {
            $output['response_delivery'] = $this->skippedResponseDelivery('empty_response_content');

            return $output;
        }

        $run->loadMissing('chatwootConnection');
        $connection = $run->chatwootConnection;

        if ($connection === null) {
            $output['response_delivery'] = $this->failedResponseDelivery('missing_chatwoot_connection');
            $run->forceFill(['output' => $output])->save();

            throw new RuntimeException('Cannot deliver agent response without a Chatwoot connection.');
        }

        try {
            (new ChatwootAgentBotClient($connection))
                ->sendConversationMessage((int) $run->conversation_id, $content);
        } catch (Throwable $exception) {
            $output['response_delivery'] = $this->failedResponseDelivery($exception->getMessage());
            $run->forceFill(['output' => $output])->save();

            throw $exception;
        }

        $output['response_delivery'] = [
            'status' => 'completed',
            'sent_at' => Carbon::now()->toISOString(),
            'conversation_id' => (int) $run->conversation_id,
            'response_type' => $responseType,
        ];

        return $output;
    }

    private function responseDeliveryCompleted(AgentRun $run): bool
    {
        $output = $run->output;

        if (! is_array($output)) {
            return false;
        }

        return data_get($output, 'response_delivery.status') === 'completed';
    }

    /**
     * @return array{status: string, reason: string, skipped_at: string}
     */
    private function skippedResponseDelivery(string $reason): array
    {
        return [
            'status' => 'skipped',
            'reason' => $reason,
            'skipped_at' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * @return array{status: string, error: string, failed_at: string}
     */
    private function failedResponseDelivery(string $error): array
    {
        return [
            'status' => 'failed',
            'error' => $error,
            'failed_at' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
