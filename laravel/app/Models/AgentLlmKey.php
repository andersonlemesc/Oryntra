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

#[Fillable([
    'workspace_id',
    'name',
    'provider',
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
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'llm_key_id');
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
