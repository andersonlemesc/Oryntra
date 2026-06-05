<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncChatwootContactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    private const MAX_PAGES = 50;

    public function __construct(public int $chatwootConnectionId)
    {
        $this->onQueue('chatwoot-sync');
    }

    public function handle(): void
    {
        $connection = ChatwootConnection::query()->find($this->chatwootConnectionId);

        if ($connection === null || ! $connection->hasAdminApiToken()) {
            return;
        }

        try {
            $client = new ChatwootAdminApiClient($connection);
        } catch (Throwable $exception) {
            Log::warning('sync_chatwoot_contacts.client_init_failed', [
                'chatwoot_connection_id' => $this->chatwootConnectionId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $page = 1;
        $now = Carbon::now();

        while ($page <= self::MAX_PAGES) {
            try {
                $response = $client->listContactsPage($page);
            } catch (Throwable $exception) {
                Log::warning('sync_chatwoot_contacts.page_failed', [
                    'chatwoot_connection_id' => $this->chatwootConnectionId,
                    'page' => $page,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $contacts = $response['contacts'];

            if ($contacts === []) {
                return;
            }

            foreach ($contacts as $remote) {
                $this->syncContact($connection, $remote, $now);
            }

            $meta = $response['meta'];
            $totalCount = (int) ($meta['count'] ?? 0);
            $pageSize = max(1, count($contacts));

            if ($totalCount > 0 && ($page * $pageSize) >= $totalCount) {
                return;
            }

            $page++;
        }
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function syncContact(ChatwootConnection $connection, array $remote, Carbon $now): void
    {
        $chatwootContactId = $remote['id'] ?? null;

        if (! is_int($chatwootContactId) && ! ctype_digit((string) $chatwootContactId)) {
            return;
        }

        $contact = Contact::query()->firstOrNew([
            'workspace_id' => (int) $connection->workspace_id,
            'chatwoot_account_id' => (int) $connection->account_id,
            'chatwoot_contact_id' => (int) $chatwootContactId,
        ]);

        if (! $contact->exists) {
            $contact->chatwoot_connection_id = (int) $connection->id;
            $contact->first_seen_at = $now;
            $contact->last_seen_at = $now;
            $contact->lead_status = 'new';
        }

        // Chatwoot is the source of truth for these fields.
        $contact->additional_attributes = is_array($remote['additional_attributes'] ?? null)
            ? $remote['additional_attributes']
            : ($contact->additional_attributes ?? []);
        $contact->chatwoot_custom_attributes = is_array($remote['custom_attributes'] ?? null)
            ? $remote['custom_attributes']
            : ($contact->chatwoot_custom_attributes ?? []);

        // Name/email/phone: only adopt the remote value when the local cell is empty
        // OR the remote value differs (Chatwoot wins). lead_status is local-only.
        $contact->name = $this->preferRemote($remote['name'] ?? null, $contact->name);
        $contact->email = $this->preferRemote($remote['email'] ?? null, $contact->email);
        $contact->phone_number = $this->preferRemote($remote['phone_number'] ?? null, $contact->phone_number);
        $contact->identifier = $this->preferRemote($remote['identifier'] ?? null, $contact->identifier);
        $contact->thumbnail = $this->preferRemote($remote['thumbnail'] ?? null, $contact->thumbnail);

        $contact->synced_at = $now;
        $contact->save();
    }

    private function preferRemote(mixed $remote, ?string $local): ?string
    {
        if (! is_string($remote)) {
            return $local;
        }

        $remote = trim($remote);

        if ($remote === '') {
            return $local;
        }

        return $remote;
    }
}
