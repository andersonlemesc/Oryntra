<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentDocumentStatus;
use Database\Factories\AgentDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int                     $id
 * @property int                     $workspace_id
 * @property string                  $name
 * @property string|null             $description
 * @property string                  $mime_type
 * @property int                     $size_bytes
 * @property string                  $storage_disk
 * @property string                  $storage_path
 * @property string|null             $checksum
 * @property array<int, string>|null $tags
 * @property AgentDocumentStatus     $index_status
 * @property string|null             $index_error
 * @property Carbon|null             $indexed_at
 * @property int                     $chunks_count
 * @property string|null             $embedding_provider
 * @property string|null             $embedding_model
 * @property int|null                $embedding_dim
 */
class AgentDocument extends Model
{
    /** @use HasFactory<AgentDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'checksum',
        'tags',
        'extractor_llm_key_id',
        'extractor_model',
        'index_status',
        'index_error',
        'indexed_at',
        'chunks_count',
        'embedding_provider',
        'embedding_model',
        'embedding_dim',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'index_status' => AgentDocumentStatus::class,
            'indexed_at' => 'datetime',
            'size_bytes' => 'integer',
            'chunks_count' => 'integer',
            'embedding_dim' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * PDF-only BYOK key used to extract text when the lib path is insufficient.
     *
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function extractorLlmKey(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'extractor_llm_key_id');
    }

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Agents this knowledge document is scoped to. No links = global.
     *
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_knowledge_document')->withTimestamps();
    }

    /**
     * Limit to documents visible to the given agent: linked to it, or global.
     *
     * @param  Builder<AgentDocument> $query
     * @return Builder<AgentDocument>
     */
    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where(function (Builder $q) use ($agentId): void {
            $q->whereHas('agents', function (Builder $agentQuery) use ($agentId): void {
                $agentQuery->whereKey($agentId);
            })->orWhereDoesntHave('agents');
        });
    }

    public function disk(): string
    {
        return $this->storage_disk ?: 's3';
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk($this->disk())->temporaryUrl($this->storage_path, now()->addMinutes($minutes));
    }
}
