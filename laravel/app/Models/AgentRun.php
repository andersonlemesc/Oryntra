<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentRunStatus;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'agent_id',
    'chatwoot_connection_id',
    'chatwoot_webhook_event_id',
    'chatwoot_account_id',
    'conversation_id',
    'chatwoot_message_id',
    'thread_id',
    'status',
    'input',
    'output',
    'error_message',
    'debounce_started_at',
    'debounce_until',
    'started_at',
    'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<ChatwootConnection, $this>
     */
    public function chatwootConnection(): BelongsTo
    {
        return $this->belongsTo(ChatwootConnection::class);
    }

    /**
     * @return BelongsTo<ChatwootWebhookEvent, $this>
     */
    public function kickoffEvent(): BelongsTo
    {
        return $this->belongsTo(ChatwootWebhookEvent::class, 'chatwoot_webhook_event_id');
    }

    /**
     * @return HasMany<ChatwootWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(ChatwootWebhookEvent::class, 'agent_run_id');
    }

    public function buildThreadId(): string
    {
        return sprintf(
            'workspace:%d:account:%d:conversation:%d',
            $this->workspace_id,
            $this->chatwoot_account_id,
            $this->conversation_id,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'debounce_started_at' => 'datetime',
            'debounce_until' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
