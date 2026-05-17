<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'chatwoot_account_id', 'timezone', 'locale'])]
class Workspace extends Model implements HasName
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ChatwootConnection, $this>
     */
    public function chatwootConnections(): HasMany
    {
        return $this->hasMany(ChatwootConnection::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasMany<AgentChatwootBinding, $this>
     */
    public function agentChatwootBindings(): HasMany
    {
        return $this->hasMany(AgentChatwootBinding::class);
    }

    /**
     * @return HasMany<AgentLlmKey, $this>
     */
    public function agentLlmKeys(): HasMany
    {
        return $this->hasMany(AgentLlmKey::class);
    }

    /**
     * @return HasMany<ChatwootWebhookEvent, $this>
     */
    public function chatwootWebhookEvents(): HasMany
    {
        return $this->hasMany(ChatwootWebhookEvent::class);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }
}
