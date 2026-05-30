<?php

declare(strict_types=1);

namespace App\Jobs\Playground;

use App\Enums\AgentRunStatus;
use App\Enums\PlaygroundMessageStatus;
use App\Events\Playground\PlaygroundStreamEvent;
use App\Jobs\Agent\ExtractContactMemoryJob;
use App\Models\AgentRun;
use App\Models\PlaygroundMessage;
use App\Services\Playground\PlaygroundRuntimeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StreamPlaygroundRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 200;

    /** Flush the token buffer at most this often / once this many chars accrue. */
    private const FLUSH_INTERVAL_SECONDS = 0.05;

    private const FLUSH_CHARS = 6;

    private string $tokenBuffer = '';

    private float $lastFlushedAt = 0.0;

    private int $streamConversationId = 0;

    private int $streamMessageId = 0;

    public function __construct(public int $playgroundMessageId)
    {
        $this->onQueue('playground');
    }

    public function handle(PlaygroundRuntimeClient $client): void
    {
        $message = PlaygroundMessage::query()
            ->with('conversation')
            ->find($this->playgroundMessageId);

        if ($message === null) {
            return;
        }

        $run = $message->agentRun;

        if ($message->conversation === null || ! $run instanceof AgentRun) {
            $this->persistFailure($message, $run, 'Playground run context is missing.');

            return;
        }

        $message->forceFill(['status' => PlaygroundMessageStatus::Streaming])->save();

        $this->streamConversationId = $message->playground_conversation_id;
        $this->streamMessageId = $message->id;
        $this->lastFlushedAt = microtime(true);

        /** @var array<string, mixed>|null $finalData */
        $finalData = null;
        /** @var array<int, array{kind: string, payload: array<string, mixed>}> $debugEvents */
        $debugEvents = [];

        try {
            foreach ($client->streamEvents($run) as $event) {
                $name = $event['event'];
                $data = $event['data'];

                if ($name === 'token') {
                    $this->tokenBuffer .= (string) ($data['delta'] ?? '');

                    if (mb_strlen($this->tokenBuffer) >= self::FLUSH_CHARS || (microtime(true) - $this->lastFlushedAt) >= self::FLUSH_INTERVAL_SECONDS) {
                        $this->flushTokens();
                    }
                } elseif (in_array($name, ['routing', 'tool_call', 'tool_result'], true)) {
                    $this->flushTokens();
                    $debugEvents[] = ['kind' => $name, 'payload' => $data];
                    broadcast(new PlaygroundStreamEvent($this->streamConversationId, $this->streamMessageId, $name, $data));
                } elseif ($name === 'final') {
                    $this->flushTokens();
                    $finalData = $data;
                } elseif ($name === 'error') {
                    $this->flushTokens();

                    throw new RuntimeException((string) ($data['message'] ?? 'Agent runtime stream error.'));
                }
            }

            $this->flushTokens();
            $this->persistFinal($message, $run, $finalData, $debugEvents);
        } catch (Throwable $exception) {
            $this->persistFailure($message, $run, $exception->getMessage());

            broadcast(new PlaygroundStreamEvent($this->streamConversationId, $this->streamMessageId, 'failed', [
                'message' => $exception->getMessage(),
            ]));

            Log::error('playground stream failed', [
                'playground_message_id' => $this->streamMessageId,
                'agent_run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function flushTokens(): void
    {
        if ($this->tokenBuffer === '') {
            return;
        }

        broadcast(new PlaygroundStreamEvent($this->streamConversationId, $this->streamMessageId, 'token', [
            'delta' => $this->tokenBuffer,
        ]));

        $this->tokenBuffer = '';
        $this->lastFlushedAt = microtime(true);
    }

    /**
     * @param array<string, mixed>|null                                      $data
     * @param array<int, array{kind: string, payload: array<string, mixed>}> $debugEvents
     */
    private function persistFinal(PlaygroundMessage $message, AgentRun $run, ?array $data, array $debugEvents = []): void
    {
        $data ??= [];
        $runtimeStatus = is_string($data['status'] ?? null) ? $data['status'] : 'failed';
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $content = is_string($response['content'] ?? null) ? $response['content'] : null;
        $specialistId = is_int($data['specialist_id'] ?? null) ? $data['specialist_id'] : null;
        $trace = is_array($data['trace'] ?? null) ? $data['trace'] : null;
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;

        $messageStatus = match ($runtimeStatus) {
            'completed' => PlaygroundMessageStatus::Completed,
            'waiting_human' => PlaygroundMessageStatus::WaitingHuman,
            default => PlaygroundMessageStatus::Failed,
        };

        $message->forceFill([
            'status' => $messageStatus,
            'content' => $content,
            'specialist_id' => $specialistId,
            'trace' => $trace,
            'events' => $debugEvents === [] ? null : $debugEvents,
            'usage' => $usage,
            'response' => $response,
        ])->save();

        $run->forceFill([
            'status' => match ($runtimeStatus) {
                'completed' => AgentRunStatus::Completed,
                'waiting_human' => AgentRunStatus::WaitingHuman,
                default => AgentRunStatus::Failed,
            },
            'output' => $data,
            'finished_at' => Carbon::now(),
        ])->save();

        broadcast(new PlaygroundStreamEvent($this->streamConversationId, $this->streamMessageId, 'completed', [
            'status' => $messageStatus->value,
            'content' => $content,
            'specialist_id' => $specialistId,
            'trace' => $trace,
            'usage' => $usage,
        ]));

        if ($runtimeStatus === 'completed' && $run->contact_id !== null) {
            ExtractContactMemoryJob::dispatch($run->id)->afterCommit();
        }
    }

    private function persistFailure(PlaygroundMessage $message, ?AgentRun $run, string $error): void
    {
        $message->forceFill([
            'status' => PlaygroundMessageStatus::Failed,
            'error_message' => $error,
        ])->save();

        if ($run instanceof AgentRun) {
            $run->forceFill([
                'status' => AgentRunStatus::Failed,
                'error_message' => $error,
                'finished_at' => Carbon::now(),
            ])->save();
        }
    }
}
