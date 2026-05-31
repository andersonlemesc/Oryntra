<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ExternalToolAuthType;
use Illuminate\Validation\Rule;

class UpdateMcpServerRequest extends ApiFormRequest
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
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            'config.base_url' => ['sometimes', 'string', 'max:2048'],
            'config.auth_type' => ['nullable', Rule::enum(ExternalToolAuthType::class)],
            'config.timeout_seconds' => ['nullable', 'integer', 'between:1,120'],
            'secret' => ['nullable', 'array'],
            'secret.token' => ['nullable', 'string'],
        ];
    }
}
