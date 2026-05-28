<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Support;

use App\Models\ExternalTool;
use App\Services\AgentTools\ExternalToolSchemaBuilder;

/**
 * Bridges the Filament form (typed repeater / advanced JSON / transient secret
 * fields) and the persisted ``external_tools`` row (``config.param_schema`` +
 * encrypted ``credentials``).
 */
final class ExternalToolFormState
{
    /**
     * Build the persisted shape from raw form data.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function assemble(array $data, ?ExternalTool $record = null): array
    {
        $builder = app(ExternalToolSchemaBuilder::class);

        $advanced = (bool) ($data['advanced_schema'] ?? false);
        $schema = $advanced
            ? $builder->fromJson((string) ($data['param_schema_json'] ?? ''))
            : $builder->fromRepeater(is_array($data['param_rows'] ?? null) ? $data['param_rows'] : []);
        $builder->validate($schema);

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config['param_schema'] = $schema;
        $data['config'] = $config;

        $data['credentials'] = self::resolveCredentials($data, (string) ($config['auth_type'] ?? 'none'), $record);

        unset(
            $data['param_rows'],
            $data['param_schema_json'],
            $data['advanced_schema'],
            $data['secret_token'],
            $data['secret_username'],
            $data['secret_password'],
        );

        return $data;
    }

    /**
     * Expand the persisted shape into the transient form fields for editing.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function hydrate(array $data): array
    {
        $builder = app(ExternalToolSchemaBuilder::class);
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $schema = is_array($config['param_schema'] ?? null) ? $config['param_schema'] : ['properties' => []];

        $data['param_rows'] = $builder->toRepeater($schema);
        $data['param_schema_json'] = (string) json_encode(
            $schema,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $data['advanced_schema'] = false;

        return $data;
    }

    /**
     * @param  array<string, mixed>      $data
     * @return array<string, mixed>|null
     */
    private static function resolveCredentials(array $data, string $authType, ?ExternalTool $record): ?array
    {
        $existing = is_array($record?->credentials) ? $record->credentials : null;

        return match ($authType) {
            'api_key', 'bearer' => filled($data['secret_token'] ?? null)
                ? ['token' => (string) $data['secret_token']]
                : $existing,
            'basic' => (filled($data['secret_username'] ?? null) || filled($data['secret_password'] ?? null))
                ? ['username' => (string) ($data['secret_username'] ?? ''), 'password' => (string) ($data['secret_password'] ?? '')]
                : $existing,
            default => null,
        };
    }
}
