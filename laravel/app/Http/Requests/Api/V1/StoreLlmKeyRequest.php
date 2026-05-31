<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use Illuminate\Validation\Rule;

class StoreLlmKeyRequest extends ApiFormRequest
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
                'required', 'string', 'max:255',
                Rule::unique('agent_llm_keys', 'name')->where('workspace_id', $this->workspaceId()),
            ],
            'provider' => ['required', Rule::enum(AgentLlmProvider::class)],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'api_key' => ['required', 'string', 'max:4096'],
            'status' => ['nullable', Rule::enum(AgentLlmKeyStatus::class)],
        ];
    }
}
