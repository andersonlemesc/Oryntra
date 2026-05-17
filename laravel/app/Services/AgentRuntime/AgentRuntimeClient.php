<?php

declare(strict_types=1);

namespace App\Services\AgentRuntime;

use App\Models\AgentRun;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AgentRuntimeClient
{
    /**
     * @return array{
     *     status: string,
     *     response: array<string, mixed>,
     *     specialist_id?: int|null,
     *     trace: array<int, array<string, mixed>>,
     *     usage: array<string, mixed>
     * }
     *
     * @throws RequestException
     */
    public function run(AgentRun $run): array
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
            ->post("{$baseUrl}/internal/chatwoot/messages", $this->payload($run))
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Agent runtime returned an invalid response.');
        }

        return $this->validatedResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AgentRun $run): array
    {
        $input = $run->getAttribute('input');

        if (! is_array($input)) {
            $input = [];
        }

        /** @var array<string, mixed> $input */

        return [
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'agent_mode' => 'single',
            'thread_id' => $run->thread_id ?: $run->buildThreadId(),
            'messages' => array_values($this->arrayInput($input, 'messages')),
            'contact' => $this->arrayInput($input, 'contact'),
            'inbox' => $this->arrayInput($input, 'inbox'),
            'guard_config' => $this->arrayInput($input, 'guard_config'),
            'media_config' => $this->arrayInput($input, 'media_config'),
            'runtime_config' => $this->arrayInput($input, 'runtime_config'),
        ];
    }

    /**
     * @param  array<string, mixed>    $input
     * @return array<array-key, mixed>
     */
    private function arrayInput(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed, mixed> $response
     * @return array{
     *     status: string,
     *     response: array<string, mixed>,
     *     specialist_id?: int|null,
     *     trace: array<int, array<string, mixed>>,
     *     usage: array<string, mixed>
     * }
     */
    private function validatedResponse(array $response): array
    {
        $status = $response['status'] ?? null;
        $responsePayload = $response['response'] ?? null;
        $specialistId = $response['specialist_id'] ?? null;
        $trace = $response['trace'] ?? [];
        $usage = $response['usage'] ?? [];

        if (
            ! is_string($status)
            || ! in_array($status, ['completed', 'waiting_human', 'failed'], true)
            || ! is_array($responsePayload)
            || ! is_array($trace)
            || ! is_array($usage)
            || (! is_int($specialistId) && $specialistId !== null)
        ) {
            throw new RuntimeException('Agent runtime response failed contract validation.');
        }

        $normalizedTrace = [];

        foreach ($trace as $traceStep) {
            if (! is_array($traceStep)) {
                throw new RuntimeException('Agent runtime response failed contract validation.');
            }

            /** @var array<string, mixed> $traceStep */
            $normalizedTrace[] = $traceStep;
        }

        /** @var array<string, mixed> $responsePayload */
        /** @var array<string, mixed> $usage */

        return [
            'status' => $status,
            'response' => $responsePayload,
            'specialist_id' => $specialistId,
            'trace' => $normalizedTrace,
            'usage' => $usage,
        ];
    }
}
