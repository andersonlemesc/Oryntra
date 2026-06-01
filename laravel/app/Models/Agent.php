<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property string                    $name
 * @property string|null               $description
 * @property AgentStatus               $status
 * @property AgentMode                 $mode
 * @property AgentResponseMode         $response_mode
 * @property string|null               $locale
 * @property string|null               $timezone
 * @property string|null               $supervisor_prompt
 * @property int|null                  $supervisor_llm_key_id
 * @property string|null               $supervisor_llm_model
 * @property int|null                  $fallback_specialist_id
 * @property array<string, mixed>|null $debounce_config
 * @property array<string, mixed>|null $guard_config
 * @property array<string, mixed>|null $rag_config
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
     * Catalog products scoped to this agent (empty = the product is global).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    /**
     * Knowledge-base (RAG) documents scoped to this agent.
     *
     * @return BelongsToMany<AgentDocument, $this>
     */
    public function knowledgeDocuments(): BelongsToMany
    {
        return $this->belongsToMany(AgentDocument::class, 'agent_knowledge_document')->withTimestamps();
    }

    /**
     * Standalone documents scoped to this agent.
     *
     * @return BelongsToMany<Document, $this>
     */
    public function standaloneDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'agent_standalone_document')->withTimestamps();
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
            'debounce_config' => 'array',
            'media_policy' => 'array',
            'guard_config' => 'array',
            'rag_config' => 'array',
            'runtime_config' => 'array',
        ];
    }
}
