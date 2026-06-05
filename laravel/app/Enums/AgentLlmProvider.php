<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentLlmProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Gemini',
            self::Local => 'Local',
        };
    }

    /**
     * Default API base URL for the provider. `null` when the provider has no
     * canonical endpoint (Local must always supply its own base URL).
     */
    public function defaultBaseUrl(): ?string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com',
            self::Gemini => 'https://generativelanguage.googleapis.com',
            self::Local => null,
        };
    }
}
