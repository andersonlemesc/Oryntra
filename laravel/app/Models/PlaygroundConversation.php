<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlaygroundConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $workspace_id
 * @property int         $agent_id
 * @property int|null    $contact_id
 * @property int         $user_id
 * @property string|null $title
 * @property string      $thread_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'agent_id',
    'contact_id',
    'user_id',
    'title',
    'thread_id',
    'last_message_at',
])]
class PlaygroundConversation extends Model
{
    /** @use HasFactory<PlaygroundConversationFactory> */
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PlaygroundMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(PlaygroundMessage::class);
    }

    public function buildThreadId(): string
    {
        return sprintf('workspace:%d:playground:%d', $this->workspace_id, $this->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }
}
