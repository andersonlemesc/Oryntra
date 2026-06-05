<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductDocument>
 */
class ProductDocumentFactory extends Factory
{
    protected $model = ProductDocument::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'product_id' => Product::factory(),
            'original_filename' => fake()->word() . '.pdf',
            'filename' => Str::uuid()->toString() . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_242_880),
            'path' => 'documents/' . Str::uuid()->toString() . '.pdf',
            'metadata' => null,
        ];
    }
}
