<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentRunStatus: string
{
    case Debouncing = 'debouncing';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case WaitingHuman = 'waiting_human';

    public function label(): string
    {
        return match ($this) {
            self::Debouncing => 'Agrupando',
            self::Queued => 'Na fila',
            self::Running => 'Executando',
            self::Completed => 'Concluido',
            self::Failed => 'Falhou',
            self::Ignored => 'Ignorado',
            self::WaitingHuman => 'Aguardando humano',
        };
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Debouncing, self::Queued, self::Running], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Ignored, self::WaitingHuman], true);
    }
}
