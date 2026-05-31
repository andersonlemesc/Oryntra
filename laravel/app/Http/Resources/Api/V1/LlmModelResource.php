<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AgentLlmModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentLlmModel
 */
class LlmModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'model_id' => $this->model_id,
            'label' => $this->label,
            'synced_at' => $this->synced_at?->toISOString(),
        ];
    }
}
