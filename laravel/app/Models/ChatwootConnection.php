<?php

namespace App\Models;

use App\Enums\ChatwootConnectionStatus;
use Database\Factories\ChatwootConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'workspace_id',
    'connection_uuid',
    'name',
    'base_url',
    'account_id',
    'api_access_token',
    'webhook_secret',
    'status',
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
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
        ];
    }
}
