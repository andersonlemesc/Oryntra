<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chatwoot_connection_id',
    'chatwoot_team_id',
    'name',
    'description',
    'allow_auto_assign',
    'synced_at',
])]
class ChatwootTeam extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chatwoot_team_id' => 'integer',
            'allow_auto_assign' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<ChatwootConnection, $this>
     */
    public function chatwootConnection(): BelongsTo
    {
        return $this->belongsTo(ChatwootConnection::class);
    }
}
