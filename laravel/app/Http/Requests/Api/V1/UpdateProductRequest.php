<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken()?->can('product:write') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($this->route('product')),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
            'agent_ids' => ['sometimes', 'array'],
            'agent_ids.*' => [Rule::exists('agents', 'id')->where('workspace_id', $this->workspaceId())],
        ];
    }
}
