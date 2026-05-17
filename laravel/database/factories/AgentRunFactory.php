<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => fn (array $attributes): int => Agent::factory()
                ->active()
                ->create(['workspace_id' => $attributes['workspace_id']])->id,
            'chatwoot_connection_id' => fn (array $attributes): int => ChatwootConnection::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])->id,
            'chatwoot_webhook_event_id' => null,
            'chatwoot_account_id' => fake()->numberBetween(1, 9999),
            'conversation_id' => fake()->numberBetween(1, 9999),
            'chatwoot_message_id' => (string) fake()->numberBetween(1, 999999),
            'thread_id' => 'workspace:1:account:1:conversation:1',
            'status' => AgentRunStatus::Debouncing,
            'input' => ['messages' => []],
            'output' => null,
            'error_message' => null,
            'debounce_started_at' => now(),
            'debounce_until' => now()->addSeconds(8),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => AgentRunStatus::Completed,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'output' => ['type' => 'text', 'content' => 'mock response'],
        ]);
    }
}
