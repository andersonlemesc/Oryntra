<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('category:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('categories', 'slug')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($this->route('category')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
