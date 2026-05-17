<?php

declare(strict_types=1);

namespace App\Services\Chatwoot;

use App\Models\ChatwootPlatformConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootPlatformClient
{
    public function __construct(private readonly ChatwootPlatformConnection $connection) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $response = $this->http()->get('/platform/api/v1/accounts');

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot listAccounts failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccountUsers(int $accountId): array
    {
        $response = $this->http()->get("/platform/api/v1/accounts/{$accountId}/account_users");

        if ($response->failed()) {
            throw new RuntimeException(
                "Chatwoot listAccountUsers({$accountId}) failed: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Returns user data, or null when the Platform App lacks permission
     * (HTTP 401/403/404 — user not in this Platform App's permissibles).
     *
     * @return array<string, mixed>|null
     */
    public function getUser(int $userId): ?array
    {
        $response = $this->http()->get("/platform/api/v1/users/{$userId}");

        if (in_array($response->status(), [401, 403, 404], true)) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot getUser({$userId}) failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    public function testConnection(): bool
    {
        return $this->http()->get('/platform/api/v1/accounts')->successful();
    }

    public function deleteAgentBot(int $agentBotId): void
    {
        $response = $this->http()->delete("/platform/api/v1/agent_bots/{$agentBotId}");

        if ($response->status() === 404) {
            return;
        }

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot deleteAgentBot({$agentBotId}) failed: HTTP {$response->status()}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createAgentBot(int $accountId, string $name, string $outgoingUrl): array
    {
        $response = $this->http()->post('/platform/api/v1/agent_bots', [
            'name' => $name,
            'description' => 'Agente Oryntra provisionado automaticamente.',
            'outgoing_url' => $outgoingUrl,
            'account_id' => $accountId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot createAgentBot({$accountId}) failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException("Chatwoot createAgentBot({$accountId}) returned invalid response");
        }

        return $data;
    }

    private function http(): PendingRequest
    {
        if (! $this->connection->isConfigured()) {
            throw new RuntimeException('ChatwootPlatformConnection not configured');
        }

        return Http::baseUrl(rtrim((string) $this->connection->base_url, '/'))
            ->withHeaders($this->connection->platformHeaders())
            ->acceptJson()
            ->timeout(30);
    }
}
