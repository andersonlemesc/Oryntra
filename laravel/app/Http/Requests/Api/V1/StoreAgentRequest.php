<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends ApiFormRequest
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
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('agents', 'name')->where('workspace_id', $this->workspaceId()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::enum(AgentStatus::class)],
            'mode' => ['required', Rule::enum(AgentMode::class)],
            'response_mode' => ['nullable', Rule::enum(AgentResponseMode::class)],
            'locale' => ['nullable', 'string', 'max:12'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'supervisor_prompt' => ['nullable', 'string'],
            'supervisor_llm_key_id' => [
                'nullable',
                Rule::exists('agent_llm_keys', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'supervisor_llm_model' => ['nullable', 'string', 'max:128'],
            'debounce_config' => ['nullable', 'array'],
            'guard_config' => ['nullable', 'array'],
            'rag_config' => ['nullable', 'array'],
            'business_hours' => ['nullable', 'array'],
        ];
    }
}
