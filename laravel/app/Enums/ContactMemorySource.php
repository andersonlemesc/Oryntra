<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactMemorySource: string
{
    case AgentExtracted = 'agent_extracted';
    case Manual = 'manual';
    case ChatwootAttribute = 'chatwoot_attribute';
    case Tool = 'tool';

    public function label(): string
    {
        return match ($this) {
            self::AgentExtracted => 'IA extraiu',
            self::Manual => 'Manual',
            self::ChatwootAttribute => 'Chatwoot',
            self::Tool => 'Tool',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::AgentExtracted->value => self::AgentExtracted->label(),
            self::Manual->value => self::Manual->label(),
            self::ChatwootAttribute->value => self::ChatwootAttribute->label(),
            self::Tool->value => self::Tool->label(),
        ];
    }
}
