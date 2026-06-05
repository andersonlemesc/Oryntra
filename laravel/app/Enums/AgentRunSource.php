<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentRunSource: string
{
    case Chatwoot = 'chatwoot';
    case Playground = 'playground';

    public function label(): string
    {
        return match ($this) {
            self::Chatwoot => 'Chatwoot',
            self::Playground => 'Playground',
        };
    }
}
