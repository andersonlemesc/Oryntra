<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentDocumentStatus;
use App\Models\AgentDocument;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentDocument>
 */
class AgentDocumentFactory extends Factory
{
    protected $model = AgentDocument::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'mime_type' => 'text/markdown',
            'size_bytes' => fake()->numberBetween(256, 1_048_576),
            'storage_disk' => 's3',
            'storage_path' => 'workspaces/1/knowledge/' . Str::uuid()->toString() . '.md',
            'checksum' => hash('sha256', Str::random()),
            'tags' => null,
            'index_status' => AgentDocumentStatus::Pending,
            'index_error' => null,
            'indexed_at' => null,
            'chunks_count' => 0,
            'embedding_provider' => null,
            'embedding_model' => null,
            'embedding_dim' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['index_status' => AgentDocumentStatus::Pending]);
    }

    public function indexed(): static
    {
        return $this->state(fn (): array => [
            'index_status' => AgentDocumentStatus::Indexed,
            'indexed_at' => now(),
            'chunks_count' => fake()->numberBetween(1, 40),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dim' => 1536,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'index_status' => AgentDocumentStatus::Failed,
            'index_error' => 'extraction failed',
        ]);
    }
}
