<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactMemoryType: string
{
    case Preference = 'preference';
    case Fact = 'fact';
    case Constraint = 'constraint';
    case History = 'history';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Preference => 'Preferencia',
            self::Fact => 'Fato',
            self::Constraint => 'Restricao',
            self::History => 'Historico',
            self::Custom => 'Personalizado',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Preference->value => self::Preference->label(),
            self::Fact->value => self::Fact->label(),
            self::Constraint->value => self::Constraint->label(),
            self::History->value => self::History->label(),
            self::Custom->value => self::Custom->label(),
        ];
    }
}
