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
use Illuminate\Support\Carbon;

/**
 * @property int                  $id
 * @property int                  $workspace_id
 * @property int                  $chatwoot_connection_id
 * @property int                  $chatwoot_contact_id
 * @property string|null          $identifier
 * @property string|null          $name
 * @property string|null          $email
 * @property string|null          $phone_number
 * @property string|null          $thumbnail
 * @property array<string, mixed> $additional_attributes
 * @property array<string, mixed> $chatwoot_custom_attributes
 * @property string               $lead_status
 * @property int|null             $lead_score
 * @property Carbon|null          $first_seen_at
 * @property Carbon|null          $last_seen_at
 * @property Carbon|null          $last_message_at
 * @property Carbon|null          $synced_at
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 * @property Carbon|null          $deleted_at
 */
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
