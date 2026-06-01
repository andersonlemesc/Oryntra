<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('agent:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $specialistExists = Rule::exists('agent_specialists', 'id')->where('workspace_id', $this->workspaceId());

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('agents', 'name')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($this->route('agent')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(AgentStatus::class)],
            'mode' => ['sometimes', Rule::enum(AgentMode::class)],
            'response_mode' => ['sometimes', Rule::enum(AgentResponseMode::class)],
            'locale' => ['nullable', 'string', 'max:12'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'supervisor_prompt' => ['nullable', 'string'],
            'supervisor_llm_key_id' => [
                'nullable',
                Rule::exists('agent_llm_keys', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'supervisor_llm_model' => ['nullable', 'string', 'max:128'],
            'fallback_specialist_id' => ['nullable', $specialistExists],
            'debounce_config' => ['nullable', 'array'],
            'guard_config' => ['nullable', 'array'],
            'rag_config' => ['nullable', 'array'],
            'business_hours' => ['nullable', 'array'],
        ];
    }
}
