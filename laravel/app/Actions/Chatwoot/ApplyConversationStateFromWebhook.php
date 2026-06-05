<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Models\ChatwootConversationState;
use App\Models\ChatwootWebhookEvent;

/**
 * Detects per-conversation state transitions from a Chatwoot webhook event and
 * persists them to {@see ChatwootConversationState}, before the message-routing
 * pipeline runs.
 *
 * - A human agent's public reply (message_created, outgoing, non-private,
 *   sender user/agent) flags the conversation as taken over by a human. The
 *   automatic-mode bot stops answering it until it is resolved.
 * - Resolving the conversation (conversation_status_changed -> resolved) clears
 *   the takeover flag, so a reused conversation id (inbox set to "reopen the
 *   same conversation") lets the bot answer again on the next episode.
 *
 * The bot's own outgoing messages arrive with sender_type "agentbot" and never
 * trigger a takeover.
 */
class ApplyConversationStateFromWebhook
{
    private const HUMAN_SENDER_TYPES = ['user', 'agent'];

    /**
     * @param  array<string, mixed>                      $normalized Output of ClassifyChatwootWebhookEvent
     * @return array{handled: bool, reason: string|null}
     */
    public function execute(ChatwootWebhookEvent $event, array $normalized): array
    {
        $conversationId = $event->conversation_id;
        $connectionId = $event->chatwoot_connection_id;

        if ($conversationId === null || $connectionId === null) {
            return $this->skip();
        }

        if (
            $event->event_name === 'conversation_status_changed'
            && ($normalized['conversation_status'] ?? null) === 'resolved'
        ) {
            ChatwootConversationState::clearHumanTakeover((int) $connectionId, (int) $conversationId);

            return ['handled' => true, 'reason' => 'conversation_resolved_unlock'];
        }

        if ($this->isHumanPublicReply($event, $normalized)) {
            ChatwootConversationState::markHumanTakeover(
                (int) $event->workspace_id,
                (int) $connectionId,
                (int) $conversationId,
            );

            return ['handled' => true, 'reason' => 'human_takeover_recorded'];
        }

        return $this->skip();
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function isHumanPublicReply(ChatwootWebhookEvent $event, array $normalized): bool
    {
        return $event->event_name === 'message_created'
            && ($normalized['message_type'] ?? null) === 'outgoing'
            && ($normalized['private'] ?? false) !== true
            && in_array($normalized['sender_type'] ?? null, self::HUMAN_SENDER_TYPES, true);
    }

    /**
     * @return array{handled: false, reason: null}
     */
    private function skip(): array
    {
        return ['handled' => false, 'reason' => null];
    }
}
