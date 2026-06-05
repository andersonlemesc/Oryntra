<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactMemorySource;
use App\Enums\ContactMemoryType;
use Database\Factories\ContactMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int                 $id
 * @property int                 $contact_id
 * @property int                 $workspace_id
 * @property ContactMemoryType   $type
 * @property string              $content
 * @property ContactMemorySource $source
 * @property float|null          $confidence
 * @property int|null            $conversation_id
 * @property int|null            $agent_run_id
 * @property int|null            $author_user_id
 * @property Carbon|null         $expires_at
 * @property Carbon|null         $created_at
 * @property Carbon|null         $updated_at
 */
#[Fillable([
    'contact_id',
    'workspace_id',
    'type',
    'content',
    'source',
    'confidence',
    'conversation_id',
    'agent_run_id',
    'author_user_id',
    'expires_at',
])]
class ContactMemory extends Model
{
    /** @use HasFactory<ContactMemoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactMemoryType::class,
            'source' => ContactMemorySource::class,
            'confidence' => 'float',
            'conversation_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
