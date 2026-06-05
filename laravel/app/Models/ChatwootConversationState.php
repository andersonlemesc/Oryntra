<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-conversation runtime state for a Chatwoot conversation, keyed by
 * (chatwoot_connection_id, conversation_id). Currently tracks human takeover:
 * once a human agent replies publicly, the bot stops auto-answering that
 * conversation until it is resolved.
 *
 * @property int         $id
 * @property int         $workspace_id
 * @property int         $chatwoot_connection_id
 * @property int         $conversation_id
 * @property Carbon|null $human_takeover_at
 */
class ChatwootConversationState extends Model
{
    protected $fillable = [
        'workspace_id',
        'chatwoot_connection_id',
        'conversation_id',
        'human_takeover_at',
    ];

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

    public static function hasHumanTakeover(int $chatwootConnectionId, int $conversationId): bool
    {
        return self::query()
            ->where('chatwoot_connection_id', $chatwootConnectionId)
            ->where('conversation_id', $conversationId)
            ->whereNotNull('human_takeover_at')
            ->exists();
    }

    public static function markHumanTakeover(int $workspaceId, int $chatwootConnectionId, int $conversationId): void
    {
        self::query()->updateOrCreate(
            [
                'chatwoot_connection_id' => $chatwootConnectionId,
                'conversation_id' => $conversationId,
            ],
            [
                'workspace_id' => $workspaceId,
                'human_takeover_at' => Carbon::now(),
            ],
        );
    }

    public static function clearHumanTakeover(int $chatwootConnectionId, int $conversationId): void
    {
        self::query()
            ->where('chatwoot_connection_id', $chatwootConnectionId)
            ->where('conversation_id', $conversationId)
            ->whereNotNull('human_takeover_at')
            ->update(['human_takeover_at' => null]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'human_takeover_at' => 'datetime',
        ];
    }
}
