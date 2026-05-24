<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'category' => fake()->randomElement(['general', 'faq', 'policy', 'catalog']),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'original_filename' => fake()->word() . '.pdf',
            'filename' => Str::uuid()->toString() . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_242_880),
            'path' => 'documents/' . Str::uuid()->toString() . '.pdf',
            'metadata' => null,
        ];
    }
}