<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlaygroundMessageRole;
use App\Enums\PlaygroundMessageStatus;
use Database\Factories\PlaygroundMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int                          $id
 * @property int                          $playground_conversation_id
 * @property int|null                     $agent_run_id
 * @property PlaygroundMessageRole        $role
 * @property string|null                  $content
 * @property PlaygroundMessageStatus|null $status
 * @property int|null                     $specialist_id
 * @property array<int, mixed>|null       $trace
 * @property array<string, mixed>|null    $usage
 * @property array<string, mixed>|null    $response
 * @property string|null                  $error_message
 * @property Carbon|null                  $created_at
 * @property Carbon|null                  $updated_at
 */
#[Fillable([
    'playground_conversation_id',
    'agent_run_id',
    'role',
    'content',
    'status',
    'specialist_id',
    'trace',
    'usage',
    'response',
    'error_message',
])]
class PlaygroundMessage extends Model
{
    /** @use HasFactory<PlaygroundMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<PlaygroundConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PlaygroundConversation::class, 'playground_conversation_id');
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
            'role' => PlaygroundMessageRole::class,
            'status' => PlaygroundMessageStatus::class,
            'trace' => 'array',
            'usage' => 'array',
            'response' => 'array',
        ];
    }
}
