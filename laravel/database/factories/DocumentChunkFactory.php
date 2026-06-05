<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentDocument;
use App\Models\DocumentChunk;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    protected $model = DocumentChunk::class;

    public function definition(): array
    {
        $dim = 1536;

        return [
            'workspace_id' => Workspace::factory(),
            'agent_document_id' => AgentDocument::factory(),
            'chunk_index' => fake()->numberBetween(0, 100),
            'content' => fake()->paragraph(),
            'tokens' => fake()->numberBetween(50, 500),
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dim' => $dim,
            'metadata' => null,
            'embedding' => array_map(
                static fn (): float => fake()->randomFloat(6, -1, 1),
                array_fill(0, $dim, null),
            ),
        ];
    }
}
