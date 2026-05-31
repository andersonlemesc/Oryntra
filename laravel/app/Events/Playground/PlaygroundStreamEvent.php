<?php

declare(strict_types=1);

namespace App\Events\Playground;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A single playground streaming frame. ``kind`` is one of:
 * token | routing | tool_call | tool_result | completed | failed.
 *
 * Broadcast synchronously (ShouldBroadcastNow) from inside the streaming job so
 * token order is preserved and there is no extra queue hop per frame.
 */
class PlaygroundStreamEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public string $kind,
        public array $payload = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("playground.conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'stream';
    }

    /**
     * @return array{messageId: int, kind: string, payload: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'kind' => $this->kind,
            'payload' => $this->payload,
        ];
    }
}
