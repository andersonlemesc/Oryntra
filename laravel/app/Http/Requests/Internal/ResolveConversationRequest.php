<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResolveConversationRequest extends FormRequest
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
            'thread_id' => ['required', 'string', 'max:500'],
            'conversation_id' => ['required', 'integer', 'min:1'],
            'specialist_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'resolution_summary' => ['required', 'string', 'max:4000'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
            'label_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
