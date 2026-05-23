<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'chatwoot_connection_id',
    'chatwoot_contact_id',
    'identifier',
    'name',
    'email',
    'phone_number',
    'thumbnail',
    'additional_attributes',
    'chatwoot_custom_attributes',
    'lead_status',
    'lead_score',
    'first_seen_at',
    'last_seen_at',
    'last_message_at',
    'synced_at',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chatwoot_contact_id' => 'integer',
            'additional_attributes' => 'array',
            'chatwoot_custom_attributes' => 'array',
            'lead_score' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_message_at' => 'datetime',
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

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * @return HasMany<ContactMemory, $this>
     */
    public function memories(): HasMany
    {
        return $this->hasMany(ContactMemory::class);
    }
}
