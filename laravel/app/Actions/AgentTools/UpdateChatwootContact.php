<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Contact;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateChatwootContact
{
    private const ALLOWED_FIELDS = ['name', 'email', 'phone_number'];

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int,name?:string|null,email?:string|null,phone_number?:string|null} $payload
     * @return array{status:string,contact:array<string, mixed>}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $this->assertSpecialistCanUse($payload, 'chatwoot_update_contact');

        $attributes = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $payload) && filled($payload[$field])) {
                $attributes[$field] = $payload[$field];
            }
        }

        if ($attributes === []) {
            throw ValidationException::withMessages([
                'attributes' => 'Provide at least one of: ' . implode(', ', self::ALLOWED_FIELDS) . '.',
            ]);
        }

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run has no Chatwoot connection.',
            ]);
        }

        if (! $connection->hasAdminApiToken()) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The Chatwoot connection has no admin_api_token configured.',
            ]);
        }

        $localContact = $this->resolveLocalContact($payload, $connection->id);
        $chatwootContactId = (int) $localContact->chatwoot_contact_id;

        $client = new ChatwootAdminApiClient($connection);
        $remote = $client->updateContact($chatwootContactId, $attributes);

        DB::transaction(function () use ($localContact, $attributes, $remote): void {
            foreach ($attributes as $field => $value) {
                $localContact->{$field} = $value;
            }

            if (is_array($remote['additional_attributes'] ?? null)) {
                $localContact->additional_attributes = $remote['additional_attributes'];
            }

            if (is_array($remote['custom_attributes'] ?? null)) {
                $localContact->chatwoot_custom_attributes = $remote['custom_attributes'];
            }

            $localContact->synced_at = Carbon::now();
            $localContact->save();
        });

        return [
            'status' => 'ok',
            'contact' => $remote,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveLocalContact(array $payload, int $connectionId): Contact
    {
        $contact = Contact::query()
            ->where('id', $payload['contact_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->where('chatwoot_connection_id', $connectionId)
            ->first();

        if (! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'contact_id' => 'The contact does not belong to this workspace and Chatwoot connection.',
            ]);
        }

        return $contact;
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
