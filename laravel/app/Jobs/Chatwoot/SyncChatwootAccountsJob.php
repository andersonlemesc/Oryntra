<?php

namespace App\Jobs\Chatwoot;

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

    public function __construct(public bool $syncUsers = true) {}

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
        ];

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
                    $user = $this->upsertUserFromAccountUser($accountUser);
                    if (! $user) {
                        continue;
                    }
                    $summary['users_upserted']++;

                    $role = $this->mapRole((string) ($accountUser['role'] ?? 'agent'));
                    $workspace->users()->syncWithoutDetaching([
                        $user->id => ['role' => $role],
                    ]);
                    $summary['members_upserted']++;
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
     * @param  array<string, mixed>  $accountUser
     */
    private function upsertUserFromAccountUser(array $accountUser): ?User
    {
        $email = $this->extractEmail($accountUser);
        if (! $email) {
            return null;
        }

        $name = (string) ($accountUser['user']['name']
            ?? $accountUser['name']
            ?? Str::before($email, '@'));

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            if ($user->name !== $name) {
                $user->forceFill(['name' => $name])->save();
            }

            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(40)),
            'is_super_admin' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $accountUser
     */
    private function extractEmail(array $accountUser): ?string
    {
        $email = $accountUser['user']['email'] ?? $accountUser['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
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
