<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaygroundMessageStatus: string
{
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Completed = 'completed';
    case WaitingHuman = 'waiting_human';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Streaming => 'Respondendo',
            self::Completed => 'Concluido',
            self::WaitingHuman => 'Aguardando humano',
            self::Failed => 'Falhou',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::WaitingHuman, self::Failed], true);
    }
}
