<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentSpecialistStatus;
use Illuminate\Validation\Rule;

class UpdateSpecialistRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('specialist:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'role_prompt' => ['sometimes', 'string'],
            'intent_keywords' => ['nullable', 'array'],
            'intent_keywords.*' => ['string', 'max:128'],
            'llm_key_id' => [
                'nullable',
                Rule::exists('agent_llm_keys', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'llm_model' => ['nullable', 'string', 'max:128'],
            'llm_temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'tools_allowlist' => ['nullable', 'array'],
            'tools_allowlist.*' => ['string', 'max:128'],
            'handoff_config' => ['nullable', 'array'],
            'contact_tools_config' => ['nullable', 'array'],
            'product_tools_config' => ['nullable', 'array'],
            'document_tools_config' => ['nullable', 'array'],
            'memory_config' => ['nullable', 'array'],
            'resolution_config' => ['nullable', 'array'],
            'google_calendar_config' => ['nullable', 'array'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'confidence_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'fallback_specialist_id' => [
                'nullable',
                Rule::exists('agent_specialists', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'status' => ['sometimes', Rule::enum(AgentSpecialistStatus::class)],
        ];
    }
}
