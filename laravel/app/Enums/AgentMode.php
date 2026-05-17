<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentMode: string
{
    case Single = 'single';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Unico',
            self::Supervisor => 'Supervisor',
        };
    }
}
