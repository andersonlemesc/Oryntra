<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Pages;

use App\Filament\Resources\AgentLlmKeys\AgentLlmKeyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentLlmKeys extends ListRecords
{
    protected static string $resource = AgentLlmKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
