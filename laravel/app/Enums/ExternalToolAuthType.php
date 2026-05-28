<?php

declare(strict_types=1);

namespace App\Enums;

enum ExternalToolAuthType: string
{
    case None = 'none';
    case ApiKey = 'api_key';
    case Bearer = 'bearer';
    case Basic = 'basic';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem autenticacao',
            self::ApiKey => 'API key em header',
            self::Bearer => 'Bearer token',
            self::Basic => 'Basic auth',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
