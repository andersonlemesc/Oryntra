<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentLlmKeyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Inactive => 'Inativa',
        };
    }
}
