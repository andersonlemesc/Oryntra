<?php

declare(strict_types=1);

namespace App\Enums;

enum ExternalToolKind: string
{
    case HttpConnector = 'http_connector';

    public function label(): string
    {
        return match ($this) {
            self::HttpConnector => 'Connector HTTP',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
