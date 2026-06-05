<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Enums\ContactMemorySource;
use App\Enums\ContactMemoryType;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Contact;
use App\Models\ContactMemory;
use Illuminate\Validation\ValidationException;

class UpdateContactMemory
{
    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int,type:string,content:string,confidence?:float|null,expires_at?:string|null} $payload
     * @return array{status:string,memory_id:int}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $this->assertSpecialistCanUse($payload, 'update_contact_memory');

        $contact = Contact::query()
            ->where('id', $payload['contact_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'contact_id' => 'The contact does not belong to this workspace.',
            ]);
        }

        $type = ContactMemoryType::tryFrom($payload['type']);

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => 'Invalid memory type.',
            ]);
        }

        $content = trim($payload['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => 'Content cannot be empty.',
            ]);
        }

        $memory = ContactMemory::query()->create([
            'contact_id' => $contact->id,
            'workspace_id' => $contact->workspace_id,
            'type' => $type->value,
            'content' => $content,
            'source' => ContactMemorySource::Tool->value,
            'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            'conversation_id' => (int) $run->conversation_id,
            'agent_run_id' => $run->id,
            'expires_at' => $payload['expires_at'] ?? null,
        ]);

        return [
            'status' => 'ok',
            'memory_id' => $memory->id,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loadRun(array $payload): AgentRun
    {
        $run = AgentRun::query()
            ->where('id', $payload['agent_run_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $run instanceof AgentRun) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run does not match the workspace and agent.',
            ]);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanUse(array $payload, string $tool): void
    {
        $specialistId = $payload['specialist_id'] ?? null;

        if ($specialistId === null) {
            return;
        }

        $specialist = AgentSpecialist::query()
            ->where('id', $specialistId)
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist does not belong to this workspace and agent.',
            ]);
        }

        $toolsAllowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        if (! in_array($tool, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to call tool '{$tool}'.",
            ]);
        }
    }
}
