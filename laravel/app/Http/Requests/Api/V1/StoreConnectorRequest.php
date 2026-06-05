<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ExternalToolAuthType;
use Illuminate\Validation\Rule;

class StoreConnectorRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('tool:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'required', 'string', 'max:128', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('external_tools', 'slug')->where('workspace_id', $this->workspaceId()),
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['nullable', 'boolean'],
            'config' => ['required', 'array'],
            'config.http_method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'config.base_url' => ['required', 'string', 'max:2048'],
            'config.path' => ['nullable', 'string', 'max:2048'],
            'config.auth_type' => ['nullable', Rule::enum(ExternalToolAuthType::class)],
            'config.param_schema' => ['nullable', 'array'],
            'config.response_extraction' => ['nullable', 'array'],
            'config.timeout_seconds' => ['nullable', 'integer', 'between:1,120'],
            'secret' => ['nullable', 'array'],
            'secret.token' => ['nullable', 'string'],
            'secret.username' => ['nullable', 'string'],
            'secret.password' => ['nullable', 'string'],
        ];
    }
}
