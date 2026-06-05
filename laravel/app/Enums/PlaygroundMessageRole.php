<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaygroundMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Usuario',
            self::Assistant => 'Agente',
        };
    }
}
