<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExternalToolKind;
use App\Models\ExternalTool;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalTool>
 */
class ExternalToolFactory extends Factory
{
    protected $model = ExternalTool::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => ExternalToolKind::HttpConnector,
            'slug' => 'query_' . fake()->unique()->word(),
            'label' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'enabled' => true,
            'config' => [
                'http_method' => 'GET',
                'base_url' => 'https://api.example.test',
                'path' => '/status',
                'auth_type' => 'none',
                'auth_config' => [],
                'static_headers' => [],
                'param_schema' => [
                    'properties' => [
                        'order_id' => [
                            'type' => 'string',
                            'description' => 'ID do pedido a consultar.',
                            'location' => 'query',
                            'required' => false,
                        ],
                    ],
                ],
                'response_extraction' => [
                    'mode' => 'jsonpath',
                    'expression' => '$',
                    'max_length' => 2000,
                ],
                'timeout_seconds' => null,
            ],
            'credentials' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
