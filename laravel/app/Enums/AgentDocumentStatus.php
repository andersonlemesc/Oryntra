<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentDocumentStatus: string
{
    case Pending = 'pending';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Indexing => 'Indexando',
            self::Indexed => 'Indexado',
            self::Failed => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Indexing => 'warning',
            self::Indexed => 'success',
            self::Failed => 'danger',
        };
    }
}
