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
    private const CHATWOOT_FIELDS = ['name', 'email', 'phone_number'];

    private const LOCAL_FIELDS = [
        'address_postal_code',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'address_country',
        'address_reference',
    ];

    /**
     * @param  array<string, mixed>                              $payload
     * @return array{status:string,contact:array<string, mixed>}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $this->assertSpecialistCanUse($payload, 'chatwoot_update_contact');

        $chatwootAttributes = [];
        $localAttributes = [];

        foreach (self::CHATWOOT_FIELDS as $field) {
            if (array_key_exists($field, $payload) && filled($payload[$field])) {
                $chatwootAttributes[$field] = $payload[$field];
            }
        }

        foreach (self::LOCAL_FIELDS as $field) {
            if (array_key_exists($field, $payload) && filled($payload[$field])) {
                $localAttributes[$field] = $payload[$field];
            }
        }

        if ($chatwootAttributes === [] && $localAttributes === []) {
            throw ValidationException::withMessages([
                'attributes' => 'Provide at least one contact or address field.',
            ]);
        }

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run has no Chatwoot connection.',
            ]);
        }

        if ($chatwootAttributes !== [] && ! $connection->hasAdminApiToken()) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The Chatwoot connection has no admin_api_token configured.',
            ]);
        }

        $localContact = $this->resolveLocalContact($payload, $connection->id);
        $remote = null;

        if ($chatwootAttributes !== []) {
            $chatwootContactId = (int) $localContact->chatwoot_contact_id;
            $client = new ChatwootAdminApiClient($connection);
            $remote = $client->updateContact($chatwootContactId, $chatwootAttributes);
        }

        DB::transaction(function () use ($chatwootAttributes, $localAttributes, $localContact, $remote): void {
            foreach ([...$chatwootAttributes, ...$localAttributes] as $field => $value) {
                $localContact->{$field} = $value;
            }

            if (is_array($remote) && is_array($remote['additional_attributes'] ?? null)) {
                $localContact->additional_attributes = $remote['additional_attributes'];
            }

            if (is_array($remote) && is_array($remote['custom_attributes'] ?? null)) {
                $localContact->chatwoot_custom_attributes = $remote['custom_attributes'];
            }

            $localContact->synced_at = Carbon::now();
            $localContact->save();
        });

        return [
            'status' => 'ok',
            'contact' => $remote ?? $this->contactPayload($localContact->refresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'chatwoot_contact_id' => $contact->chatwoot_contact_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone_number' => $contact->phone_number,
            'address_postal_code' => $contact->address_postal_code,
            'address_street' => $contact->address_street,
            'address_number' => $contact->address_number,
            'address_complement' => $contact->address_complement,
            'address_neighborhood' => $contact->address_neighborhood,
            'address_city' => $contact->address_city,
            'address_state' => $contact->address_state,
            'address_country' => $contact->address_country,
            'address_reference' => $contact->address_reference,
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
