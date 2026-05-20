<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns\Pages;

use App\Filament\Resources\AgentRuns\AgentRunResource;
use App\Filament\Resources\AgentRuns\Support\AgentRunHitlActions;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AgentRunHitlActions::approve(),
            AgentRunHitlActions::edit(),
            AgentRunHitlActions::reject(),
        ];
    }
}
