<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Pages;

use App\Actions\Agents\CreateAgentWithDefaults;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Support\LlmModelField;
use App\Models\Agent;
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
        if ($this->record instanceof Agent) {
            CreateAgentWithDefaults::ensureSingleModeSpecialist($this->record);
        }
    }
}
