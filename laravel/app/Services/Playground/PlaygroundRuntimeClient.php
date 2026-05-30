<?php

declare(strict_types=1);

namespace App\Services\Playground;

use App\Enums\AgentRunSource;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\PlaygroundConversation;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
     * Open the Python SSE stream for a run and yield decoded events.
     *
     * @return Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function streamEvents(AgentRun $run): Generator
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new RuntimeException('Agent runtime internal token is not configured.');
        }

        $response = Http::asJson()
            ->withHeaders([
                'X-Internal-Token' => $token,
                'Accept' => 'text/event-stream',
            ])
            ->withOptions(['stream' => true])
            ->timeout((int) config('services.agent_runtime.stream_timeout', 180))
            ->post("{$baseUrl}/internal/playground/stream", $this->runtime->buildPayload($run))
            ->throw();

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $decoded = $this->parseSseEvent($rawEvent);

                if ($decoded !== null) {
                    yield $decoded;
                }
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
