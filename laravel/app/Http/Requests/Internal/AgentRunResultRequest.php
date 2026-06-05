<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the runtime result posted back by the Python agent service through
 * the internal callback endpoint. Mirrors the contract previously enforced
 * inline by AgentRuntimeClient::validatedResponse() for the synchronous path.
 */
class AgentRunResultRequest extends FormRequest
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
            'status' => ['required', Rule::in(['completed', 'failed'])],
            'response' => ['nullable', 'array'],
            'specialist_id' => ['nullable', 'integer'],
            'trace' => ['nullable', 'array'],
            'trace.*' => ['array'],
            'usage' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * Normalize to the runtime-result shape consumed by FinalizeAgentRunJob.
     *
     * @return array<string, mixed>
     */
    public function runtimeResult(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'status' => $data['status'],
            'response' => is_array($data['response'] ?? null) ? $data['response'] : [],
            'specialist_id' => $data['specialist_id'] ?? null,
            'trace' => is_array($data['trace'] ?? null) ? array_values($data['trace']) : [],
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
            'error' => $data['error'] ?? null,
        ];
    }
}
