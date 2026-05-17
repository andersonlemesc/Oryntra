<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Enums\AgentChatwootBindingStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootWebhookEvent;

class ResolveAgentForChatwootEvent
{
    /**
     * Pick the active agent that should handle a Chatwoot webhook event.
     *
     * Lookup order:
     * - active binding for (workspace_id, chatwoot_connection_id)
     * - if binding has inbox_ids set, payload inbox.id must be in list
     * - binding's agent must itself be active
     *
     * @param  array<string, mixed>                                                                      $normalized Output of ClassifyChatwootWebhookEvent
     * @return array{agent: Agent|null, binding: AgentChatwootBinding|null, ignored_reason: string|null}
     */
    public function execute(ChatwootWebhookEvent $event, array $normalized): array
    {
        $binding = AgentChatwootBinding::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('chatwoot_connection_id', $event->chatwoot_connection_id)
            ->where('status', AgentChatwootBindingStatus::Active->value)
            ->with('agent')
            ->first();

        if ($binding === null) {
            return $this->ignored('no_active_binding');
        }

        $agent = $binding->agent;

        if (! $agent instanceof Agent) {
            return $this->ignored('no_active_agent');
        }

        if ($agent->status !== AgentStatus::Active) {
            return $this->ignored('agent_inactive');
        }

        if (! $this->inboxMatchesBinding($binding, $event)) {
            return $this->ignored('inbox_not_enabled');
        }

        return [
            'agent' => $agent,
            'binding' => $binding,
            'ignored_reason' => null,
        ];
    }

    private function inboxMatchesBinding(AgentChatwootBinding $binding, ChatwootWebhookEvent $event): bool
    {
        $allowedInboxIds = $binding->inbox_ids;

        if (! is_array($allowedInboxIds) || $allowedInboxIds === []) {
            return true;
        }

        $payload = $event->payload ?? [];
        $inboxId = data_get($payload, 'inbox.id')
            ?? data_get($payload, 'conversation.inbox_id');

        if (! is_numeric($inboxId)) {
            return false;
        }

        $normalized = array_map(static fn (mixed $id): int => (int) $id, $allowedInboxIds);

        return in_array((int) $inboxId, $normalized, true);
    }

    /**
     * @return array{agent: null, binding: null, ignored_reason: string}
     */
    private function ignored(string $reason): array
    {
        return [
            'agent' => null,
            'binding' => null,
            'ignored_reason' => $reason,
        ];
    }
}
