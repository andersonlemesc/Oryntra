<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentChatwootBindingStatus;
use App\Enums\ChatwootConnectionStatus;
use App\Jobs\Chatwoot\DeleteChatwootAgentBotJob;
use App\Jobs\Chatwoot\SyncChatwootMetadataJob;
use Database\Factories\ChatwootConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'workspace_id',
    'connection_uuid',
    'name',
    'base_url',
    'account_id',
    'agent_bot_id',
    'agent_bot_outgoing_url',
    'api_access_token',
    'admin_api_token',
    'webhook_secret',
    'status',
    'provisioned_at',
    'provisioning_started_at',
    'provisioning_error',
])]
class ChatwootConnection extends Model
{
    /** @use HasFactory<ChatwootConnectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_access_token',
        'admin_api_token',
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        self::creating(function (ChatwootConnection $connection): void {
            if (blank($connection->connection_uuid)) {
                $connection->connection_uuid = (string) Str::uuid();
            }
        });

        self::deleting(function (ChatwootConnection $connection): void {
            if (filled($connection->agent_bot_id)) {
                DeleteChatwootAgentBotJob::dispatch((int) $connection->agent_bot_id)->afterCommit();
            }
        });

        self::saved(function (ChatwootConnection $connection): void {
            if ($connection->wasChanged('admin_api_token') && $connection->hasAdminApiToken()) {
                SyncChatwootMetadataJob::dispatch($connection->id)->afterCommit();
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<ChatwootWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(ChatwootWebhookEvent::class);
    }

    /**
     * @return HasMany<AgentChatwootBinding, $this>
     */
    public function agentBindings(): HasMany
    {
        return $this->hasMany(AgentChatwootBinding::class);
    }

    /**
     * @return HasOne<AgentChatwootBinding, $this>
     */
    public function activeAgentBinding(): HasOne
    {
        return $this->hasOne(AgentChatwootBinding::class)
            ->where('status', AgentChatwootBindingStatus::Active->value);
    }

    /**
     * @return array{api_access_token: string}
     */
    public function chatwootHeaders(): array
    {
        return [
            'api_access_token' => (string) $this->api_access_token,
        ];
    }

    /**
     * @return array{api_access_token: string}
     */
    public function chatwootAdminHeaders(): array
    {
        return [
            'api_access_token' => (string) $this->admin_api_token,
        ];
    }

    public function hasAdminApiToken(): bool
    {
        return filled($this->admin_api_token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_access_token' => 'encrypted',
            'admin_api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'status' => ChatwootConnectionStatus::class,
            'provisioned_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
        ];
    }
}
