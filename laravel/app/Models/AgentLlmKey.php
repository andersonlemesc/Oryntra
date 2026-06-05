<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use Database\Factories\AgentLlmKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int               $id
 * @property int               $workspace_id
 * @property string            $name
 * @property AgentLlmProvider  $provider
 * @property string|null       $base_url
 * @property string|null       $api_key
 * @property AgentLlmKeyStatus $status
 * @property Carbon|null       $last_used_at
 * @property Carbon|null       $created_at
 * @property Carbon|null       $updated_at
 */
#[Fillable([
    'workspace_id',
    'name',
    'provider',
    'base_url',
    'api_key',
    'status',
    'last_used_at',
])]
#[Hidden(['api_key'])]
class AgentLlmKey extends Model
{
    /** @use HasFactory<AgentLlmKeyFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function supervisedAgents(): HasMany
    {
        return $this->hasMany(Agent::class, 'supervisor_llm_key_id');
    }

    /**
     * @return HasMany<AgentSpecialist, $this>
     */
    public function specialists(): HasMany
    {
        return $this->hasMany(AgentSpecialist::class, 'llm_key_id');
    }

    /**
     * @return HasMany<AgentLlmModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AgentLlmModel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'status' => AgentLlmKeyStatus::class,
            'provider' => AgentLlmProvider::class,
            'last_used_at' => 'datetime',
        ];
    }
}
