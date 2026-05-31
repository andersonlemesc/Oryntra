<?php

declare(strict_types=1);

namespace App\Actions\Agents;

use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Models\Agent;
use App\Models\AgentSpecialist;
use Illuminate\Support\Facades\DB;

class CreateAgentWithDefaults
{
    /**
     * Create an agent and, when it runs in Single mode, auto-create the one
     * specialist that holds the real config. Mirrors the Filament
     * CreateAgent::afterCreate() behaviour so both surfaces stay consistent.
     *
     * @param array<string, mixed> $attributes already validated, includes workspace_id
     */
    public function execute(array $attributes): Agent
    {
        return DB::transaction(function () use ($attributes): Agent {
            $agent = Agent::query()->create($attributes);

            // Reload so database defaults (status, response_mode, locale,
            // timezone) are reflected on the returned model.
            $agent->refresh();

            self::ensureSingleModeSpecialist($agent);

            return $agent;
        });
    }

    /**
     * Auto-create the lone specialist for a Single-mode agent if missing.
     * Shared between this action and the Filament CreateAgent page.
     */
    public static function ensureSingleModeSpecialist(Agent $agent): void
    {
        if ($agent->mode !== AgentMode::Single || $agent->specialists()->exists()) {
            return;
        }

        AgentSpecialist::query()->create([
            'workspace_id' => $agent->workspace_id,
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'status' => AgentSpecialistStatus::Active,
            'role_prompt' => 'Você é um assistente de atendimento. Responda de forma clara e objetiva.',
            'llm_key_id' => null,
            'llm_model' => null,
            'llm_temperature' => 0.2,
            'tools_allowlist' => [],
            'intent_keywords' => [],
            'priority' => 100,
            'confidence_threshold' => 0.0,
        ]);
    }
}
