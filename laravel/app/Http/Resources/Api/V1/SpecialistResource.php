<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AgentSpecialist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentSpecialist
 */
class SpecialistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'description' => $this->description,
            'role_prompt' => $this->role_prompt,
            'intent_keywords' => $this->intent_keywords,
            'llm_key_id' => $this->llm_key_id,
            'llm_model' => $this->llm_model,
            'llm_temperature' => $this->llm_temperature,
            'tools_allowlist' => $this->tools_allowlist,
            'handoff_config' => $this->handoff_config,
            'contact_tools_config' => $this->contact_tools_config,
            'product_tools_config' => $this->product_tools_config,
            'document_tools_config' => $this->document_tools_config,
            'memory_config' => $this->memory_config,
            'resolution_config' => $this->resolution_config,
            'google_calendar_config' => $this->google_calendar_config,
            'priority' => $this->priority,
            'confidence_threshold' => $this->confidence_threshold,
            'fallback_specialist_id' => $this->fallback_specialist_id,
            'status' => $this->status?->value,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
