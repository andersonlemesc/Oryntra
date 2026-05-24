<?php

declare(strict_types=1);

namespace App\Services\AgentRuntime;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ContactMemory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
     * Resume a paused agent run after a human decision.
     *
     * @param array{decision:string,response_content?:string|null,reason?:string|null,actor_id?:int|null} $payload
     *
     * @throws AgentRuntimeException
     */
    public function resume(AgentRun $run, array $payload): void
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new AgentRuntimeException('Agent runtime internal token is not configured.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((int) config('services.agent_runtime.timeout', 30))
                ->withHeaders(['X-Internal-Token' => $token])
                ->post("{$baseUrl}/v1/runs/{$run->id}/resume", $payload);

            if ($response->failed()) {
                throw new AgentRuntimeException(sprintf(
                    'Agent runtime resume failed for run %d: HTTP %d',
                    $run->id,
                    $response->status(),
                ));
            }
        } catch (AgentRuntimeException $exception) {
            Log::warning('agent_runtime.resume.failed', [
                'agent_run_id' => $run->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('agent_runtime.resume.failed', [
                'agent_run_id' => $run->id,
                'message' => $exception->getMessage(),
            ]);

            throw new AgentRuntimeException(
                sprintf('Agent runtime resume failed for run %d: %s', $run->id, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AgentRun $run): array
    {
        $run->loadMissing([
            'agent',
            'agent.supervisorLlmKey',
            'agent.audioLlmKey',
            'agent.visionLlmKey',
            'agent.specialists' => fn ($query) => $query
                ->with('llmKey')
                ->where('workspace_id', $run->workspace_id)
                ->where('status', AgentSpecialistStatus::Active->value)
                ->orderBy('priority')
                ->orderBy('id'),
            'contact',
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
        $supervisorCredential = $this->supervisorCredential($agent, $run->workspace_id);

        return [
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'agent_mode' => $agent->mode instanceof AgentMode ? $agent->mode->value : AgentMode::Single->value,
            'fallback_specialist_id' => $agent->fallback_specialist_id,
            'thread_id' => $run->thread_id ?: $run->buildThreadId(),
            'supervisor' => [
                'prompt' => $agent->supervisor_prompt,
                'llm_key_id' => $agent->supervisor_llm_key_id,
                'llm_provider' => $supervisorCredential['provider'],
                'llm_model' => $agent->supervisor_llm_model,
                'llm_api_key' => $supervisorCredential['api_key'],
            ],
            'specialists' => $agent->specialists
                ->map(function (AgentSpecialist $specialist) use ($run): array {
                    $specialistCredential = $this->specialistCredential($specialist, $run->workspace_id);

                    return [
                        'id' => $specialist->id,
                        'name' => $specialist->name,
                        'description' => $specialist->description,
                        'role_prompt' => $specialist->role_prompt,
                        'llm_key_id' => $specialist->llm_key_id,
                        'llm_provider' => $specialistCredential['provider'],
                        'llm_model' => $specialist->llm_model,
                        'llm_api_key' => $specialistCredential['api_key'],
                        'llm_temperature' => $specialist->llm_temperature,
                        'tools' => $specialist->tools_allowlist,
                        'handoff_config' => $specialist->handoff_config,
                        'memory_config' => $this->normalizedMemoryConfig($specialist),
                        'intent_keywords' => $specialist->intent_keywords,
                        'confidence_threshold' => $specialist->confidence_threshold,
                        'fallback_specialist_id' => $specialist->fallback_specialist_id,
                    ];
                })
                ->values()
                ->all(),
            'messages' => array_values($this->arrayInput($input, 'messages')),
            'contact' => $this->contactPayload($run, $input),
            'inbox' => $this->objectInput($input, 'inbox'),
            'guard_config' => $this->objectInput($input, 'guard_config'),
            'media_policy' => $this->mediaPolicyPayload($agent),
            'audio_llm_key' => $this->mediaCredentialFromKey(
                $agent->audioLlmKey,
                $run->workspace_id,
                $agent->audio_llm_model,
            ),
            'vision_llm_key' => $this->mediaCredentialFromKey(
                $agent->visionLlmKey,
                $run->workspace_id,
                $agent->vision_llm_model,
            ),
            'runtime_config' => $this->runtimeConfig($run, $input),
        ];
    }

    /**
     * @return array{provider: string|null, api_key: string|null}
     */
    private function specialistCredential(AgentSpecialist $specialist, int $workspaceId): array
    {
        return $this->credentialFromKey($specialist->llmKey, $workspaceId);
    }

    /**
     * @return array{extraction_enabled:bool,injection_enabled:bool,injection_limit:int|null,extraction_types:array<int,string>,max_tool_iterations:int}
     */
    private function normalizedMemoryConfig(AgentSpecialist $specialist): array
    {
        $config = is_array($specialist->memory_config) ? $specialist->memory_config : [];

        return [
            'extraction_enabled' => (bool) ($config['extraction_enabled'] ?? false),
            'injection_enabled' => (bool) ($config['injection_enabled'] ?? false),
            'injection_limit' => isset($config['injection_limit']) && is_numeric($config['injection_limit'])
                ? max(1, (int) $config['injection_limit'])
                : null,
            'extraction_types' => is_array($config['extraction_types'] ?? null)
                ? array_values(array_filter(
                    $config['extraction_types'],
                    fn (mixed $type): bool => in_array($type, ['preference', 'fact', 'constraint', 'history', 'custom'], true),
                ))
                : [],
            'max_tool_iterations' => isset($config['max_tool_iterations']) && is_numeric($config['max_tool_iterations'])
                ? max(1, min(20, (int) $config['max_tool_iterations']))
                : 4,
        ];
    }

    /**
     * @param  array<string, mixed>           $input
     * @return array<string, mixed>|\stdClass
     */
    private function contactPayload(AgentRun $run, array $input): array|\stdClass
    {
        $base = $this->objectInput($input, 'contact');
        $contact = $run->contact;

        if ($contact === null) {
            return $base;
        }

        $payload = is_array($base) ? $base : [];
        $payload['id'] = $contact->id;
        $payload['chatwoot_contact_id'] = $contact->chatwoot_contact_id;
        $payload['name'] = $contact->name;
        $payload['email'] = $contact->email;
        $payload['phone_number'] = $contact->phone_number;
        $payload['address_postal_code'] = $contact->address_postal_code;
        $payload['address_street'] = $contact->address_street;
        $payload['address_number'] = $contact->address_number;
        $payload['address_complement'] = $contact->address_complement;
        $payload['address_neighborhood'] = $contact->address_neighborhood;
        $payload['address_city'] = $contact->address_city;
        $payload['address_state'] = $contact->address_state;
        $payload['address_country'] = $contact->address_country;
        $payload['address_reference'] = $contact->address_reference;
        $payload['lead_status'] = $contact->lead_status;
        $payload['memories'] = $this->contactMemoriesPayload($run);

        return $payload;
    }

    /**
     * @return array<int, array{type:string,content:string,source:string,confidence:float|null,created_at:string,conversation_id:int|null}>
     */
    private function contactMemoriesPayload(AgentRun $run): array
    {
        if ($run->contact_id === null) {
            return [];
        }

        if (! $this->anySpecialistInjectsMemory($run)) {
            return [];
        }

        return ContactMemory::query()
            ->where('contact_id', $run->contact_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['type', 'content', 'source', 'confidence', 'created_at', 'conversation_id'])
            ->map(fn (ContactMemory $memory): array => [
                'type' => $memory->type->value,
                'content' => $memory->content,
                'source' => $memory->source->value,
                'confidence' => $memory->confidence !== null ? (float) $memory->confidence : null,
                'created_at' => $memory->created_at?->toIso8601String() ?? '',
                'conversation_id' => $memory->conversation_id,
            ])
            ->all();
    }

    private function anySpecialistInjectsMemory(AgentRun $run): bool
    {
        $agent = $run->agent;

        if ($agent === null) {
            return false;
        }

        foreach ($agent->specialists as $specialist) {
            $config = is_array($specialist->memory_config) ? $specialist->memory_config : [];

            if ($config['injection_enabled'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{provider: string|null, api_key: string|null}
     */
    private function supervisorCredential(Agent $agent, int $workspaceId): array
    {
        return $this->credentialFromKey($agent->supervisorLlmKey, $workspaceId);
    }

    /**
     * @return array{provider: string|null, api_key: string|null}
     */
    private function credentialFromKey(?AgentLlmKey $llmKey, int $workspaceId): array
    {
        if (
            ! $llmKey instanceof AgentLlmKey
            || $llmKey->workspace_id !== $workspaceId
            || $llmKey->status !== AgentLlmKeyStatus::Active
        ) {
            return ['provider' => null, 'api_key' => null];
        }

        $provider = $llmKey->getAttribute('provider');

        if ($provider instanceof AgentLlmProvider) {
            $provider = $provider->value;
        }

        return [
            'provider' => is_string($provider) ? $provider : null,
            'api_key' => $llmKey->api_key,
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
     * @param  array<string, mixed>           $input
     * @return array<string, mixed>|\stdClass
     */
    private function objectInput(array $input, string $key): array|\stdClass
    {
        $value = $input[$key] ?? [];

        if (! is_array($value) || $value === []) {
            return new \stdClass;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runtimeConfig(AgentRun $run, array $input): array
    {
        $agentConfig = is_array($run->agent?->runtime_config) ? $run->agent->runtime_config : [];
        $inputConfig = is_array($input['runtime_config'] ?? null) ? $input['runtime_config'] : [];

        return [
            ...$agentConfig,
            ...$inputConfig,
            'agent_run_id' => $run->id,
            'conversation_id' => $run->conversation_id,
            'workspace_timezone' => $this->stringOrDefault($run->workspace?->timezone, 'UTC'),
            'chatwoot_internal_base_url' => (string) config('services.chatwoot.internal_base_url', ''),
        ];
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /**
     * @return array{
     *   audio:    array{enabled:bool, fallback_message:string},
     *   image:    array{enabled:bool, fallback_message:string},
     *   document: array{enabled:bool, fallback_message:string},
     *   video:    array{enabled:bool, fallback_message:string},
     * }
     */
    private function mediaPolicyPayload(Agent $agent): array
    {
        $raw = is_array($agent->media_policy) ? $agent->media_policy : [];

        return [
            'audio' => $this->mediaTypeSlice($raw, 'audio'),
            'image' => $this->mediaTypeSlice($raw, 'image'),
            // document/video processing not implemented this phase — force enabled=false
            'document' => [
                'enabled' => false,
                'fallback_message' => $this->fallbackSlice($raw, 'document'),
            ],
            'video' => [
                'enabled' => false,
                'fallback_message' => $this->fallbackSlice($raw, 'video'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed> $raw
     * @return array{enabled:bool, fallback_message:string}
     */
    private function mediaTypeSlice(array $raw, string $key): array
    {
        $slice = is_array($raw[$key] ?? null) ? $raw[$key] : [];

        return [
            'enabled' => (bool) ($slice['enabled'] ?? false),
            'fallback_message' => (string) ($slice['fallback_message'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function fallbackSlice(array $raw, string $key): string
    {
        $slice = is_array($raw[$key] ?? null) ? $raw[$key] : [];

        return (string) ($slice['fallback_message'] ?? '');
    }

    /**
     * @return array{provider: string, model: string, api_key: string}|null
     */
    private function mediaCredentialFromKey(?AgentLlmKey $llmKey, int $workspaceId, ?string $model): ?array
    {
        if (
            ! $llmKey instanceof AgentLlmKey
            || $llmKey->workspace_id !== $workspaceId
            || $llmKey->status !== AgentLlmKeyStatus::Active
            || $model === null
            || trim($model) === ''
        ) {
            return null;
        }

        $provider = $llmKey->getAttribute('provider');
        if ($provider instanceof AgentLlmProvider) {
            $provider = $provider->value;
        }
        if (! is_string($provider) || $provider === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => (string) $llmKey->api_key,
        ];
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
