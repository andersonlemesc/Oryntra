<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentRunSource;
use App\Enums\AgentRunStatus;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property int                       $agent_id
 * @property AgentRunSource            $source
 * @property int|null                  $chatwoot_connection_id
 * @property int|null                  $contact_id
 * @property int|null                  $chatwoot_webhook_event_id
 * @property int|null                  $chatwoot_account_id
 * @property int|null                  $conversation_id
 * @property string|null               $chatwoot_message_id
 * @property string|null               $thread_id
 * @property AgentRunStatus            $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null               $error_message
 * @property Carbon|null               $debounce_started_at
 * @property Carbon|null               $debounce_until
 * @property Carbon|null               $started_at
 * @property Carbon|null               $finished_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
#[Fillable([
    'workspace_id',
    'agent_id',
    'source',
    'chatwoot_connection_id',
    'contact_id',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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

    /**
     * Limit a query to Chatwoot-originated runs (excludes playground test runs).
     *
     * @param  Builder<AgentRun> $query
     * @return Builder<AgentRun>
     */
    public function scopeFromChatwoot(Builder $query): Builder
    {
        return $query->where('source', AgentRunSource::Chatwoot->value);
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
            'source' => AgentRunSource::class,
            'input' => 'array',
            'output' => 'array',
            'debounce_started_at' => 'datetime',
            'debounce_until' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
