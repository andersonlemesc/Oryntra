<?php

declare(strict_types=1);

namespace App\Services\Playground;

use App\Enums\AgentRunSource;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\PlaygroundConversation;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Support\Carbon;
use RuntimeException;

class PlaygroundRuntimeClient
{
    public function __construct(private readonly AgentRuntimeClient $runtime) {}

    /**
     * Create the AgentRun that backs a single playground turn. It carries no
     * Chatwoot connection (source=playground) and reuses the conversation's
     * stable thread_id so the LangGraph checkpointer keeps prior turns.
     */
    public function createTurnRun(PlaygroundConversation $conversation, string $content): AgentRun
    {
        $now = Carbon::now();

        return AgentRun::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'agent_id' => $conversation->agent_id,
            'source' => AgentRunSource::Playground,
            'chatwoot_connection_id' => null,
            'contact_id' => $conversation->contact_id,
            'chatwoot_account_id' => 0,
            'conversation_id' => $conversation->id,
            'thread_id' => $conversation->thread_id,
            'status' => AgentRunStatus::Running,
            'input' => [
                'messages' => [$this->buildMessageEntry($content, $now)],
            ],
            'started_at' => $now,
        ]);
    }

    /**
     * Stream the Python SSE response, invoking $onEvent for each decoded event
     * as it arrives.
     *
     * Uses cURL with CURLOPT_WRITEFUNCTION instead of the Guzzle/Laravel HTTP
     * client: the latter buffers the whole response even with stream=>true, so
     * tokens only surfaced once the run finished. The write callback delivers
     * chunks live, giving real token-by-token streaming.
     *
     * @param callable(array{event: string, data: array<string, mixed>}): void $onEvent
     */
    public function streamEvents(AgentRun $run, callable $onEvent): void
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new RuntimeException('Agent runtime internal token is not configured.');
        }

        $payload = json_encode($this->runtime->buildPayload($run));
        $buffer = '';

        $handle = curl_init("{$baseUrl}/internal/playground/stream");
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                "X-Internal-Token: {$token}",
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => (int) config('services.agent_runtime.stream_timeout', 180),
            CURLOPT_WRITEFUNCTION => function ($curl, string $data) use (&$buffer, $onEvent): int {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $rawEvent = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $decoded = $this->parseSseEvent($rawEvent);

                    if ($decoded !== null) {
                        $onEvent($decoded);
                    }
                }

                return strlen($data);
            },
        ]);

        try {
            $ok = curl_exec($handle);
            $curlError = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($handle);
        }

        if ($ok === false && $curlError !== '') {
            throw new RuntimeException("Agent runtime stream failed: {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("Agent runtime stream returned HTTP {$httpCode}.");
        }

        if (trim($buffer) !== '') {
            $decoded = $this->parseSseEvent($buffer);

            if ($decoded !== null) {
                $onEvent($decoded);
            }
        }
    }

    /**
     * @return array{event: string, data: array<string, mixed>}|null
     */
    private function parseSseEvent(string $rawEvent): ?array
    {
        $event = 'message';
        $dataLines = [];

        foreach (explode("\n", $rawEvent) as $line) {
            $line = rtrim($line, "\r");

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, 5);
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $payload = ltrim(implode("\n", $dataLines));
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return ['event' => $event, 'data' => $decoded];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessageEntry(string $content, Carbon $now): array
    {
        return [
            'content' => $content,
            'content_type' => 'text',
            'attachments' => [],
            'received_at' => $now->toIso8601String(),
        ];
    }
}
