<?php

declare(strict_types=1);

namespace App\Actions\ExternalTools;

use App\Models\ExternalTool;
use App\Services\AgentTools\ExternalToolSchemaBuilder;
use Illuminate\Validation\ValidationException;

/**
 * Builds the persisted ExternalTool attributes from API input. Mirrors the
 * Filament ExternalToolFormState but works from an explicit config + secret
 * payload instead of form fields. Secrets are accepted only on write and never
 * read back.
 */
class AssembleExternalTool
{
    public function __construct(private readonly ExternalToolSchemaBuilder $builder) {}

    /**
     * @param  array<string, mixed> $input validated request data
     * @return array<string, mixed> attributes ready for create()/update()
     *
     * @throws ValidationException
     */
    public function attributes(array $input, ?ExternalTool $record = null): array
    {
        $config = is_array($input['config'] ?? null) ? $input['config'] : [];

        // For http connectors, validate the param_schema the client provided.
        if (array_key_exists('param_schema', $config)) {
            $schema = is_array($config['param_schema']) ? $config['param_schema'] : ['properties' => []];

            try {
                $this->builder->validate($schema);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['config.param_schema' => $e->getMessage()]);
            }

            $config['param_schema'] = $schema;
        }

        $attributes = [
            'slug' => $input['slug'] ?? $record?->slug,
            'label' => $input['label'] ?? $record?->label,
            'description' => $input['description'] ?? $record->description ?? '',
            'enabled' => $input['enabled'] ?? $record->enabled ?? true,
            'config' => $config,
        ];

        // Credentials are write-only: only overwrite when a secret is supplied.
        $credentials = $this->resolveCredentials($input, (string) ($config['auth_type'] ?? 'none'), $record);
        if ($credentials !== false) {
            $attributes['credentials'] = $credentials;
        }

        // Drop only keys we couldn't resolve (null) so the model defaults apply;
        // keep false values like enabled=false.
        return array_filter($attributes, fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>            $input
     * @return array<string, mixed>|null|false false = leave existing untouched
     */
    private function resolveCredentials(array $input, string $authType, ?ExternalTool $record): array|null|false
    {
        $secret = is_array($input['secret'] ?? null) ? $input['secret'] : [];

        return match ($authType) {
            'api_key', 'bearer' => filled($secret['token'] ?? null)
                ? ['token' => (string) $secret['token']]
                : ($record === null ? null : false),
            'basic' => (filled($secret['username'] ?? null) || filled($secret['password'] ?? null))
                ? ['username' => (string) ($secret['username'] ?? ''), 'password' => (string) ($secret['password'] ?? '')]
                : ($record === null ? null : false),
            default => null,
        };
    }
}
