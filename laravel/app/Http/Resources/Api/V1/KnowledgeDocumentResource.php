<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AgentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentDocument
 */
class KnowledgeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'tags' => $this->tags,
            'index_status' => $this->index_status->value,
            'index_error' => $this->index_error,
            'chunks_count' => $this->chunks_count,
            'indexed_at' => $this->indexed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
