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
            'handoff_config' => [
                'enabled' => false,
                'default_priority' => 'normal',
                'customer_message' => 'Vou transferir voce para um atendente.',
                'rules' => [],
            ],
            'contact_tools_config' => [
                'update_enabled' => false,
                'update_fields' => ['name', 'email', 'phone_number'],
            ],
            'product_tools_config' => [
                'query_enabled' => false,
            ],
            'memory_config' => [
                'extraction_enabled' => false,
                'injection_enabled' => false,
                'extraction_types' => [],
                'injection_limit' => null,
                'max_tool_iterations' => 4,
            ],
            'resolution_config' => [
                'enabled' => false,
                'customer_message' => null,
                'label_name' => null,
                'rules' => [],
            ],
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
