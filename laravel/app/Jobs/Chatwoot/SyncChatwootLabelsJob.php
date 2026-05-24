<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Models\ChatwootConnection;
use App\Models\ChatwootLabel;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncChatwootLabelsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $chatwootConnectionId)
    {
        $this->onQueue('chatwoot-sync');
    }

    public function handle(): void
    {
        $connection = ChatwootConnection::query()->find($this->chatwootConnectionId);

        if ($connection === null) {
            Log::warning('SyncChatwootLabelsJob: connection not found', [
                'chatwoot_connection_id' => $this->chatwootConnectionId,
            ]);

            return;
        }

        if (blank($connection->base_url) || ! $connection->hasAdminApiToken()) {
            Log::warning('SyncChatwootLabelsJob: connection missing admin_api_token', [
                'chatwoot_connection_id' => $connection->id,
            ]);

            return;
        }

        $client = new ChatwootAdminApiClient($connection);
        $summary = ['fetched' => 0, 'upserted' => 0];

        try {
            $labels = $client->listLabels();
            $summary['fetched'] = count($labels);
            $seenTitles = [];

            foreach ($labels as $label) {
                $title = is_string($label['title'] ?? null) ? trim($label['title']) : '';

                if ($title === '') {
                    continue;
                }

                $chatwootLabelId = isset($label['id']) && is_numeric($label['id'])
                    ? (int) $label['id']
                    : null;

                ChatwootLabel::query()->updateOrCreate(
                    [
                        'chatwoot_connection_id' => $connection->id,
                        'title' => $title,
                    ],
                    [
                        'workspace_id' => (int) $connection->workspace_id,
                        'chatwoot_label_id' => $chatwootLabelId,
                        'description' => is_string($label['description'] ?? null) ? $label['description'] : null,
                        'color' => is_string($label['color'] ?? null) ? $label['color'] : null,
                        'show_on_sidebar' => (bool) ($label['show_on_sidebar'] ?? true),
                        'synced_at' => now(),
                    ],
                );

                $seenTitles[] = $title;
                $summary['upserted']++;
            }

            ChatwootLabel::query()
                ->where('chatwoot_connection_id', $connection->id)
                ->when($seenTitles !== [], fn ($query) => $query->whereNotIn('title', $seenTitles))
                ->delete();

            Log::info('SyncChatwootLabelsJob completed', [
                'chatwoot_connection_id' => $connection->id,
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            Log::error('SyncChatwootLabelsJob failed', [
                'chatwoot_connection_id' => $connection->id,
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            throw $e;
        }
    }
}
