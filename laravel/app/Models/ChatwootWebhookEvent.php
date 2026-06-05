<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatwootWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chatwoot_connection_id',
    'event_name',
    'chatwoot_account_id',
    'conversation_id',
    'chatwoot_message_id',
    'resolved_agent_id',
    'agent_run_id',
    'payload',
    'signature',
    'status',
    'received_at',
    'processing_started_at',
    'processed_at',
    'failed_at',
    'failure_reason',
    'ignored_reason',
    'failed_reason',
])]
class ChatwootWebhookEvent extends Model
{
    /** @use HasFactory<ChatwootWebhookEventFactory> */
    use HasFactory;

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

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function resolvedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'resolved_agent_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
