<?php

declare(strict_types=1);

namespace App\Services\AgentRuntime;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MemoryExtractionClient
{
    /**
     * @param  array{
     *     workspace_id:int,
     *     agent_id:int,
     *     specialist_id:int|null,
     *     contact_id:int,
     *     transcript:array<int, array{role:string,content:string,created_at?:string|null}>,
     *     existing_memories:array<int, array{type:string,content:string}>,
     *     allowed_types:array<int, string>,
     *     credential:array{provider:string,model:string,api_key:string},
     * } $payload
     * @return array{status:string,memories:array<int, array{type:string,content:string,confidence:float}>,reason?:string|null}
     */
    public function extract(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new RuntimeException('Agent runtime internal token is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.agent_runtime.timeout', 30))
            ->withHeaders(['X-Internal-Token' => $token])
            ->post("{$baseUrl}/internal/memory/extract", $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Memory extraction failed: HTTP %d',
                $response->status(),
            ));
        }

        return $this->validate($response);
    }

    /**
     * @return array{status:string,memories:array<int, array{type:string,content:string,confidence:float}>,reason?:string|null}
     */
    private function validate(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body) || ! isset($body['status'])) {
            throw new RuntimeException('Memory extraction returned an invalid response.');
        }

        $memories = [];
        $rawMemories = is_array($body['memories'] ?? null) ? $body['memories'] : [];

        foreach ($rawMemories as $memory) {
            if (! is_array($memory)) {
                continue;
            }

            $memories[] = [
                'type' => (string) ($memory['type'] ?? 'fact'),
                'content' => (string) ($memory['content'] ?? ''),
                'confidence' => (float) ($memory['confidence'] ?? 0.7),
            ];
        }

        return [
            'status' => (string) $body['status'],
            'memories' => $memories,
            'reason' => isset($body['reason']) ? (string) $body['reason'] : null,
        ];
    }
}
