<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Models\AgentLlmKey;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentLlmKey>
 */
class AgentLlmKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->company() . ' Key',
            'provider' => AgentLlmProvider::OpenAI,
            'api_key' => 'sk-' . Str::random(40),
            'status' => AgentLlmKeyStatus::Active,
            'last_used_at' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => AgentLlmKeyStatus::Inactive]);
    }

    public function provider(AgentLlmProvider $provider): self
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }
}
