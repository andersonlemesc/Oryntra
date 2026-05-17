<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Chatwoot\ProcessChatwootWebhookEventJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatwootWebhookController extends Controller
{
    public function __invoke(Request $request, string $connectionUuid): JsonResponse
    {
        $connection = $request->attributes->get('chatwoot_connection');
        if (! $connection instanceof ChatwootConnection) {
            return response()->json(['message' => 'Webhook connection not found.'], 404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $eventName = $this->stringValue($payload['event'] ?? null);
        $accountId = $this->integerValue(data_get($payload, 'account.id') ?? data_get($payload, 'account_id'));

        if (! $eventName || ! $accountId) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }

        if ($accountId !== $connection->account_id) {
            return response()->json(['message' => 'Webhook account does not match connection.'], 422);
        }

        $chatwootMessageId = $this->extractMessageId($payload, $eventName);
        if ($chatwootMessageId && ChatwootWebhookEvent::query()
            ->where('chatwoot_connection_id', $connection->id)
            ->where('chatwoot_message_id', $chatwootMessageId)
            ->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $event = DB::transaction(function () use ($connection, $payload, $eventName, $accountId, $chatwootMessageId, $request): ChatwootWebhookEvent {
            return ChatwootWebhookEvent::create([
                'workspace_id' => $connection->workspace_id,
                'chatwoot_connection_id' => $connection->id,
                'event_name' => $eventName,
                'chatwoot_account_id' => $accountId,
                'conversation_id' => $this->extractConversationId($payload, $eventName),
                'chatwoot_message_id' => $chatwootMessageId,
                'payload' => $payload,
                'signature' => $request->header('X-Chatwoot-Signature'),
                'status' => 'queued',
                'received_at' => now(),
            ]);
        });

        ProcessChatwootWebhookEventJob::dispatch($event->id);

        return response()->json([
            'status' => 'queued',
            'event_id' => $event->id,
        ], 202);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractConversationId(array $payload, string $eventName): ?int
    {
        $conversationId = $this->integerValue(
            data_get($payload, 'conversation.id')
            ?? data_get($payload, 'conversation_id')
        );

        if ($conversationId) {
            return $conversationId;
        }

        if (str_starts_with($eventName, 'conversation_')) {
            return $this->integerValue($payload['id'] ?? null);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractMessageId(array $payload, string $eventName): ?string
    {
        if (! str_starts_with($eventName, 'message_')) {
            return null;
        }

        $messageId = data_get($payload, 'message.id') ?? $payload['id'] ?? null;

        if (is_int($messageId) || is_string($messageId)) {
            return (string) $messageId;
        }

        return null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integerValue = (int) $value;

            return $integerValue > 0 ? $integerValue : null;
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
