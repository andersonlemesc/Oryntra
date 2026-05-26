<?php

declare(strict_types=1);

namespace App\Services\Chatwoot;

use App\Models\ChatwootConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatwootAgentBotClient
{
    public function __construct(private readonly ChatwootConnection $connection) {}

    public function sendConversationMessage(int $conversationId, string $content): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/messages"), [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => false,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot sendConversationMessage({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function addConversationLabel(int $conversationId, string $label): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/labels"), [
                'labels' => [$label],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot addConversationLabel({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function addPrivateNote(int $conversationId, string $content): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/messages"), [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => true,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot addPrivateNote({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function assignTeam(int $conversationId, int $teamId): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/assignments"), [
                'team_id' => $teamId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot assignTeam({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function assignAgent(int $conversationId, int $agentId): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/assignments"), [
                'assignee_id' => $agentId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot assignAgent({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function toggleConversationStatus(int $conversationId, string $status): void
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->post($this->url("conversations/{$conversationId}/toggle_status"), [
                'status' => $status,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot toggleConversationStatus({$conversationId}) failed: HTTP {$response->status()}");
        }
    }

    public function getConversationStatus(int $conversationId): ?string
    {
        $response = Http::withHeaders($this->connection->chatwootHeaders())
            ->get($this->url("conversations/{$conversationId}"));

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot getConversationStatus({$conversationId}) failed: HTTP {$response->status()}");
        }

        $status = $response->json('status');

        return is_string($status) ? $status : null;
    }

    /**
     * Sends a single Chatwoot message carrying one or more file attachments.
     * Chatwoot accepts a repeated `attachments[]` multipart field.
     *
     * @param  array<int, array{path:string,filename:string,mime:string}> $attachments
     * @return array<string, mixed>
     */
    public function sendConversationMessageWithAttachments(
        int $conversationId,
        string $content,
        array $attachments,
    ): array {
        $request = Http::withHeaders($this->connection->chatwootHeaders())->timeout(60);

        foreach ($attachments as $attachment) {
            $request = $request->attach(
                'attachments[]',
                file_get_contents($attachment['path']),
                $attachment['filename'],
                ['Content-Type' => $attachment['mime']],
            );
        }

        // Multipart drops a boolean `false`, which makes Chatwoot persist a null
        // `private` column (NOT NULL violation). Send the flag as the string 'false'.
        $response = $request->post($this->url("conversations/{$conversationId}/messages"), [
            'content' => $content,
            'message_type' => 'outgoing',
            'private' => 'false',
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Chatwoot sendConversationMessageWithAttachments({$conversationId}) failed: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) $this->connection->base_url, '/');
        $accountId = (int) $this->connection->account_id;

        return "{$baseUrl}/api/v1/accounts/{$accountId}/{$path}";
    }
}
