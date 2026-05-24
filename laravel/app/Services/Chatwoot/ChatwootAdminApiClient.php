<?php

declare(strict_types=1);

namespace App\Services\Chatwoot;

use App\Models\ChatwootConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootAdminApiClient
{
    public function __construct(private readonly ChatwootConnection $connection)
    {
        if (! $connection->hasAdminApiToken()) {
            throw new RuntimeException(
                "ChatwootConnection {$connection->id} has no admin_api_token. " .
                'Set it in Filament before calling admin-scoped endpoints (teams, agents, contacts).'
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTeams(): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url('teams'));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin listTeams failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAgents(): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url('agents'));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin listAgents failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLabels(): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url('labels'));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin listLabels failed: HTTP {$response->status()}");
        }

        $payload = $response->json('payload');

        return is_array($payload) ? array_values($payload) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTeamMembers(int $teamId): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url("teams/{$teamId}/team_members"));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin listTeamMembers({$teamId}) failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Fetch a page of contacts via the admin API.
     *
     * @return array{contacts: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listContactsPage(int $page = 1): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url('contacts'), ['page' => $page]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin listContactsPage({$page}) failed: HTTP {$response->status()}");
        }

        $payload = $response->json('payload');
        $meta = $response->json('meta');

        return [
            'contacts' => is_array($payload) ? array_values($payload) : [],
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getContact(int $contactId): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->get($this->url("contacts/{$contactId}"));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin getContact({$contactId}) failed: HTTP {$response->status()}");
        }

        $payload = $response->json('payload');

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateContact(int $contactId, array $attributes): array
    {
        $response = Http::withHeaders($this->connection->chatwootAdminHeaders())
            ->patch($this->url("contacts/{$contactId}"), $attributes);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot admin updateContact({$contactId}) failed: HTTP {$response->status()}");
        }

        $payload = $response->json('payload');

        return is_array($payload) ? $payload : [];
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) $this->connection->base_url, '/');
        $accountId = (int) $this->connection->account_id;

        return "{$baseUrl}/api/v1/accounts/{$accountId}/{$path}";
    }
}
