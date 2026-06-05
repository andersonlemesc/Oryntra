<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentChatwootBindingStatus;
use Database\Factories\AgentChatwootBindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'agent_id',
    'chatwoot_connection_id',
    'status',
    'inbox_ids',
    'ignore_assigned_conversations',
    'ignore_label_names',
    'handoff_label_name',
    'handoff_team_id',
    'handoff_team_name',
    'handoff_agent_id',
    'handoff_agent_name',
    'handoff_private_note_template',
    'handoff_assign_strategy',
])]
class AgentChatwootBinding extends Model
{
    /** @use HasFactory<AgentChatwootBindingFactory> */
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentChatwootBindingStatus::class,
            'inbox_ids' => 'array',
            'ignore_assigned_conversations' => 'boolean',
            'ignore_label_names' => 'array',
            'handoff_team_id' => 'integer',
            'handoff_agent_id' => 'integer',
        ];
    }
}
