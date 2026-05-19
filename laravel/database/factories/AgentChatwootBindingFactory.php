<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentChatwootBindingStatus;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentChatwootBinding>
 */
class AgentChatwootBindingFactory extends Factory
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
            'chatwoot_connection_id' => fn (array $attributes): int => ChatwootConnection::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])->id,
            'status' => AgentChatwootBindingStatus::Active,
            'inbox_ids' => null,
            'ignore_assigned_conversations' => false,
            'ignore_label_names' => [],
            'handoff_label_name' => null,
            'handoff_team_id' => null,
            'handoff_team_name' => null,
            'handoff_agent_id' => null,
            'handoff_agent_name' => null,
            'handoff_private_note_template' => null,
            'handoff_assign_strategy' => 'none',
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => AgentChatwootBindingStatus::Inactive]);
    }
}
