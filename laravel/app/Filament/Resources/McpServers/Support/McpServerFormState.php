<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Support;

use App\Models\ExternalTool;

/**
 * Bridges transient Filament form fields (plain-text secret) with the
 * persisted ``external_tools`` row (``config`` + encrypted ``credentials``).
 */
final class McpServerFormState
{
    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function assemble(array $data, ?ExternalTool $record = null): array
    {
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $authType = (string) ($config['auth_type'] ?? 'none');

        $existing = is_array($record?->credentials) ? $record->credentials : null;

        $credentials = match ($authType) {
            'api_key', 'bearer' => filled($data['secret_token'] ?? null)
                ? ['token' => (string) $data['secret_token']]
                : $existing,
            default => null,
        };

        $data['config'] = $config;
        $data['credentials'] = $credentials;

        unset($data['secret_token']);

        return $data;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function hydrate(array $data): array
    {
        $data['secret_token'] = null;

        return $data;
    }
}
