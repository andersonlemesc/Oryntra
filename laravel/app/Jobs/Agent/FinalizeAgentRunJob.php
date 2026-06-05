<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Actions\AgentTools\SendDocument;
use App\Enums\AgentResponseMode;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
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

/**
 * Finalizes an agent run once the Python runtime posts its result back through
 * the internal callback endpoint. This holds the post-run logic that used to
 * live inline in {@see DispatchAgentRunJob} while it blocked on the synchronous
 * HTTP call: delivering the response to Chatwoot, merging the run output and
 * transitioning the run to its terminal status.
 *
 * The job is idempotent — a duplicate callback or a race with the stuck-run
 * reaper is a no-op once the run reaches a terminal status.
 */
class FinalizeAgentRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param array<string, mixed> $runtimeResult
     */
    public function __construct(public int $agentRunId, public array $runtimeResult)
    {
        $this->onQueue('agent');
    }

    public function handle(): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $lockKey = "agent-run-finalize:{$run->id}";

        Cache::lock($lockKey, 60)->block(15, function () use ($run): void {
            $run->refresh();
            $status = $run->status;

            if ($status instanceof AgentRunStatus && $status->isTerminal()) {
                return;
            }

            $output = $this->runtimeResult;

            try {
                $output = $this->deliverResponseToChatwoot($run, $output);

                DB::transaction(function () use ($run, $output): void {
                    $run->refresh();
                    $existingOutput = is_array($run->output) ? $run->output : [];
                    $existingHandoff = is_array($existingOutput['handoff'] ?? null) ? $existingOutput['handoff'] : null;
                    $existingResolution = is_array($existingOutput['resolution'] ?? null) ? $existingOutput['resolution'] : null;
                    $mergedOutput = $output;

                    if ($existingHandoff !== null) {
                        $mergedOutput['handoff'] = array_replace($existingHandoff, $mergedOutput['handoff'] ?? []);
                    }

                    if ($existingResolution !== null) {
                        $mergedOutput['resolution'] = array_replace($existingResolution, $mergedOutput['resolution'] ?? []);
                    }

                    $run->forceFill([
                        'status' => $this->resolveTerminalStatus($output),
                        'output' => $mergedOutput,
                        'error_message' => $output['status'] === AgentRunStatus::Failed->value
                            ? $this->stringValue($output['error'] ?? null) ?: 'runtime_failed'
                            : $run->error_message,
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

                Log::info('agent_run finalized by runtime callback', [
                    'agent_run_id' => $run->id,
                    'agent_id' => $run->agent_id,
                    'conversation_id' => $run->conversation_id,
                    'messages' => count($run->input['messages'] ?? []),
                    'runtime_status' => $output['status'],
                ]);

                if ($output['status'] === AgentRunStatus::Completed->value) {
                    ExtractContactMemoryJob::dispatch($run->id)->afterCommit();
                }
            } catch (Throwable $e) {
                $run->forceFill([
                    'status' => AgentRunStatus::Failed,
                    'error_message' => $e->getMessage(),
                    'finished_at' => Carbon::now(),
                ])->save();

                Log::error('agent_run finalize failed', [
                    'agent_run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * @param array<string, mixed> $output
     */
    private function resolveTerminalStatus(array $output): AgentRunStatus
    {
        return match ($output['status'] ?? null) {
            AgentRunStatus::Failed->value => AgentRunStatus::Failed,
            default => AgentRunStatus::Completed,
        };
    }

    /**
     * @param  array<string, mixed> $output
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

        // The resolve_conversation tool already wrote resolution.side_effects and
        // queued ApplyResolveConversationToChatwootJob, which delivers the closing
        // customer_message. The runtime echoes that same text as response.content,
        // so delivering it here would send the message twice.
        if (data_get($run->fresh()?->output, 'resolution.side_effects') !== null) {
            $output['response_delivery'] = $this->skippedResponseDelivery('resolution_message_dispatched_separately');

            return $output;
        }

        $response = $this->arrayValue($output['response'] ?? []);
        $responseType = $this->stringValue($response['type'] ?? null);
        $content = $this->stringValue($response['content'] ?? null);

        if (! in_array($responseType, ['text', 'clarify', 'send_document'], true)) {
            $output['response_delivery'] = $this->skippedResponseDelivery('unsupported_response_type');

            return $output;
        }

        if ($run->loadMissing('agent')->agent?->response_mode === AgentResponseMode::SuggestionOnly) {
            return $this->deliverSuggestionToChatwoot($run, $output, $responseType, $content);
        }

        if ($responseType === 'send_document') {
            $documentType = $this->stringValue($response['document_type'] ?? null) ?: 'standalone';
            $caption = $content;

            $documentIds = array_values(array_filter(array_map(
                'intval',
                $this->arrayValue($response['document_ids'] ?? [intval($response['document_id'] ?? 0)]),
            ), fn (int $id): bool => $id > 0));

            if ($documentIds === []) {
                $output['response_delivery'] = $this->skippedResponseDelivery('missing_document_id');

                return $output;
            }

            $run->loadMissing('chatwootConnection');
            $connection = $run->chatwootConnection;

            if ($connection === null) {
                $output['response_delivery'] = $this->failedResponseDelivery('missing_chatwoot_connection');
                $run->forceFill(['output' => $output])->save();

                throw new RuntimeException('Cannot deliver send_document response without a Chatwoot connection.');
            }

            try {
                $result = app(SendDocument::class)->execute([
                    'workspace_id' => $run->workspace_id,
                    'agent_run_id' => $run->id,
                    'document_ids' => $documentIds,
                    'document_type' => $documentType,
                    'caption' => $caption,
                    'conversation_id' => (int) $run->conversation_id,
                ]);

                if (($result['sent'] ?? false) === false) {
                    $output['response_delivery'] = $this->failedResponseDelivery($result['error'] ?? 'send_document_failed');
                    $run->forceFill(['output' => $output])->save();

                    throw new RuntimeException('send_document failed: ' . ($result['error'] ?? 'unknown'));
                }

                $output['response_delivery'] = [
                    'status' => 'completed',
                    'sent_at' => (string) Carbon::now()->toISOString(),
                    'conversation_id' => (int) $run->conversation_id,
                    'response_type' => 'send_document',
                    'document_ids' => $documentIds,
                    'document_type' => $documentType,
                    'filenames' => $result['filenames'] ?? [],
                ];
            } catch (Throwable $exception) {
                $output['response_delivery'] = $this->failedResponseDelivery($exception->getMessage());
                $run->forceFill(['output' => $output])->save();

                throw $exception;
            }

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
            'sent_at' => (string) Carbon::now()->toISOString(),
            'conversation_id' => (int) $run->conversation_id,
            'response_type' => $responseType,
        ];

        return $output;
    }

    /**
     * Copilot mode: never reply to the customer. Open the conversation so a human
     * agent sees it, then post the agent's response as a private note for them to
     * use. send_document suggestions are posted as a textual note (no file is sent).
     *
     * @param  array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function deliverSuggestionToChatwoot(AgentRun $run, array $output, string $responseType, string $content): array
    {
        $note = $content;

        if ($note === '' && $responseType === 'send_document') {
            $note = 'Sugestao: enviar documento ao cliente.';
        }

        if ($note === '') {
            $output['response_delivery'] = $this->skippedResponseDelivery('empty_response_content');

            return $output;
        }

        $run->loadMissing('chatwootConnection');
        $connection = $run->chatwootConnection;

        if ($connection === null) {
            $output['response_delivery'] = $this->failedResponseDelivery('missing_chatwoot_connection');
            $run->forceFill(['output' => $output])->save();

            throw new RuntimeException('Cannot deliver suggestion without a Chatwoot connection.');
        }

        try {
            $client = new ChatwootAgentBotClient($connection);
            $client->toggleConversationStatus((int) $run->conversation_id, 'open');
            $client->addPrivateNote((int) $run->conversation_id, $note);
        } catch (Throwable $exception) {
            $output['response_delivery'] = $this->failedResponseDelivery($exception->getMessage());
            $run->forceFill(['output' => $output])->save();

            throw $exception;
        }

        $output['response_delivery'] = [
            'status' => 'completed',
            'sent_at' => (string) Carbon::now()->toISOString(),
            'conversation_id' => (int) $run->conversation_id,
            'response_type' => $responseType,
            'mode' => 'suggestion',
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
            'skipped_at' => (string) Carbon::now()->toISOString(),
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
            'failed_at' => (string) Carbon::now()->toISOString(),
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
