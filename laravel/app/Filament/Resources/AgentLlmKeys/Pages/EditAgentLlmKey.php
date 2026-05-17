<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Pages;

use App\Filament\Resources\AgentLlmKeys\AgentLlmKeyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAgentLlmKey extends EditRecord
{
    protected static string $resource = AgentLlmKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
