<?php

declare(strict_types=1);

namespace App\Services\MCP;

/**
 * Translates an MCP tool `inputSchema` (JSON Schema) into the internal
 * `param_schema` format used by ExternalToolSchemaBuilder / Python tool builder.
 *
 * MCP args always go in the request body, so every parameter gets `location = body`.
 * Unsupported JSON Schema types fall back to `string`.
 */
final class McpSchemaTranslator
{
    private const SUPPORTED_TYPES = ['string', 'integer', 'number', 'boolean'];

    /**
     * @param  array<string, mixed>                                   $inputSchema MCP tool inputSchema
     * @return array{properties: array<string, array<string, mixed>>}
     */
    public function translate(array $inputSchema): array
    {
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];

        $result = [];

        foreach ($properties as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'string');
            if (! in_array($type, self::SUPPORTED_TYPES, true)) {
                $type = 'string';
            }

            $result[$name] = [
                'type' => $type,
                'description' => (string) ($definition['description'] ?? ''),
                'location' => 'body',
                'required' => in_array($name, $required, true),
            ];
        }

        return ['properties' => $result];
    }
}
