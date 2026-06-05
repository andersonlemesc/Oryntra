<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestHumanHandoffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'min:1'],
            'agent_id' => ['required', 'integer', 'min:1'],
            'agent_run_id' => ['required', 'integer', 'min:1'],
            'thread_id' => ['required', 'string', 'max:500'],
            'conversation_id' => ['required', 'integer', 'min:1'],
            'specialist_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'suggested_team' => ['nullable', 'string', 'max:120'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
            'conversation_summary' => ['nullable', 'string', 'max:4000'],
            'key_fact' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
