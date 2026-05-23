<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\ContactMemoryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactMemoryRequest extends FormRequest
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
            'contact_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(array_map(fn (ContactMemoryType $case): string => $case->value, ContactMemoryType::cases()))],
            'content' => ['required', 'string', 'max:2000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
