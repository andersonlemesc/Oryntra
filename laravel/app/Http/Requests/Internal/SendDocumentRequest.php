<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendDocumentRequest extends FormRequest
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
            'agent_run_id' => ['required', 'integer', 'min:1'],
            'document_ids' => ['required', 'array', 'min:1', 'max:20'],
            'document_ids.*' => ['integer', 'min:1'],
            'document_type' => ['required', 'string', 'in:product,standalone'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'conversation_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
