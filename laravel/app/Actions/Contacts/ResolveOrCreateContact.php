<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class ResolveOrCreateContact
{
    /**
     * Persist or refresh a Contact for the given workspace + Chatwoot connection.
     *
     * @param array<string, mixed> $sender Sender block from the Chatwoot webhook payload
     */
    public function execute(
        int $workspaceId,
        int $chatwootConnectionId,
        array $sender,
        ?Carbon $messageAt = null,
    ): Contact {
        $chatwootContactId = $this->intValue($sender['id'] ?? null);

        if ($chatwootContactId === null) {
            throw new \InvalidArgumentException('Sender id is required to resolve a contact.');
        }

        $now = Carbon::now();
        $messageAt ??= $now;

        $attributes = [
            'workspace_id' => $workspaceId,
            'chatwoot_connection_id' => $chatwootConnectionId,
            'chatwoot_contact_id' => $chatwootContactId,
        ];

        $payload = [
            'identifier' => $this->stringValue($sender['identifier'] ?? null),
            'name' => $this->stringValue($sender['name'] ?? null),
            'email' => $this->stringValue($sender['email'] ?? null),
            'phone_number' => $this->stringValue($sender['phone_number'] ?? null),
            'thumbnail' => $this->stringValue($sender['thumbnail'] ?? null),
            'additional_attributes' => $this->arrayValue($sender['additional_attributes'] ?? null),
            'chatwoot_custom_attributes' => $this->arrayValue($sender['custom_attributes'] ?? null),
        ];

        try {
            $contact = Contact::query()->firstOrCreate(
                $attributes,
                array_merge($payload, [
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'last_message_at' => $messageAt,
                    'lead_status' => 'new',
                ]),
            );
        } catch (QueryException) {
            $contact = Contact::query()->where($attributes)->firstOrFail();
        }

        if (! $contact->wasRecentlyCreated) {
            $contact->fill($payload);
            $contact->last_seen_at = $now;

            if ($messageAt !== null && ($contact->last_message_at === null || $messageAt->greaterThan($contact->last_message_at))) {
                $contact->last_message_at = $messageAt;
            }

            if ($contact->isDirty()) {
                $contact->save();
            }
        }

        return $contact;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
