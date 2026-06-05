<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentRunResumeRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['approved', 'rejected', 'edited'])],
            'response_content' => ['nullable', 'string', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
