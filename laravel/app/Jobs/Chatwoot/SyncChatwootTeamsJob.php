<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Models\ChatwootConnection;
use App\Models\ChatwootTeam;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncChatwootTeamsJob implements ShouldQueue
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
            Log::warning('SyncChatwootTeamsJob: connection not found', [
                'chatwoot_connection_id' => $this->chatwootConnectionId,
            ]);

            return;
        }

        if (blank($connection->base_url) || ! $connection->hasAdminApiToken()) {
            Log::warning('SyncChatwootTeamsJob: connection missing admin_api_token', [
                'chatwoot_connection_id' => $connection->id,
            ]);

            return;
        }

        $client = new ChatwootAdminApiClient($connection);
        $summary = ['fetched' => 0, 'upserted' => 0];

        try {
            $teams = $client->listTeams();
            $summary['fetched'] = count($teams);
            $seenIds = [];

            foreach ($teams as $team) {
                $chatwootTeamId = (int) ($team['id'] ?? 0);

                if ($chatwootTeamId <= 0) {
                    continue;
                }

                ChatwootTeam::query()->updateOrCreate(
                    [
                        'chatwoot_connection_id' => $connection->id,
                        'chatwoot_team_id' => $chatwootTeamId,
                    ],
                    [
                        'workspace_id' => (int) $connection->workspace_id,
                        'name' => (string) ($team['name'] ?? "team-{$chatwootTeamId}"),
                        'description' => is_string($team['description'] ?? null) ? $team['description'] : null,
                        'allow_auto_assign' => (bool) ($team['allow_auto_assign'] ?? false),
                        'synced_at' => now(),
                    ],
                );

                $seenIds[] = $chatwootTeamId;
                $summary['upserted']++;
            }

            ChatwootTeam::query()
                ->where('chatwoot_connection_id', $connection->id)
                ->when($seenIds !== [], fn ($query) => $query->whereNotIn('chatwoot_team_id', $seenIds))
                ->delete();

            Log::info('SyncChatwootTeamsJob completed', [
                'chatwoot_connection_id' => $connection->id,
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            Log::error('SyncChatwootTeamsJob failed', [
                'chatwoot_connection_id' => $connection->id,
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            throw $e;
        }
    }
}
