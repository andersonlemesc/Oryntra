<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentResponseMode: string
{
    case Automatic = 'automatic';
    case SuggestionOnly = 'suggestion_only';
    case HumanApproval = 'human_approval';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatico',
            self::SuggestionOnly => 'Sugestao apenas',
            self::HumanApproval => 'Aprovacao humana',
        };
    }
}
