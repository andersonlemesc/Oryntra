<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreCategoryRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255',
                Rule::unique('categories', 'slug')->where('workspace_id', $this->workspaceId()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
