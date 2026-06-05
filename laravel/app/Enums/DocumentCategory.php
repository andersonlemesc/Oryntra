<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentCategory: string
{
    case Catalog = 'catalog';
    case Faq = 'faq';
    case Manual = 'manual';
    case Policy = 'policy';
    case General = 'general';
    case Knowledge = 'knowledge';

    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Catalogo',
            self::Faq => 'FAQ',
            self::Manual => 'Manual',
            self::Policy => 'Politica',
            self::General => 'Geral',
            self::Knowledge => 'Conhecimento IA',
        };
    }

    /**
     * Whether documents in this category may be sent to the customer.
     * Knowledge documents are AI-reference only and must never be delivered.
     */
    public function isSendable(): bool
    {
        return $this !== self::Knowledge;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function sendableOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $category): bool => $category->isSendable())
            ->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function sendableValues(): array
    {
        return array_values(array_map(
            fn (self $category): string => $category->value,
            array_filter(self::cases(), fn (self $category): bool => $category->isSendable()),
        ));
    }
}
