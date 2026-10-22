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

    /**
     * Monotonic source for `chunk_index`.
     *
     * `document_chunks` is UNIQUE (agent_document_id, chunk_index). Drawing the
     * index at random collided whenever a test made several chunks for one
     * document — three independent draws out of 101 values collide ~3% of the
     * time — which made AgentDocumentSchemaTest fail on roughly 1 run in 34.
     * A counter also mirrors production, where chunks are numbered in order.
     */
    private static int $nextChunkIndex = 0;

    public function definition(): array
    {
        $dim = 1536;

        return [
            'workspace_id' => Workspace::factory(),
            'agent_document_id' => AgentDocument::factory(),
            'chunk_index' => self::$nextChunkIndex++,
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
