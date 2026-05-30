<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agent;
use App\Models\PlaygroundConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundConversation>
 */
class PlaygroundConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => fn (array $attributes): int => Agent::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])->id,
            'contact_id' => null,
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'thread_id' => 'workspace:1:playground:' . fake()->unique()->numberBetween(1, 999999),
            'last_message_at' => now(),
        ];
    }
}
