<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaygroundMessageStatus: string
{
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Streaming => 'Respondendo',
            self::Completed => 'Concluido',
            self::Failed => 'Falhou',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
