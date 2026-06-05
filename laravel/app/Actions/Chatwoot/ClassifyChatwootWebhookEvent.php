<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Models\ChatwootWebhookEvent;

class ClassifyChatwootWebhookEvent
{
    /**
     * @return array{
     *     should_process: bool,
     *     ignored_reason: string|null,
     *     normalized: array<string, mixed>
     * }
     */
    public function execute(ChatwootWebhookEvent $event): array
    {
        $normalized = $this->normalize($event);

        if ($event->event_name !== 'message_created') {
            return $this->ignore('unsupported_event', $normalized);
        }

        if (! $event->chatwoot_message_id) {
            return $this->ignore('missing_message_id', $normalized);
        }

        if (! $event->conversation_id) {
            return $this->ignore('missing_conversation', $normalized);
        }

        if (($normalized['private'] ?? false) === true) {
            return $this->ignore('private_message', $normalized);
        }

        if (($normalized['message_type'] ?? null) !== 'incoming') {
            return $this->ignore('not_incoming_message', $normalized);
        }

        if (($normalized['sender_type'] ?? null) !== 'contact') {
            return $this->ignore('non_contact_sender', $normalized);
        }

        return [
            'should_process' => true,
            'ignored_reason' => null,
            'normalized' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(ChatwootWebhookEvent $event): array
    {
        $payload = $event->payload ?? [];
        $attachments = data_get($payload, 'attachments')
            ?? data_get($payload, 'conversation.messages.0.attachments')
            ?? [];

        return [
            'workspace_id' => $event->workspace_id,
            'account_id' => $event->chatwoot_account_id,
            'conversation_id' => $event->conversation_id,
            'message_id' => $event->chatwoot_message_id,
            'event_name' => $event->event_name,
            'message_type' => $this->messageType(data_get($payload, 'message_type')),
            'sender_type' => $this->senderType(
                data_get($payload, 'sender.type') ?? data_get($payload, 'conversation.messages.0.sender_type')
            ),
            'content' => is_string(data_get($payload, 'content')) ? data_get($payload, 'content') : null,
            'content_type' => is_string(data_get($payload, 'content_type')) ? data_get($payload, 'content_type') : null,
            'private' => data_get($payload, 'private') === true,
            'conversation_status' => $this->conversationStatus(
                data_get($payload, 'status')
                    ?? data_get($payload, 'conversation.status')
            ),
            'attachments' => $this->attachments(is_array($attachments) ? $attachments : []),
        ];
    }

    /**
     * @param  array<int, mixed>                $attachments
     * @return array<int, array<string, mixed>>
     */
    private function attachments(array $attachments): array
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(fn (array $attachment): array => [
                'id' => $attachment['id'] ?? null,
                'file_type' => is_string($attachment['file_type'] ?? null) ? $attachment['file_type'] : null,
                'content_type' => is_string($attachment['content_type'] ?? null) ? $attachment['content_type'] : null,
                'data_url' => is_string($attachment['data_url'] ?? null) ? $attachment['data_url'] : null,
                'thumb_url' => is_string($attachment['thumb_url'] ?? null) ? $attachment['thumb_url'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{
     *     should_process: false,
     *     ignored_reason: string,
     *     normalized: array<string, mixed>
     * }
     */
    private function ignore(string $reason, array $normalized): array
    {
        return [
            'should_process' => false,
            'ignored_reason' => $reason,
            'normalized' => $normalized,
        ];
    }

    private function messageType(mixed $value): ?string
    {
        if (is_int($value)) {
            return match ($value) {
                0 => 'incoming',
                1 => 'outgoing',
                default => null,
            };
        }

        if (is_string($value)) {
            $value = mb_strtolower($value);

            return match ($value) {
                '0', 'incoming' => 'incoming',
                '1', 'outgoing' => 'outgoing',
                default => $value !== '' ? $value : null,
            };
        }

        return null;
    }

    private function senderType(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_strtolower($value) : null;
    }

    private function conversationStatus(mixed $value): ?string
    {
        if (is_int($value)) {
            return match ($value) {
                0 => 'open',
                1 => 'resolved',
                2 => 'pending',
                3 => 'snoozed',
                default => null,
            };
        }

        if (is_string($value)) {
            $value = mb_strtolower($value);

            return match ($value) {
                '0', 'open' => 'open',
                '1', 'resolved' => 'resolved',
                '2', 'pending' => 'pending',
                '3', 'snoozed' => 'snoozed',
                default => $value !== '' ? $value : null,
            };
        }

        return null;
    }
}
