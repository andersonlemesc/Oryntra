<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns\Pages;

use App\Filament\Resources\AgentRuns\AgentRunResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentRuns extends ListRecords
{
    protected static string $resource = AgentRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
