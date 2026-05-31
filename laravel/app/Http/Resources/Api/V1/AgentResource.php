<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agent
 */
class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'mode' => $this->mode->value,
            'response_mode' => $this->response_mode->value,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'supervisor_prompt' => $this->supervisor_prompt,
            'supervisor_llm_key_id' => $this->supervisor_llm_key_id,
            'supervisor_llm_model' => $this->supervisor_llm_model,
            'fallback_specialist_id' => $this->fallback_specialist_id,
            'debounce_config' => $this->debounce_config,
            'guard_config' => $this->guard_config,
            'rag_config' => $this->rag_config,
            'specialists_count' => $this->whenCounted('specialists'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
