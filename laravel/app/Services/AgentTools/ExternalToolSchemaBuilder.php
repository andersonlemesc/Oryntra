<?php

declare(strict_types=1);

namespace App\Services\AgentTools;

use App\Enums\ExternalToolParamLocation;
use InvalidArgumentException;

/**
 * Converts between the Filament repeater rows and the normalized
 * ``param_schema`` persisted in ``external_tools.config`` and validates it.
 *
 * Normalized shape:
 *   [
 *     'properties' => [
 *       'order_id' => [
 *         'type' => 'string',
 *         'description' => 'ID do pedido.',
 *         'location' => 'query',
 *         'required' => true,
 *       ],
 *     ],
 *   ]
 */
final class ExternalToolSchemaBuilder
{
    /** @var list<string> */
    public const TYPES = ['string', 'number', 'integer', 'boolean'];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  array<int, array<string, mixed>>                                                                             $rows
     * @return array{properties: array<string, array{type: string, description: string, location: string, required: bool}>}
     */
    public function fromRepeater(array $rows): array
    {
        $properties = [];

        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';

            if ($name === '') {
                continue;
            }

            $properties[$name] = [
                'type' => is_string($row['type'] ?? null) ? $row['type'] : 'string',
                'description' => is_string($row['description'] ?? null) ? $row['description'] : '',
                'location' => is_string($row['location'] ?? null) ? $row['location'] : ExternalToolParamLocation::Query->value,
                'required' => (bool) ($row['required'] ?? false),
            ];
        }

        return ['properties' => $properties];
    }

    /**
     * @param  array<string, mixed>                                                                                 $schema
     * @return array<int, array{name: string, type: string, description: string, location: string, required: bool}>
     */
    public function toRepeater(array $schema): array
    {
        $rows = [];

        foreach ($this->properties($schema) as $name => $definition) {
            $rows[] = [
                'name' => $name,
                'type' => is_string($definition['type'] ?? null) ? $definition['type'] : 'string',
                'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
                'location' => is_string($definition['location'] ?? null) ? $definition['location'] : ExternalToolParamLocation::Query->value,
                'required' => (bool) ($definition['required'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>                $schema
     * @return array<string, array<string, mixed>>
     */
    public function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        return is_array($properties) ? $properties : [];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $schema): void
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            throw new InvalidArgumentException('param_schema must contain a "properties" object.');
        }

        $pathParams = [];

        foreach ($properties as $name => $definition) {
            if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException("Parameter name '{$name}' must be snake_case.");
            }

            if (! is_array($definition)) {
                throw new InvalidArgumentException("Parameter '{$name}' must be an object.");
            }

            $type = $definition['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Parameter '{$name}' has an invalid type. Allowed: " . implode(', ', self::TYPES) . '.');
            }

            $location = $definition['location'] ?? null;
            if (! is_string($location) || ExternalToolParamLocation::tryFrom($location) === null) {
                throw new InvalidArgumentException("Parameter '{$name}' has an invalid location.");
            }

            if ($location === ExternalToolParamLocation::Path->value) {
                $pathParams[] = $name;
            }
        }

        foreach ($pathParams as $name) {
            if (count(array_keys($properties, $name, true)) > 1) {
                throw new InvalidArgumentException("Duplicate path parameter '{$name}'.");
            }
        }
    }

    /**
     * Parse a raw JSON schema string from the advanced editor.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function fromJson(string $json): array
    {
        $trimmed = trim($json);

        if ($trimmed === '') {
            return ['properties' => []];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('param_schema JSON is invalid.');
        }

        $this->validate($decoded);

        return $decoded;
    }
}
