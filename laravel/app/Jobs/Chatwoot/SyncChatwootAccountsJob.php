<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Actions\Invitations\SendUserInvitation;
use App\Models\ChatwootPlatformConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chatwoot\ChatwootPlatformClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncChatwootAccountsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public bool $syncUsers = true)
    {
        $this->onQueue('chatwoot-sync');
    }

    public function handle(): void
    {
        $connection = ChatwootPlatformConnection::current();

        if (! $connection->exists || ! $connection->isConfigured()) {
            Log::warning('SyncChatwootAccountsJob: platform connection not configured');

            return;
        }

        $client = new ChatwootPlatformClient($connection);
        $summary = [
            'accounts_seen' => 0,
            'workspaces_upserted' => 0,
            'users_upserted' => 0,
            'members_upserted' => 0,
            'invites_sent' => 0,
        ];

        /** @var array<int, User> $newUsers */
        $newUsers = [];

        try {
            $accounts = $client->listAccounts();
            $summary['accounts_seen'] = count($accounts);

            foreach ($accounts as $account) {
                $accountId = (int) ($account['id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }

                $workspace = $this->upsertWorkspace($accountId, (string) ($account['name'] ?? "account-{$accountId}"));
                $summary['workspaces_upserted']++;

                if (! $this->syncUsers) {
                    continue;
                }

                $accountUsers = $client->listAccountUsers($accountId);
                foreach ($accountUsers as $accountUser) {
                    $userId = (int) ($accountUser['user_id'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }

                    $userData = $client->getUser($userId);
                    if ($userData === null) {
                        $summary['users_skipped'] = ($summary['users_skipped'] ?? 0) + 1;
                        Log::warning('SyncChatwoot: user skipped (not permissible)', [
                            'user_id' => $userId,
                            'account_id' => $accountId,
                        ]);

                        continue;
                    }

                    $wasNew = false;
                    $user = $this->upsertUser($userData, $wasNew);
                    if (! $user) {
                        continue;
                    }
                    $summary['users_upserted']++;

                    if ($wasNew) {
                        $newUsers[$user->id] = $user;
                    }

                    $chatwootRole = (string) ($accountUser['role'] ?? 'agent');
                    $role = $this->mapRole($chatwootRole);
                    $workspace->users()->syncWithoutDetaching([
                        $user->id => [
                            'role' => $role,
                            'chatwoot_user_id' => $userId,
                            'chatwoot_availability' => is_string($userData['availability_status'] ?? null)
                                ? (string) $userData['availability_status']
                                : null,
                            'chatwoot_confirmed' => (bool) ($userData['confirmed'] ?? false),
                            'chatwoot_role' => $chatwootRole,
                        ],
                    ]);
                    $summary['members_upserted']++;
                }
            }

            if (config('invitations.send_on_sync') && $newUsers !== []) {
                $action = app(SendUserInvitation::class);
                foreach ($newUsers as $newUser) {
                    try {
                        $action->execute($newUser, source: 'chatwoot_sync');
                        $summary['invites_sent']++;
                    } catch (Throwable $invitationError) {
                        Log::error('SyncChatwoot: invitation dispatch failed', [
                            'user_id' => $newUser->id,
                            'email' => $newUser->email,
                            'error' => $invitationError->getMessage(),
                        ]);
                    }
                }
            }

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
                'last_sync_summary' => $summary,
            ])->save();

            Log::info('SyncChatwootAccountsJob completed', $summary);
        } catch (Throwable $e) {
            $connection->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => 'error',
                'last_sync_error' => $e->getMessage(),
                'last_sync_summary' => $summary,
            ])->save();

            Log::error('SyncChatwootAccountsJob failed', [
                'message' => $e->getMessage(),
                'summary' => $summary,
            ]);

            throw $e;
        }
    }

    private function upsertWorkspace(int $accountId, string $name): Workspace
    {
        return DB::transaction(function () use ($accountId, $name) {
            $existing = Workspace::query()->where('chatwoot_account_id', $accountId)->first();

            if ($existing) {
                $existing->fill(['name' => $name])->save();

                return $existing;
            }

            return Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'chatwoot_account_id' => $accountId,
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => config('app.locale', 'en'),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $userData Payload de /platform/api/v1/users/{id}
     */
    private function upsertUser(array $userData, bool &$wasNew = false): ?User
    {
        $email = is_string($userData['email'] ?? null) ? mb_strtolower((string) $userData['email']) : null;
        if (! $email) {
            return null;
        }

        $name = (string) ($userData['name']
            ?? $userData['available_name']
            ?? Str::before($email, '@'));

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            // Existing user: sync only links workspace membership; never overwrites
            // personal data (name, password, is_super_admin).
            $wasNew = false;

            return $user;
        }

        $wasNew = true;

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(40)),
            'is_super_admin' => false,
        ]);
    }

    private function mapRole(string $chatwootRole): string
    {
        return match ($chatwootRole) {
            'administrator' => 'admin',
            'agent' => 'member',
            default => 'viewer',
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
