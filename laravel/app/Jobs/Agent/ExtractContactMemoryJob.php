<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\ContactMemorySource;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ContactMemory;
use App\Services\AgentRuntime\MemoryExtractionClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractContactMemoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $agentRunId)
    {
        $this->onQueue('agent');
    }

    public function handle(MemoryExtractionClient $client): void
    {
        $run = AgentRun::query()
            ->with(['contact', 'agent.specialists'])
            ->find($this->agentRunId);

        if ($run === null || $run->contact_id === null) {
            return;
        }

        $specialist = $this->resolveSpecialist($run);

        if ($specialist === null) {
            return;
        }

        $config = is_array($specialist->memory_config) ? $specialist->memory_config : [];

        if (! ($config['extraction_enabled'] ?? false)) {
            return;
        }

        $allowedTypes = $config['extraction_types'] ?? ['preference', 'fact', 'constraint'];

        if (! is_array($allowedTypes) || $allowedTypes === []) {
            return;
        }

        $credential = $this->credentialFor($specialist);

        if ($credential === null) {
            return;
        }

        $transcript = $this->buildTranscript($run);

        if ($transcript === []) {
            return;
        }

        $existing = ContactMemory::query()
            ->where('contact_id', $run->contact_id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['type', 'content'])
            ->map(fn (ContactMemory $memory): array => [
                'type' => $memory->type->value,
                'content' => $memory->content,
            ])
            ->all();

        try {
            $result = $client->extract([
                'workspace_id' => (int) $run->workspace_id,
                'agent_id' => (int) $run->agent_id,
                'specialist_id' => $specialist->id,
                'contact_id' => (int) $run->contact_id,
                'transcript' => $transcript,
                'existing_memories' => $existing,
                'allowed_types' => array_values(array_filter($allowedTypes, 'is_string')),
                'credential' => $credential,
            ]);
        } catch (Throwable $exception) {
            Log::warning('extract_contact_memory.failed', [
                'agent_run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (($result['status'] ?? '') !== 'ok') {
            return;
        }

        foreach ($result['memories'] as $memory) {
            ContactMemory::query()->create([
                'contact_id' => $run->contact_id,
                'workspace_id' => $run->workspace_id,
                'type' => $memory['type'],
                'content' => $memory['content'],
                'source' => ContactMemorySource::AgentExtracted->value,
                'confidence' => $memory['confidence'],
                'conversation_id' => (int) $run->conversation_id,
                'agent_run_id' => $run->id,
            ]);
        }
    }

    private function resolveSpecialist(AgentRun $run): ?AgentSpecialist
    {
        $output = is_array($run->output) ? $run->output : [];
        $trace = is_array($output['trace'] ?? null) ? $output['trace'] : [];

        foreach (array_reverse($trace) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $specialistId = $step['specialist_id'] ?? null;

            if (is_int($specialistId)) {
                return AgentSpecialist::query()
                    ->where('id', $specialistId)
                    ->where('workspace_id', $run->workspace_id)
                    ->where('agent_id', $run->agent_id)
                    ->first();
            }
        }

        return null;
    }

    /**
     * @return array{provider:string,model:string,api_key:string}|null
     */
    private function credentialFor(AgentSpecialist $specialist): ?array
    {
        $llmKey = $specialist->llmKey;

        if (
            ! $llmKey instanceof AgentLlmKey
            || $llmKey->workspace_id !== $specialist->workspace_id
            || $llmKey->status !== AgentLlmKeyStatus::Active
            || $specialist->llm_model === null
            || $llmKey->api_key === null
        ) {
            return null;
        }

        $provider = $llmKey->getAttribute('provider');

        if ($provider instanceof AgentLlmProvider) {
            $provider = $provider->value;
        }

        if (! is_string($provider)) {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => (string) $specialist->llm_model,
            'api_key' => (string) $llmKey->api_key,
        ];
    }

    /**
     * @return array<int, array{role:string,content:string}>
     */
    private function buildTranscript(AgentRun $run): array
    {
        $input = is_array($run->input) ? $run->input : [];
        $rawMessages = is_array($input['messages'] ?? null) ? $input['messages'] : [];

        $transcript = [];

        foreach ($rawMessages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? null;

            if (! is_string($content) || trim($content) === '') {
                continue;
            }

            $transcript[] = [
                'role' => 'user',
                'content' => trim($content),
            ];
        }

        $output = is_array($run->output) ? $run->output : [];
        $responseContent = $output['response']['content'] ?? null;

        if (is_string($responseContent) && trim($responseContent) !== '') {
            $transcript[] = [
                'role' => 'assistant',
                'content' => trim($responseContent),
            ];
        }

        return $transcript;
    }
}
