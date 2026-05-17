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
}
