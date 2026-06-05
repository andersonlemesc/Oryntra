<?php

declare(strict_types=1);

namespace App\Enums;

enum ExternalToolParamLocation: string
{
    case Query = 'query';
    case Path = 'path';
    case Body = 'body';
    case Header = 'header';

    public function label(): string
    {
        return match ($this) {
            self::Query => 'Query string (?chave=valor)',
            self::Path => 'Caminho da URL ({chave})',
            self::Body => 'Corpo JSON',
            self::Header => 'Header HTTP',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $location): array => [$location->value => $location->label()])
            ->all();
    }
}
