<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Models\ChatwootConnection;
use App\Models\ChatwootPlatformConnection;
use App\Services\Chatwoot\ChatwootPlatformClient;
use RuntimeException;

class ProvisionChatwootAgentBot
{
    public function execute(ChatwootConnection $connection): ChatwootConnection
    {
        if (filled($connection->agent_bot_id) && filled($connection->api_access_token)) {
            return $connection;
        }

        $platformConnection = ChatwootPlatformConnection::current();

        if (! $platformConnection->exists || ! $platformConnection->isConfigured()) {
            throw new RuntimeException('Chatwoot Platform connection is not configured.');
        }

        if ($connection->account_id <= 0) {
            throw new RuntimeException('Chatwoot connection does not have a valid account_id.');
        }

        $outgoingUrl = $this->outgoingUrl($connection);
        $client = new ChatwootPlatformClient($platformConnection);
        $agentBot = $client->createAgentBot(
            accountId: $connection->account_id,
            name: "Oryntra - {$connection->name}",
            outgoingUrl: $outgoingUrl,
        );

        $agentBotId = $this->integerValue($agentBot['id'] ?? null);
        $accessToken = $this->stringValue($agentBot['access_token'] ?? null);
        $webhookSecret = $this->stringValue($agentBot['webhook_secret'] ?? null);

        if (! $agentBotId || ! $accessToken) {
            throw new RuntimeException('Chatwoot agent bot response is missing id or access_token.');
        }

        $attributes = [
            'agent_bot_id' => $agentBotId,
            'agent_bot_outgoing_url' => $outgoingUrl,
            'api_access_token' => $accessToken,
            'provisioned_at' => now(),
            'provisioning_error' => null,
        ];

        if ($webhookSecret) {
            $attributes['webhook_secret'] = $webhookSecret;
        }

        $connection->forceFill($attributes)->save();

        return $connection;
    }

    private function outgoingUrl(ChatwootConnection $connection): string
    {
        $baseUrl = rtrim((string) config('chatwoot.webhook_base_url'), '/');
        $path = route('chatwoot.webhooks.receive', [
            'connectionUuid' => $connection->connection_uuid,
        ], absolute: false);

        return $baseUrl . $path;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            $integerValue = (int) $value;

            return $integerValue > 0 ? $integerValue : null;
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
