<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Converts a pgvector column between its textual form `[1.2,3.4]` and a PHP
 * `array<int, float>`. Works identically on the sqlite text fallback used by the
 * test suite.
 *
 * @implements CastsAttributes<array<int, float>|null, array<int, float>|null>
 */
class Embedding implements CastsAttributes
{
    /**
     * @param  array<string, mixed>   $attributes
     * @return array<int, float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map(
            static fn (string $component): float => (float) $component,
            explode(',', $trimmed),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return (string) $value;
        }

        return '[' . implode(',', array_map(
            static fn (float|int|string $component): string => (string) (float) $component,
            $value,
        )) . ']';
    }
}
