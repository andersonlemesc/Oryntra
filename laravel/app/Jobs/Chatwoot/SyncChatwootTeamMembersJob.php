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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncChatwootTeamMembersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $chatwootConnectionId)
    {
        $this->onQueue('chatwoot-sync');
    }

    public function handle(): void
    {
        $connection = ChatwootConnection::query()->find($this->chatwootConnectionId);

        if ($connection === null) {
            Log::warning('SyncChatwootTeamMembersJob: connection not found', [
                'chatwoot_connection_id' => $this->chatwootConnectionId,
            ]);

            return;
        }

        if (blank($connection->base_url) || ! $connection->hasAdminApiToken()) {
            Log::warning('SyncChatwootTeamMembersJob: connection missing admin_api_token', [
                'chatwoot_connection_id' => $connection->id,
            ]);

            return;
        }

        $client = new ChatwootAdminApiClient($connection);
        $workspaceId = (int) $connection->workspace_id;
        $teams = ChatwootTeam::query()->where('chatwoot_connection_id', $connection->id)->get();
        $summary = ['teams' => $teams->count(), 'members_upserted' => 0];

        try {
            foreach ($teams as $team) {
                $members = $client->listTeamMembers((int) $team->chatwoot_team_id);
                $seenUserIds = [];
                $now = now();

                foreach ($members as $member) {
                    $chatwootUserId = (int) ($member['id'] ?? 0);

                    if ($chatwootUserId <= 0) {
                        continue;
                    }

                    DB::table('chatwoot_team_members')->updateOrInsert(
                        [
                            'chatwoot_connection_id' => $connection->id,
                            'chatwoot_team_id' => (int) $team->chatwoot_team_id,
                            'chatwoot_user_id' => $chatwootUserId,
                        ],
                        [
                            'workspace_id' => $workspaceId,
                            'synced_at' => $now,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );

                    $seenUserIds[] = $chatwootUserId;
                    $summary['members_upserted']++;
                }

                DB::table('chatwoot_team_members')
                    ->where('chatwoot_connection_id', $connection->id)
                    ->where('chatwoot_team_id', (int) $team->chatwoot_team_id)
                    ->when($seenUserIds !== [], fn ($q) => $q->whereNotIn('chatwoot_user_id', $seenUserIds))
                    ->delete();
            }

            Log::info('SyncChatwootTeamMembersJob completed', [
                'chatwoot_connection_id' => $connection->id,
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            Log::error('SyncChatwootTeamMembersJob failed', [
                'chatwoot_connection_id' => $connection->id,
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            throw $e;
        }
    }
}
