<?php

namespace App\Models;

use App\Enums\ChatwootConnectionStatus;
use App\Jobs\Chatwoot\DeleteChatwootAgentBotJob;
use Database\Factories\ChatwootConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * @return array{api_access_token: string}
     */
    public function chatwootHeaders(): array
    {
        return [
            'api_access_token' => (string) $this->api_access_token,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'status' => ChatwootConnectionStatus::class,
            'provisioned_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
        ];
    }
}
