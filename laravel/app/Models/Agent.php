<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'description',
    'status',
    'mode',
    'fallback_specialist_id',
    'locale',
    'timezone',
    'response_mode',
    'llm_provider',
    'llm_key_id',
    'llm_model',
    'llm_temperature',
    'llm_max_tokens',
    'system_prompt',
    'behavior_prompt',
    'fallback_message',
    'supervisor_prompt',
    'supervisor_llm_key_id',
    'supervisor_llm_model',
    'debounce_config',
    'media_policy',
    'guard_config',
    'rag_config',
    'runtime_config',
    'audio_llm_key_id',
    'audio_llm_model',
    'vision_llm_key_id',
    'vision_llm_model',
])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<AgentChatwootBinding, $this>
     */
    public function chatwootBindings(): HasMany
    {
        return $this->hasMany(AgentChatwootBinding::class);
    }

    /**
     * @return HasMany<AgentSpecialist, $this>
     */
    public function specialists(): HasMany
    {
        return $this->hasMany(AgentSpecialist::class);
    }

    /**
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function llmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'llm_key_id');
    }

    /**
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function supervisorLlmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'supervisor_llm_key_id');
    }

    /**
     * @return BelongsTo<AgentSpecialist, $this>
     */
    public function fallbackSpecialist(): BelongsTo
    {
        return $this->belongsTo(AgentSpecialist::class, 'fallback_specialist_id');
    }

    /**
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function audioLlmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'audio_llm_key_id');
    }

    /**
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function visionLlmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'vision_llm_key_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'mode' => AgentMode::class,
            'response_mode' => AgentResponseMode::class,
            'llm_provider' => AgentLlmProvider::class,
            'llm_temperature' => 'float',
            'llm_max_tokens' => 'integer',
            'debounce_config' => 'array',
            'media_policy' => 'array',
            'guard_config' => 'array',
            'rag_config' => 'array',
            'runtime_config' => 'array',
        ];
    }
}
