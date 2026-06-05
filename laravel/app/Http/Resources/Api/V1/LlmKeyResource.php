<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AgentLlmKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentLlmKey
 */
class LlmKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'base_url' => $this->base_url,
            'status' => $this->status->value,
            // api_key is write-only and never serialized.
            'has_api_key' => filled($this->getRawOriginal('api_key')),
            'models_count' => $this->whenCounted('models'),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
