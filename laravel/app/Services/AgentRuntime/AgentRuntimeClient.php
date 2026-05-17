<?php

declare(strict_types=1);

namespace App\Services\AgentRuntime;

use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
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
        $run->loadMissing([
            'agent',
            'agent.specialists' => fn ($query) => $query
                ->where('workspace_id', $run->workspace_id)
                ->where('status', AgentSpecialistStatus::Active->value)
                ->orderBy('priority')
                ->orderBy('id'),
        ]);

        $input = $run->getAttribute('input');
        $agent = $run->agent;

        if (! $agent instanceof Agent) {
            throw new RuntimeException('Agent runtime cannot run without a resolved agent.');
        }

        if (! is_array($input)) {
            $input = [];
        }

        /** @var array<string, mixed> $input */

        return [
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'agent_mode' => $agent->mode instanceof AgentMode ? $agent->mode->value : AgentMode::Single->value,
            'thread_id' => $run->thread_id ?: $run->buildThreadId(),
            'supervisor' => [
                'prompt' => $agent->supervisor_prompt,
                'llm_key_id' => $agent->supervisor_llm_key_id,
                'llm_model' => $agent->supervisor_llm_model,
            ],
            'specialists' => $agent->specialists
                ->map(fn ($specialist): array => [
                    'id' => $specialist->id,
                    'name' => $specialist->name,
                    'description' => $specialist->description,
                    'role_prompt' => $specialist->role_prompt,
                    'llm_key_id' => $specialist->llm_key_id,
                    'llm_model' => $specialist->llm_model,
                    'llm_temperature' => $specialist->llm_temperature,
                    'tools' => $specialist->tools_allowlist,
                    'intent_keywords' => $specialist->intent_keywords,
                    'confidence_threshold' => $specialist->confidence_threshold,
                    'fallback_specialist_id' => $specialist->fallback_specialist_id,
                ])
                ->values()
                ->all(),
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
