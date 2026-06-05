<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use Illuminate\Validation\Rule;

class UpdateLlmKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('llmkey:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('agent_llm_keys', 'name')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($this->route('llmKey')),
            ],
            'provider' => ['sometimes', Rule::enum(AgentLlmProvider::class)],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'api_key' => ['sometimes', 'string', 'max:4096'],
            'status' => ['sometimes', Rule::enum(AgentLlmKeyStatus::class)],
        ];
    }
}
