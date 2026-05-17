<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentLlmProvider;
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
    'debounce_config',
    'media_policy',
    'guard_config',
    'rag_config',
    'runtime_config',
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
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function llmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'llm_key_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
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
