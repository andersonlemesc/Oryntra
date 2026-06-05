<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Contact;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class GetChatwootContact
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int} $payload
     * @return array{status:string,contact:array<string, mixed>,source:string}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $this->assertSpecialistCanUse($payload, 'chatwoot_get_contact');

        $connection = $run->chatwootConnection;

        if ($connection === null) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run has no Chatwoot connection.',
            ]);
        }

        $cached = $this->loadCachedContact($payload);

        if ($cached === null) {
            throw ValidationException::withMessages([
                'contact_id' => 'The contact does not belong to this workspace and Chatwoot connection.',
            ]);
        }

        $chatwootContactId = (int) $cached->chatwoot_contact_id;

        if ($this->isFresh($cached)) {
            return [
                'status' => 'ok',
                'contact' => $this->serializeCached($cached),
                'source' => 'cache',
            ];
        }

        if (! $connection->hasAdminApiToken()) {
            return [
                'status' => 'ok',
                'contact' => $this->serializeCached($cached),
                'source' => 'cache_stale',
            ];
        }

        $client = new ChatwootAdminApiClient($connection);
        $remote = $client->getContact($chatwootContactId);
        $this->syncLocalContact($cached, $remote);

        return [
            'status' => 'ok',
            'contact' => $remote,
            'source' => 'chatwoot',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loadCachedContact(array $payload): ?Contact
    {
        return Contact::query()
            ->where('id', $payload['contact_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->first();
    }

    private function isFresh(Contact $contact): bool
    {
        if ($contact->synced_at === null) {
            return false;
        }

        return $contact->synced_at->greaterThan(Carbon::now()->subSeconds(self::CACHE_TTL_SECONDS));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCached(Contact $contact): array
    {
        return [
            'id' => $contact->chatwoot_contact_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone_number' => $contact->phone_number,
            'identifier' => $contact->identifier,
            'thumbnail' => $contact->thumbnail,
            'additional_attributes' => $contact->additional_attributes,
            'custom_attributes' => $contact->chatwoot_custom_attributes,
        ];
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function syncLocalContact(Contact $contact, array $remote): void
    {
        $contact->name = $this->stringValue($remote['name'] ?? null) ?? $contact->name;
        $contact->email = $this->stringValue($remote['email'] ?? null) ?? $contact->email;
        $contact->phone_number = $this->stringValue($remote['phone_number'] ?? null) ?? $contact->phone_number;
        $contact->identifier = $this->stringValue($remote['identifier'] ?? null) ?? $contact->identifier;
        $contact->thumbnail = $this->stringValue($remote['thumbnail'] ?? null) ?? $contact->thumbnail;
        $contact->additional_attributes = is_array($remote['additional_attributes'] ?? null)
            ? $remote['additional_attributes']
            : ($contact->additional_attributes ?? []);
        $contact->chatwoot_custom_attributes = is_array($remote['custom_attributes'] ?? null)
            ? $remote['custom_attributes']
            : ($contact->chatwoot_custom_attributes ?? []);
        $contact->synced_at = Carbon::now();
        $contact->save();
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
