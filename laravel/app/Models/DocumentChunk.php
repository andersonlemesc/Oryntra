<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Embedding;
use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property int                       $agent_document_id
 * @property int                       $chunk_index
 * @property string                    $content
 * @property int|null                  $tokens
 * @property string                    $embedding_model
 * @property int                       $embedding_dim
 * @property array<string, mixed>|null $metadata
 * @property array<int, float>|null    $embedding
 */
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'agent_document_id',
        'chunk_index',
        'content',
        'tokens',
        'embedding_model',
        'embedding_dim',
        'metadata',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'tokens' => 'integer',
            'embedding_dim' => 'integer',
            'metadata' => 'array',
            'embedding' => Embedding::class,
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
     * @return BelongsTo<AgentDocument, $this>
     */
    public function agentDocument(): BelongsTo
    {
        return $this->belongsTo(AgentDocument::class);
    }
}
