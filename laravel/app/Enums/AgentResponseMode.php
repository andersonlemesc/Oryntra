<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentResponseMode: string
{
    case Automatic = 'automatic';
    case SuggestionOnly = 'suggestion_only';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatico',
            self::SuggestionOnly => 'Sugestao (copilot)',
        };
    }
}
