<?php

declare(strict_types=1);

namespace App\Services\AgentRuntime;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Enums\ExternalToolKind;
use App\Models\Agent;
use App\Models\AgentDocument;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ContactMemory;
use App\Models\ExternalTool;
use App\Models\Workspace;
use App\Services\MCP\McpHttpClient;
use App\Services\MCP\McpSchemaTranslator;
use App\Support\BusinessHours;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
            ->post("{$baseUrl}/internal/chatwoot/messages", $this->buildPayload($run))
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Agent runtime returned an invalid response.');
        }

        return $this->validatedResponse($response);
    }

    /**
     * Fire-and-forget dispatch of an agent run. Python accepts the payload
     * (HTTP 202), runs the graph in the background and posts the result back to
     * Laravel via the internal callback endpoint. The PHP worker is freed
     * immediately instead of blocking for the whole LLM round-trip.
     *
     * Returns false when the runtime is at capacity (HTTP 503): the caller
     * should release the job back onto the queue rather than mark the run
     * running. Other transport/status failures still throw.
     *
     * @throws RequestException
     */
    public function start(AgentRun $run): bool
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new RuntimeException('Agent runtime internal token is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.agent_runtime.accept_timeout', 10))
            ->withHeaders(['X-Internal-Token' => $token])
            ->post("{$baseUrl}/internal/chatwoot/messages/dispatch", $this->buildPayload($run));

        if ($response->status() === 503) {
            return false;
        }

        $response->throw();

        return true;
    }

    /**
     * Ingest a knowledge document: Python downloads the file, extracts text
     * (lib with vision-LLM fallback), chunks it and embeds it with the
     * workspace BYOK embedding credential. Python is stateless — it returns the
     * chunks and vectors for Laravel to persist into pgvector.
     *
     * @return array{
     *     chunks: array<int, array{index:int,content:string,tokens:int|null,metadata:array<string,mixed>}>,
     *     vectors: array<int, array<int, float>>,
     *     embedding_provider: string,
     *     embedding_model: string,
     *     embedding_dim: int,
     *     usage: array<string, mixed>
     * }
     *
     * @throws AgentRuntimeException
     */
    public function ingestKnowledge(AgentDocument $document): array
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new AgentRuntimeException('Agent runtime internal token is not configured.');
        }

        $workspace = $document->workspace;

        if (! $workspace instanceof Workspace) {
            throw new AgentRuntimeException('Knowledge document is not bound to a workspace.');
        }

        $embedderCred = $this->mediaCredentialFromKey(
            $workspace->embeddingLlmKey,
            $workspace->id,
            is_string($workspace->getAttribute('embedding_model')) ? $workspace->getAttribute('embedding_model') : null,
        );

        if ($embedderCred === null) {
            throw new AgentRuntimeException('Workspace has no active embedding model configured.');
        }

        $payload = [
            'workspace_id' => $document->workspace_id,
            'document_id' => $document->id,
            'file_url' => $this->internalDownloadUrl($document),
            'mime' => $document->mime_type,
            'extractor_cred' => $this->mediaCredentialFromKey(
                $document->extractorLlmKey,
                $document->workspace_id,
                is_string($document->extractor_model) ? $document->extractor_model : null,
            ),
            'embedder_cred' => $embedderCred,
        ];

        $response = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.agent_runtime.ingest_timeout', 600))
            ->withHeaders(['X-Internal-Token' => $token])
            ->post("{$baseUrl}/internal/rag/ingest", $payload);

        if ($response->failed()) {
            throw new AgentRuntimeException(sprintf(
                'Knowledge ingest failed for document %d: HTTP %d',
                $document->id,
                $response->status(),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new AgentRuntimeException('Knowledge ingest returned an invalid response.');
        }

        return $this->validatedIngestResponse($body);
    }

    /**
     * Presigned URL the agent-python container can reach. For the S3/MinIO disk
     * we sign with the Docker-internal endpoint (`s3_internal`); other disks use
     * their own temporary URL.
     */
    private function internalDownloadUrl(AgentDocument $document): string
    {
        if ($document->disk() === 's3') {
            return Storage::disk('s3_internal')->temporaryUrl($document->storage_path, now()->addMinutes(60));
        }

        return $document->temporaryUrl(60);
    }

    /**
     * @param array<mixed, mixed> $response
     * @return array{
     *     chunks: array<int, array{index:int,content:string,tokens:int|null,metadata:array<string,mixed>}>,
     *     vectors: array<int, array<int, float>>,
     *     embedding_provider: string,
     *     embedding_model: string,
     *     embedding_dim: int,
     *     usage: array<string, mixed>
     * }
     */
    private function validatedIngestResponse(array $response): array
    {
        $chunks = $response['chunks'] ?? null;
        $vectors = $response['vectors'] ?? null;
        $provider = $response['embedding_provider'] ?? null;
        $model = $response['embedding_model'] ?? null;
        $dim = $response['embedding_dim'] ?? null;
        $usage = $response['usage'] ?? [];

        if (
            ! is_array($chunks)
            || ! is_array($vectors)
            || count($chunks) !== count($vectors)
            || ! is_string($provider)
            || ! is_string($model)
            || ! is_int($dim)
            || ! is_array($usage)
        ) {
            throw new AgentRuntimeException('Knowledge ingest response failed contract validation.');
        }

        /** @var array<int, array{index:int,content:string,tokens:int|null,metadata:array<string,mixed>}> $chunks */
        /** @var array<int, array<int, float>> $vectors */
        /** @var array<string, mixed> $usage */

        return [
            'chunks' => array_values($chunks),
            'vectors' => array_values($vectors),
            'embedding_provider' => $provider,
            'embedding_model' => $model,
            'embedding_dim' => $dim,
            'usage' => $usage,
        ];
    }

    /**
     * Embed a single query string with the workspace embedding credential.
     * Used by knowledge-base retrieval: the query must be embedded with the
     * same model that produced the stored chunks (embeddings live in Python).
     *
     * @return array{vector: array<int, float>, embedding_model: string, embedding_dim: int}
     *
     * @throws AgentRuntimeException
     */
    public function embedQuery(Workspace $workspace, string $query): array
    {
        $baseUrl = rtrim((string) config('services.agent_runtime.base_url'), '/');
        $token = (string) config('services.agent_runtime.internal_token');

        if ($token === '') {
            throw new AgentRuntimeException('Agent runtime internal token is not configured.');
        }

        $embedderCred = $this->mediaCredentialFromKey(
            $workspace->embeddingLlmKey,
            $workspace->id,
            is_string($workspace->getAttribute('embedding_model')) ? $workspace->getAttribute('embedding_model') : null,
        );

        if ($embedderCred === null) {
            throw new AgentRuntimeException('Workspace has no active embedding model configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout((int) config('services.agent_runtime.timeout', 30))
            ->withHeaders(['X-Internal-Token' => $token])
            ->post("{$baseUrl}/internal/rag/embed-query", [
                'query' => $query,
                'embedder_cred' => $embedderCred,
            ]);

        if ($response->failed()) {
            throw new AgentRuntimeException(sprintf(
                'Query embedding failed for workspace %d: HTTP %d',
                $workspace->id,
                $response->status(),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new AgentRuntimeException('Query embedding returned an invalid response.');
        }

        $vector = $body['vector'] ?? null;
        $model = $body['embedding_model'] ?? null;
        $dim = $body['embedding_dim'] ?? null;

        if (! is_array($vector) || $vector === [] || ! is_string($model) || ! is_int($dim)) {
            throw new AgentRuntimeException('Query embedding response failed contract validation.');
        }

        return [
            'vector' => array_values(array_map(static fn (mixed $value): float => (float) $value, $vector)),
            'embedding_model' => $model,
            'embedding_dim' => $dim,
        ];
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
     * Build the full runtime request payload for an agent run. Public so the
     * playground (which streams the same contract over SSE) can reuse it.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(AgentRun $run): array
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
        $connectors = $this->workspaceConnectors($run->workspace_id);
        $mcpServers = $this->workspaceMcpServers($run->workspace_id);

        return [
            'agent_run_id' => $run->id,
            'workspace_id' => $run->workspace_id,
            'agent_id' => $run->agent_id,
            'agent_mode' => $agent->mode instanceof AgentMode ? $agent->mode->value : AgentMode::Single->value,
            'fallback_specialist_id' => $agent->fallback_specialist_id,
            'thread_id' => $run->thread_id ?: $run->buildThreadId(),
            'supervisor' => [
                'prompt' => $this->withBusinessHours($agent->supervisor_prompt, $agent),
                'llm_key_id' => $agent->supervisor_llm_key_id,
                'llm_provider' => $supervisorCredential['provider'],
                'llm_base_url' => $supervisorCredential['base_url'],
                'llm_model' => $agent->supervisor_llm_model,
                'llm_api_key' => $supervisorCredential['api_key'],
            ],
            'specialists' => $agent->specialists
                ->map(function (AgentSpecialist $specialist) use ($run, $connectors, $mcpServers, $agent): array {
                    $specialistCredential = $this->specialistCredential($specialist, $run->workspace_id);

                    return [
                        'id' => $specialist->id,
                        'name' => $specialist->name,
                        'description' => $specialist->description,
                        'role_prompt' => $this->withBusinessHours($specialist->role_prompt, $agent),
                        'llm_key_id' => $specialist->llm_key_id,
                        'llm_provider' => $specialistCredential['provider'],
                        'llm_base_url' => $specialistCredential['base_url'],
                        'llm_model' => $specialist->llm_model,
                        'llm_api_key' => $specialistCredential['api_key'],
                        'llm_temperature' => $specialist->llm_temperature,
                        'tools' => is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [],
                        'external_tools' => $this->connectorsForSpecialist($specialist, $connectors),
                        'mcp_servers' => $this->mcpServersForSpecialist($specialist, $mcpServers),
                        'handoff_config' => $this->objectOrEmpty($specialist->handoff_config),
                        'memory_config' => $this->normalizedMemoryConfig($specialist),
                        'intent_keywords' => is_array($specialist->intent_keywords) ? $specialist->intent_keywords : [],
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
     * @return array{provider: string|null, base_url: string|null, api_key: string|null}
     */
    private function specialistCredential(AgentSpecialist $specialist, int $workspaceId): array
    {
        return $this->credentialFromKey($specialist->llmKey, $workspaceId);
    }

    /**
     * Normalize a jsonb config column for transport: a populated array stays an
     * array (encodes to a JSON object via string keys), but an empty/null value
     * becomes ``(object) []`` so it serializes as ``{}`` — Pydantic models on the
     * Python side reject a bare ``[]`` for object fields.
     *
     * @return array<string, mixed>|object
     */
    private function objectOrEmpty(mixed $value): array|object
    {
        $array = is_array($value) ? $value : [];

        return $array === [] ? (object) [] : $array;
    }

    /**
     * Enabled HTTP connector tools of the workspace, keyed by slug.
     *
     * @return Collection<string, ExternalTool>
     */
    private function workspaceConnectors(int $workspaceId): Collection
    {
        return ExternalTool::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', ExternalToolKind::HttpConnector)
            ->enabled()
            ->get()
            ->keyBy('slug');
    }

    /**
     * Enabled MCP servers of the workspace, keyed by slug.
     *
     * @return Collection<string, ExternalTool>
     */
    private function workspaceMcpServers(int $workspaceId): Collection
    {
        return ExternalTool::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', ExternalToolKind::Mcp)
            ->enabled()
            ->get()
            ->keyBy('slug');
    }

    /**
     * Connector definitions the specialist may call (slug present in its
     * allowlist). Secrets and base_url are intentionally excluded — Python only
     * needs the slug, description and the param schema to build the tool.
     *
     * @param  Collection<string, ExternalTool>                                                         $connectors
     * @return array<int, array{slug: string, description: string, param_schema: array<string, mixed>}>
     */
    private function connectorsForSpecialist(AgentSpecialist $specialist, Collection $connectors): array
    {
        $allowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        $payload = [];

        foreach ($allowlist as $slug) {
            $connector = $connectors->get($slug);

            if ($connector instanceof ExternalTool) {
                $payload[] = [
                    'slug' => $connector->slug,
                    'description' => $connector->description,
                    'param_schema' => $connector->paramSchema(),
                ];
            }
        }

        return $payload;
    }

    /**
     * MCP servers the specialist may use (slug present in its allowlist).
     * For each server: initialize session, list tools, translate schemas.
     * Failures are logged and skipped — they must not abort the run.
     *
     * @param  Collection<string, ExternalTool>                                                                         $mcpServers
     * @return array<int, array{server_slug: string, session_id: string|null, tools: array<int, array<string, mixed>>}>
     */
    private function mcpServersForSpecialist(AgentSpecialist $specialist, Collection $mcpServers): array
    {
        $allowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];
        $client = app(McpHttpClient::class);
        $translator = app(McpSchemaTranslator::class);

        $payload = [];

        foreach ($allowlist as $slug) {
            $server = $mcpServers->get($slug);

            if (! $server instanceof ExternalTool) {
                continue;
            }

            try {
                $session = $client->initialize($server);
                $rawTools = $client->listTools($server, $session);
            } catch (Throwable $e) {
                Log::warning('MCP server unreachable during run setup — skipping', [
                    'server_slug' => $slug,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $tools = [];
            foreach ($rawTools as $tool) {
                if (! is_array($tool) || ! isset($tool['name'])) {
                    continue;
                }

                $tools[] = [
                    'server_slug' => $server->slug,
                    'session_id' => $session->sessionId,
                    'tool_name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'param_schema' => $translator->translate(
                        is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
                    ),
                ];
            }

            $payload[] = [
                'server_slug' => $server->slug,
                'session_id' => $session->sessionId,
                'tools' => $tools,
            ];
        }

        return $payload;
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
     * Append the agent's business-hours summary to a prompt so every specialist
     * states the correct opening hours. No-op when hours are not configured.
     */
    private function withBusinessHours(?string $prompt, Agent $agent): ?string
    {
        $hours = BusinessHours::fromArray(is_array($agent->business_hours) ? $agent->business_hours : null);

        if (! $hours->isConfigured()) {
            return $prompt;
        }

        $timezone = is_string($agent->timezone) && $agent->timezone !== '' ? $agent->timezone : 'UTC';
        $line = "Horário de funcionamento ({$timezone}): {$hours->toHuman()}. "
            . 'Use estes horários ao informar disponibilidade e nunca agende fora deles.';

        return $prompt === null || $prompt === '' ? $line : $prompt . "\n\n" . $line;
    }

    /**
     * @return array{provider: string|null, base_url: string|null, api_key: string|null}
     */
    private function supervisorCredential(Agent $agent, int $workspaceId): array
    {
        return $this->credentialFromKey($agent->supervisorLlmKey, $workspaceId);
    }

    /**
     * @return array{provider: string|null, base_url: string|null, api_key: string|null}
     */
    private function credentialFromKey(?AgentLlmKey $llmKey, int $workspaceId): array
    {
        if (
            ! $llmKey instanceof AgentLlmKey
            || $llmKey->workspace_id !== $workspaceId
            || $llmKey->status !== AgentLlmKeyStatus::Active
        ) {
            return ['provider' => null, 'base_url' => null, 'api_key' => null];
        }

        $provider = $llmKey->getAttribute('provider');

        if ($provider instanceof AgentLlmProvider) {
            $provider = $provider->value;
        }

        $baseUrl = $llmKey->base_url;

        return [
            'provider' => is_string($provider) ? $provider : null,
            'base_url' => (is_string($baseUrl) && trim($baseUrl) !== '') ? $baseUrl : null,
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
     * @param  array<string,mixed>                          $raw
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
     * @return array{provider: string, base_url: string|null, model: string, api_key: string}|null
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

        $baseUrl = $llmKey->base_url;

        return [
            'provider' => $provider,
            'base_url' => (is_string($baseUrl) && trim($baseUrl) !== '') ? $baseUrl : null,
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
