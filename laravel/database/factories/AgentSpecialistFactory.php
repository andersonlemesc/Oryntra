<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
use App\Models\AgentSpecialist;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentSpecialist>
 */
class AgentSpecialistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => Agent::factory(),
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
            'role_prompt' => 'You are a specialist for this domain.',
            'intent_keywords' => ['support', 'help'],
            'llm_model' => 'gpt-4.1-mini',
            'llm_temperature' => 0.2,
            'tools_allowlist' => [],
            'priority' => 100,
            'confidence_threshold' => 0.6,
            'status' => AgentSpecialistStatus::Active,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => AgentSpecialistStatus::Inactive]);
    }
}
