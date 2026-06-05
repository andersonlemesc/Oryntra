<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ExternalTool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExternalTool
 */
class ExternalToolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'slug' => $this->slug,
            'label' => $this->label,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'config' => $this->config,
            // credentials are write-only and never serialized.
            'has_credentials' => filled($this->getRawOriginal('credentials')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
