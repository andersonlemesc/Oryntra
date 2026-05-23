<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentSpecialistStatus;
use Database\Factories\AgentSpecialistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'agent_id',
    'name',
    'description',
    'role_prompt',
    'intent_keywords',
    'llm_key_id',
    'llm_model',
    'llm_temperature',
    'tools_allowlist',
    'handoff_config',
    'contact_tools_config',
    'memory_config',
    'priority',
    'confidence_threshold',
    'fallback_specialist_id',
    'status',
])]
class AgentSpecialist extends Model
{
    /** @use HasFactory<AgentSpecialistFactory> */
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
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function llmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'llm_key_id');
    }

    /**
     * @return BelongsTo<AgentSpecialist, $this>
     */
    public function fallbackSpecialist(): BelongsTo
    {
        return $this->belongsTo(AgentSpecialist::class, 'fallback_specialist_id');
    }

    /**
     * @return HasMany<AgentSpecialist, $this>
     */
    public function fallbackForSpecialists(): HasMany
    {
        return $this->hasMany(AgentSpecialist::class, 'fallback_specialist_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intent_keywords' => 'array',
            'tools_allowlist' => 'array',
            'handoff_config' => 'array',
            'contact_tools_config' => 'array',
            'memory_config' => 'array',
            'llm_temperature' => 'float',
            'priority' => 'integer',
            'confidence_threshold' => 'float',
            'status' => AgentSpecialistStatus::class,
        ];
    }
}
