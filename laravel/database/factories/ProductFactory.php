<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'category_id' => null,
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 10, 1000),
            'metadata' => null,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    public function inCategory(string $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category_id' => Category::factory()->state([
                'workspace_id' => $attributes['workspace_id'] ?? Workspace::factory(),
                'name' => $category,
                'slug' => Str::slug($category),
            ]),
        ]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $category->workspace_id,
            'category_id' => $category->id,
        ]);
    }
}
