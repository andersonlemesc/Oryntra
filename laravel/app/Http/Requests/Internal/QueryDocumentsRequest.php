<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QueryDocumentsRequest extends FormRequest
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
            'query' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
