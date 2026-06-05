<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentLlmKey;
use App\Models\AgentLlmModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentLlmModel>
 */
class AgentLlmModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $model = fake()->unique()->slug(2);

        return [
            'agent_llm_key_id' => AgentLlmKey::factory(),
            'model_id' => $model,
            'label' => $model,
            'synced_at' => now(),
        ];
    }
}
