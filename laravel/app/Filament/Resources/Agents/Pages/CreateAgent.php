<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Pages;

use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Support\LlmModelField;
use App\Models\Agent;
use App\Models\AgentSpecialist;
use Filament\Resources\Pages\CreateRecord;

class CreateAgent extends CreateRecord
{
    protected static string $resource = AgentResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LlmModelField::resolve($data, 'supervisor_llm_model', 'audio_llm_model', 'vision_llm_model');
    }

    /**
     * Single-mode agents always have exactly one specialist holding the real
     * config (LLM, prompt, tools). Auto-create it so the user only edits one
     * thing instead of wondering why a "single" agent needs a specialist.
     */
    protected function afterCreate(): void
    {
        $agent = $this->record;

        if (! $agent instanceof Agent || $agent->mode !== AgentMode::Single) {
            return;
        }

        if ($agent->specialists()->exists()) {
            return;
        }

        AgentSpecialist::create([
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
