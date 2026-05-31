<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchKnowledgeBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'min:1'],
            'agent_id' => ['required', 'integer', 'min:1'],
            'agent_run_id' => ['required', 'integer', 'min:1'],
            'specialist_id' => ['nullable', 'integer', 'min:1'],
            'query' => ['required', 'string', 'max:1000'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }
}
