<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 10, 1000),
            'category' => fake()->randomElement(['Eletronicos', 'Acessorios', 'Roupas', 'Calcados']),
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
            'category' => $category,
        ]);
    }
}