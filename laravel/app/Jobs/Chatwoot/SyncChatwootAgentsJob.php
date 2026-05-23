<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Models\ChatwootConnection;
use App\Models\User;
use App\Services\Chatwoot\ChatwootAdminApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncChatwootAgentsJob implements ShouldQueue
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
            Log::warning('SyncChatwootAgentsJob: connection not found', [
                'chatwoot_connection_id' => $this->chatwootConnectionId,
            ]);

            return;
        }

        if (blank($connection->base_url) || ! $connection->hasAdminApiToken()) {
            Log::warning('SyncChatwootAgentsJob: connection missing admin_api_token', [
                'chatwoot_connection_id' => $connection->id,
            ]);

            return;
        }

        $client = new ChatwootAdminApiClient($connection);
        $summary = ['fetched' => 0, 'upserted' => 0, 'members_synced' => 0];

        try {
            $agents = $client->listAgents();
            $summary['fetched'] = count($agents);
            $workspaceId = (int) $connection->workspace_id;

            foreach ($agents as $agent) {
                $chatwootUserId = (int) ($agent['id'] ?? 0);
                $email = is_string($agent['email'] ?? null) ? mb_strtolower((string) $agent['email']) : null;

                if ($chatwootUserId <= 0 || $email === null) {
                    continue;
                }

                $user = User::query()->where('email', $email)->first();

                if (! $user instanceof User) {
                    $user = User::query()->create([
                        'name' => (string) ($agent['name'] ?? $agent['available_name'] ?? Str::before($email, '@')),
                        'email' => $email,
                        'password' => bcrypt(Str::random(40)),
                        'is_super_admin' => false,
                    ]);
                }

                $summary['upserted']++;

                $chatwootRole = (string) ($agent['role'] ?? 'agent');
                $localRole = $this->mapRole($chatwootRole);

                DB::table('workspace_members')->updateOrInsert(
                    [
                        'workspace_id' => $workspaceId,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => $localRole,
                        'chatwoot_user_id' => $chatwootUserId,
                        'chatwoot_availability' => is_string($agent['availability_status'] ?? null)
                            ? (string) $agent['availability_status']
                            : null,
                        'chatwoot_confirmed' => (bool) ($agent['confirmed'] ?? false),
                        'chatwoot_role' => $chatwootRole,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $summary['members_synced']++;
            }

            Log::info('SyncChatwootAgentsJob completed', [
                'chatwoot_connection_id' => $connection->id,
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            Log::error('SyncChatwootAgentsJob failed', [
                'chatwoot_connection_id' => $connection->id,
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            throw $e;
        }
    }

    private function mapRole(string $chatwootRole): string
    {
        return match ($chatwootRole) {
            'administrator' => 'admin',
            'agent' => 'member',
            default => 'viewer',
        };
    }
}
